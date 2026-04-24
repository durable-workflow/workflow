# Workflow V2 Scheduler Correctness and Cache Independence Contract

This document freezes the v2 contract for what makes scheduler and
dispatch behavior correct, which layers are allowed to affect
responsiveness without affecting correctness, and how operators see
the difference. It is the reference cited by product docs, CLI
reasoning, Waterline diagnostics, server deployment guidance, cloud
capacity planning, and test coverage so the whole fleet speaks one
language about durable dispatch state, wake acceleration, degraded
propagation, and detection of misconfiguration.

The guarantees below apply to the `durable-workflow/workflow` package
at v2, to the standalone `durable-workflow/server` that embeds it, and
to every host that embeds the package directly or talks to the server
over HTTP. A change to any named guarantee is a protocol-level change
and must be reviewed as such, even if the class that implements it is
`@internal`.

This contract builds on the semantics frozen in
`docs/architecture/execution-guarantees.md` (Phase 1), the routing
guarantees frozen in `docs/architecture/worker-compatibility.md`
(Phase 2), and the matching and dispatch guarantees frozen in
`docs/architecture/task-matching.md` (Phase 3). Duplicate execution,
retries, redelivery, compatibility, and the `snapshot()`/`changed()`
wake primitives keep the language they have there; this document
adds the language for what those primitives are allowed to be
responsible for and what remains the job of durable dispatch state.
The Phase 4 control-plane and execution-plane role split
preserves every guarantee here unchanged; the split is a topology
change, not a correctness change.

## Scope

The contract covers:

- **durable dispatch state** — the canonical set of durable rows whose
  contents determine whether a task can be discovered, claimed,
  redelivered, and retired. This is the correctness substrate.
- **acceleration layer** — the set of non-durable mechanisms
  (wake notifications, shared-cache version stamps, notifier
  pub/sub, compatibility heartbeat caches) that reduce scheduler
  latency without changing which tasks are eligible or who owns a
  claim.
- **degraded-mode behavior** — the behavior guaranteed when the
  acceleration layer is delayed, partitioned, or unavailable.
- **health semantics** — how operators distinguish a degraded
  acceleration path from a broken correctness substrate, and which
  metrics and log lines surface each.
- **backend classification** — which backends are permitted as the
  correctness substrate, which are permitted only for acceleration,
  and how boot-time validation enforces that split.
- **scheduler fire evaluation** — how scheduled workflow starts
  remain correct in the absence of wake propagation.
- **lease expiry and redelivery** — how redelivery stays correct
  without any dependency on cache or notifier state.
- **migration path** — how deployments built on shared-cache-backed
  wake notification move to a stronger acceleration primitive
  without a correctness regression.

It does not cover:

- the rollout safety enforcement work described by Phase 6.
  Phase 6 owns scheduler leader election and coordinated rollout
  across role replicas; this contract names what each node is
  allowed to observe but does not arbitrate which replica is
  allowed to fire a given schedule.
- the dedicated matching-role process image shipped by future
  tooling. The Phase 3 contract already permits that shape; this
  contract says the matching role keeps the same
  correctness/acceleration split regardless of deployment shape.
- replacement of the HTTP worker protocol or the SQL persistence
  model. Cache independence is a scheduler correctness change, not
  an engine rewrite.
- host-level infrastructure choices such as which notifier backend
  (Redis pub/sub, NATS, PostgreSQL `LISTEN/NOTIFY`, etc.) to deploy
  as the acceleration channel. Those are deployment concerns that
  consume this contract; they do not define it.

## Terminology

- **Durable dispatch state** — the set of rows in
  `workflow_tasks`, `workflow_runs`, `workflow_instances`,
  `activity_executions`, `activity_attempts`, and
  `workflow_schedules` whose contents determine which tasks are
  ready, which are leased, and which are due. Ownership and
  mutation authority are frozen by the Phase 4 role split; this
  contract names the rows as the single correctness substrate.
- **Correctness substrate** — the durable store whose consistency
  is necessary and sufficient for the scheduler to be correct.
  `workflow_tasks` plus the activity and schedule rows above are
  the correctness substrate; no other layer is.
- **Acceleration layer** — a non-durable signalling or caching
  mechanism that speeds up scheduler response without affecting
  which rows are eligible. Shared cache, notifier pub/sub, and
  compatibility heartbeat caches are acceleration layers.
- **Wake notification** — a non-durable signal published on task
  creation, task update, history event append, or projection
  change that a poller can observe to re-probe the correctness
  substrate sooner than its next scheduled poll. Frozen by
  Phase 3 under the `snapshot()` / `changed()` contract.
- **Wake propagation** — delivery of a wake notification from
  the publisher to every poller subscribed to the relevant
  channel. Propagation MAY be delayed, partitioned, partial, or
  fail open; the correctness substrate does not care.
- **Degraded-mode** — the observable state in which the
  acceleration layer is unavailable, misconfigured, or losing
  signals. Degraded-mode is a performance and latency degradation,
  not a correctness degradation.
- **Correctness boundary** — the smallest surface whose
  consistency the system requires. In v2, the correctness boundary
  is the workflow database; it is not the shared cache, not the
  notifier backend, and not any in-memory poller state.
- **Bounded discovery latency** — the guaranteed upper bound on
  how long a ready task can remain undiscovered in the absence of
  wake propagation. This bound is set by the configured poll
  interval, the `task_repair` redispatch cadence, and the
  `long_poll` timeout ceiling.

## Durable dispatch state is the correctness substrate

The following durable rows are the single source of truth for
scheduler correctness. A poller that reads them correctly is
correct. A poller that reads the acceleration layer correctly but
misses the durable state is not correct.

- `workflow_tasks` — `status`, `available_at`, `lease_owner`,
  `lease_expires_at`, `dispatch_attempted_at`, `repair_available_at`,
  `compatibility`, `connection`, `queue`, and `namespace` fields.
  Eligibility for claim is a pure function of these columns.
- `workflow_runs` — `status` and the closed-run timestamps that
  cause a ready task to be skipped.
- `activity_executions` and `activity_attempts` — the existence
  and `status` of the current attempt govern whether an
  activity-task row is claimable.
- `workflow_schedules` — `status`, `next_fire_at`, and
  `buffered_actions` determine whether a schedule is due. Schedule
  fire evaluation reads this row directly; it does not read any
  cache value to decide whether to fire.

Reads of these rows MUST NOT be gated by acceleration-layer state.
A poller whose wake channel returns "no change" MUST still perform
a full poll at its configured interval. A scheduler whose
notifier backend is unreachable MUST still evaluate due schedules
on its configured cadence.

Writes to these rows MUST NOT be skipped or deferred because an
acceleration signal was (or was not) published. Acceleration
signals are published after durable commit via `DB::afterCommit()`
per the Phase 3 contract; they are effect, not cause.

## The acceleration layer is optional

The acceleration layer exists to shorten the time between a task
becoming eligible and a compatible worker claiming it. It has
three named responsibilities and no others:

- **Wake signalling** via `Workflow\V2\Contracts\LongPollWakeStore`
  and `Workflow\V2\Support\CacheLongPollWakeStore`, as defined by
  the Phase 3 `snapshot()` / `changed()` contract.
- **Compatibility heartbeat caching** via
  `Workflow\V2\Support\WorkerCompatibilityFleet`, which caches
  the set of live workers that advertise a given compatibility
  marker.
- **Notifier fan-out**, when a deployment configures a richer
  backend (for example a Redis pub/sub channel or a database
  `LISTEN/NOTIFY` trigger) in place of or alongside the default
  cache-backed wake store. Any such backend MUST implement the
  Phase 3 `LongPollWakeStore` interface and the same
  `snapshot()` / `changed()` semantics.

Wake notification is a performance optimisation, not the
correctness boundary. A deployment that disables the wake layer,
or whose wake layer silently drops every signal, remains correct;
it simply experiences higher discovery latency, bounded by its
configured poll interval.

## Degraded-mode behavior

The acceleration layer MAY be delayed, partitioned, partially
missing, or entirely unavailable. Each condition has an explicit
defined behavior. The following are the guaranteed outcomes.

- **Wake backend unreachable** — `signal()` calls surface as
  exceptions or log lines at the publisher; pollers continue to
  discover work on the next configured poll. Discovery latency
  rises to the bounded discovery latency upper bound. Task claim,
  lease expiry, redelivery, and schedule fire evaluation are
  unchanged.
- **Wake backend partitioned** — different nodes see different
  version snapshots. A node that missed a signal re-polls on its
  configured interval and still finds every eligible task.
  Duplicate claims are prevented by the per-row lock frozen in
  the Phase 3 claim contract, not by cache coherence.
- **Wake backend lost some signals** — a subset of published
  signals never reaches subscribers. Pollers that missed the
  signal re-poll on their configured interval. No work is lost.
- **Compatibility heartbeat cache unavailable** — compatibility
  filtering degrades to a direct
  `worker_compatibility_heartbeats` read. Claim decisions stay
  consistent with the Phase 2 routing contract.
- **Cache backend permanently unavailable** — the system remains
  correct. Wake signals are not propagated; pollers run at their
  configured interval; durable dispatch state continues to
  govern every decision. Operators are expected to see the
  degraded state through the health surfaces described below
  and to repair the backend; the system does not silently turn
  the outage into a correctness failure.

A node MUST NOT refuse to make progress because the acceleration
layer is unavailable. A node MUST NOT fabricate task assignments,
schedule fires, or lease expiries in response to acceleration-layer
signals that contradict the durable state.

## Bounded discovery latency

Discovery latency is the time between a task becoming eligible
and a compatible poller first observing it. The bounded discovery
latency upper bound is set by durable dispatch state alone.

- The default long-poll timeout is 30 seconds, with a 60-second
  maximum as declared by `WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT`
  and `WorkerProtocolVersion::MAX_LONG_POLL_TIMEOUT`. After that
  interval expires, the poller returns and the next poll scans
  the correctness substrate directly.
- The `workflows.v2.task_repair.redispatch_after_seconds` cadence
  (default 3 seconds) bounds how long a task stuck between
  creation and dispatch remains unclaimed. Redispatch is driven
  by `workflow_tasks` rows alone.
- The `workflows.v2.task_repair.loop_throttle_seconds` cadence
  (default 5 seconds) bounds how long repair scanning pauses
  before the next scan. Repair scanning reads only durable rows.
- Schedule fire evaluation is driven by
  `WorkflowSchedule::tick()`, whose cadence is owned by the host
  (for example a `* * * * *` Laravel schedule). The bound on
  schedule fire latency is therefore the tick cadence plus the
  transaction time to evaluate due rows.
- Lease expiry redelivery is governed by `lease_expires_at` on
  `workflow_tasks` (5 minutes for activity tasks per
  `Workflow\V2\Support\ActivityLease::DURATION_MINUTES`).
  Redelivery starts at most one repair loop interval after the
  lease expires.

These numbers are contract guarantees: they hold whether or not
the acceleration layer is propagating signals.

## Scheduler fire evaluation

Scheduled workflow starts MUST remain correct without
acceleration-layer cooperation.

- `Workflow\V2\Support\ScheduleManager::tick()` selects due
  schedules from `workflow_schedules` using `status = 'active'`
  and `next_fire_at <= NOW()`. It does not read any cache value
  or notifier state to decide which schedules are due.
- Buffered schedule actions are drained by the same tick against
  `buffered_actions` on the schedule row; no cache check is
  performed.
- Schedule fires route through the control-plane start contract
  frozen by the Phase 4 role split. The scheduler does not
  bypass duplicate-start policies, compatibility pinning, or
  namespace checks.
- When the scheduler role is deployed as multiple replicas, the
  per-schedule row lock taken by `ScheduleManager::triggerDetailed()`
  is the correctness seam, not a cache-held leader key. Phase 6
  will harden leader coordination with explicit health;
  this contract requires that the scheduler-role surface NOT
  depend on cache coherence for deduplicated firing.

If the acceleration layer is unavailable, scheduled workflows
continue to fire at their next tick cadence. If the acceleration
layer is propagating stale signals, the scheduler still reads
`next_fire_at` directly and is therefore not misled.

## Lease expiry and redelivery

Lease expiry and redelivery are DB-only by contract.

- `Workflow\V2\Support\TaskRepairPolicy::leaseExpired()` decides
  expiry from `status`, `lease_expires_at`, and the current time.
  It reads no cache value.
- `Workflow\V2\Support\TaskRepair` drives the redelivery loop
  against the durable `workflow_tasks` rows.
- The repair loop surfaces expired leases through
  `OperatorMetrics::snapshot()` under
  `tasks.repair_needed_runs`, `backlog.claim_failed_runs`, and
  `repair.missing_task_candidates`, independent of any
  acceleration-layer metric.

A deployment whose cache backend is unavailable will still repair
expired leases, still redeliver stuck tasks, and still expose
redelivery state through the durable health surface.

## Backend classification

Workflow v2 classifies backends by the responsibility they can
legally carry.

- **Correctness substrate backends** — the supported workflow
  database drivers: `mysql`, `pgsql`, `sqlite`, and `sqlsrv`.
  `Workflow\V2\Support\BackendCapabilities` validates the
  database driver, transaction support, after-commit callback
  support, and row-lock support on boot. A deployment whose
  database driver is unsupported fails the `backend_capabilities`
  health check and cannot run v2 correctly.
- **Acceleration-only backends** — Laravel cache stores
  (`redis`, `database`, `memcached`) validated for multi-node
  coordination by
  `Workflow\V2\Support\LongPollCacheValidator`. These backends
  are permitted to carry the wake notification layer only. They
  MUST NOT be treated as the correctness substrate.
- **Unsupported for acceleration** — `file` and `array` cache
  stores. These stores cannot coordinate wake signals across
  nodes and are flagged by `LongPollCacheValidator` when the
  `multi_node` flag is set. A single-node deployment may use
  them, but wake notification becomes a no-op across nodes if
  multiple are ever added.

Boot-time validation is gated by `DW_V2_VALIDATE_CACHE_BACKEND`
(default true) and controlled by `DW_V2_CACHE_VALIDATION_MODE`
(`fail`, `warn`, or `silent`; default `warn`). A deployment that
sets the mode to `silent` accepts the risk of silently degraded
wake propagation; the correctness substrate is unaffected either
way.

## Detection of misconfiguration and degraded acceleration

Misconfiguration and degraded acceleration are surfaced through
explicit signals, not inferred from behaviour.

- **Boot-time validation** — `LongPollCacheValidator::checkMultiNodeSafety()`
  reports the detected cache backend, whether it is capable of
  multi-node coordination, and the remediation message. This is
  visible through the `backend_capabilities` health check so the
  operator sees the misconfiguration before running production
  traffic.
- **Backend health** — `Workflow\V2\Support\HealthCheck::snapshot()`
  includes a `backend_capabilities` check whose status is `error`
  when any backend issue of severity `error` is present.
  Acceleration-layer issues MAY escalate to `warning`; they MUST
  NOT escalate to `error` on the correctness path, because the
  correctness substrate is the database, not the cache.
- **Dispatch and repair metrics** — `OperatorMetrics::snapshot()`
  surfaces `tasks.ready`, `tasks.leased`, `tasks.claim_failed`,
  and `backlog.repair_needed_runs` straight from durable rows.
  Operators watching these metrics see rising ready depth and
  falling claim rate when acceleration is degraded; the metrics
  remain accurate even when the acceleration layer is lying.
- **Queue-partition visibility** —
  `Workflow\V2\Support\OperatorQueueVisibility::forNamespace()`
  and `::forQueue()` report matching-role health per partition,
  derived from durable rows. They keep working when the cache is
  down.

Misconfiguration MUST NOT be turned into a silent correctness
failure. A deployment that removes its cache backend sees rising
discovery latency and a `backend_capabilities` warning; it does
not silently lose work.

## Config surface

The acceleration-layer and scheduler-independence behavior is
controlled by the following keys. Each key has a `DW_*` env name
and a `workflows.v2.*` config path.

- `DW_V2_MULTI_NODE` / `workflows.v2.long_poll.multi_node` —
  whether the deployment requires the acceleration layer to
  coordinate across nodes. When true, boot validation enforces a
  shared cache backend.
- `DW_V2_VALIDATE_CACHE_BACKEND` /
  `workflows.v2.long_poll.validate_cache_backend` — boot-time
  validation on/off.
- `DW_V2_CACHE_VALIDATION_MODE` /
  `workflows.v2.long_poll.validation_mode` — how validation
  failures are surfaced (`fail`, `warn`, `silent`).
- `DW_V2_TASK_DISPATCH_MODE` /
  `workflows.v2.task_dispatch_mode` — frozen by Phase 3. Named
  here because dispatch mode determines how tasks reach workers
  when the acceleration layer is absent.
- `DW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS`,
  `DW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS`,
  `DW_V2_TASK_REPAIR_SCAN_LIMIT`, and
  `DW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS` —
  bound the durable-state redelivery loop.

None of these keys change the correctness substrate. They tune
how fast degraded acceleration is detected and how fast durable
redelivery catches up.

## Migration path

Deployments that grew up on cache-coordinated wake notification
can adopt this contract without a cutover.

1. **Audit the acceleration surface.** Confirm no application
   code (including host-level middleware or operator tooling)
   reads a cache key and treats it as a correctness signal. Any
   such caller is a migration target; it must switch to reading
   the correctness substrate directly.
2. **Enable boot-time validation.** Keep
   `DW_V2_VALIDATE_CACHE_BACKEND` enabled and set
   `DW_V2_CACHE_VALIDATION_MODE` to `warn` during the transition.
   Confirm the `backend_capabilities` check reports the expected
   cache backend class.
3. **Verify degraded-mode behavior.** Stop the cache backend in a
   staging environment and confirm tasks still discover,
   schedules still fire, and leases still expire. Discovery
   latency rises; the correctness substrate is unchanged.
4. **Introduce a stronger acceleration primitive (optional).**
   Implement `LongPollWakeStore` against a richer backend (for
   example Redis pub/sub, PostgreSQL `LISTEN/NOTIFY`, or NATS)
   and bind it in place of `CacheLongPollWakeStore`. The contract
   remains the same: `snapshot()` / `changed()` / `signal()` with
   a 60-second signal TTL upper bound. Discovery latency
   improves; the correctness substrate is unchanged.
5. **Tighten the validation mode.** Once the deployment is
   running on the chosen acceleration backend, move
   `DW_V2_CACHE_VALIDATION_MODE` to `fail` so a future
   misconfiguration is caught at boot rather than in production.

Each step is reversible. Rolling back to the cache-backed wake
store or to a single-node deployment is always legal; the
correctness substrate does not care.

## Operator-visible state

Operators see the separation between correctness and acceleration
through the following surfaces.

- `Workflow\V2\Support\OperatorMetrics::snapshot()` — reports
  `tasks.ready`, `tasks.leased`, `tasks.claim_failed`,
  `backlog.repair_needed_runs`, `schedules.active`,
  `schedules.missed`, and `workers.active_workers` derived from
  durable rows. These metrics answer the question "is scheduler
  correctness healthy?"
- `Workflow\V2\Support\HealthCheck::snapshot()` — reports
  `backend_capabilities`, `task_transport`, `durable_resume_paths`,
  `worker_compatibility`, and `long_poll_wake_acceleration` check
  status. Each check carries an explicit `category` field whose
  value is either `correctness` or `acceleration`. The snapshot
  also exposes a `categories` map with a rolled-up `status` per
  category so a single response answers the two operator
  questions below without re-aggregating the check list.
  Acceleration-layer issues appear as `warning`;
  correctness-substrate issues appear as `error`.
- `Workflow\V2\Support\OperatorQueueVisibility::forNamespace()`
  and `::forQueue()` — per-partition depth and claim state
  derived from `workflow_tasks`.
- Standalone server surfaces: `GET /api/system/metrics`,
  `GET /api/system/repair`, `POST /api/system/repair/pass`,
  `GET /api/system/activity-timeouts`, and
  `POST /api/system/activity-timeouts/pass` expose the same
  signals over HTTP.
- Cloud UI and Waterline consume the surfaces through the
  observability repository binding; they do not probe the
  acceleration layer directly.

Guarantees:

- Health surfaces MUST distinguish degraded acceleration from
  durable correctness failures. A cache outage MUST NOT mask a
  correctness failure, and a correctness failure MUST NOT be
  reported merely as a warning because the cache is up.
- Operators MUST be able to answer "is work being discovered?"
  and "is the acceleration layer propagating?" as separate
  questions, from separate metrics.
- Every `HealthCheck::snapshot()` check entry MUST carry a
  `category` of `correctness` or `acceleration`. The
  `long_poll_wake_acceleration` check is the acceleration-layer
  surface and MUST NOT raise its status above `warning` even
  when the configured backend is unreachable, because the
  acceleration layer is optional by contract. Correctness
  checks remain free to report `error` when the durable
  substrate is broken.

## Test strategy alignment

- `tests/Feature/V2/V2OperatorMetricsTest.php` — covers the
  correctness-substrate metric surface that operators watch when
  acceleration degrades.
- `tests/Feature/V2/V2OperatorQueueVisibilityTest.php` — covers
  per-partition visibility derived from durable rows.
- `tests/Feature/V2/V2TaskDispatchTest.php` — covers dispatch
  publication and ready-task discovery against the durable
  substrate.
- `tests/Feature/V2/V2ScheduleTest.php` — covers schedule fire
  evaluation from `workflow_schedules` rows.
- `tests/Unit/V2/CacheLongPollWakeStoreTest.php` and
  `tests/Unit/V2/LongPollCacheValidatorTest.php` — cover the
  acceleration-layer contract without coupling it to correctness
  assertions.
- This document is pinned by
  `tests/Unit/V2/SchedulerCorrectnessDocumentationTest.php`. A
  change that renames, removes, or narrows any named guarantee
  (correctness substrate rows, acceleration layer
  responsibilities, degraded-mode behaviors, bounded discovery
  latency, backend classification, or detection surfaces) must
  update the pinning test and this document in the same change
  so the contract does not drift silently.

## What this contract does not yet guarantee

The following are explicitly deferred to later roadmap phases and
must not be assumed:

- **Scheduler leader election across replicas** — Phase 6
  owns the leader coordination contract. Pre-Phase-6 deployments
  that run multiple scheduler replicas MUST rely on the
  per-schedule row lock as the only correctness seam.
- **Rollout-safety enforcement across protocol-version steps** —
  Phase 6 owns the rollout-safety seam. This contract does
  not freeze how a mixed-version cluster coordinates schedule
  leadership during a rollout.
- **A notifier-backed implementation of `LongPollWakeStore`
  shipped by this repo** — the contract permits such an
  implementation; the concrete binding and its health surface are
  tracked separately.
- **Durable notifier persistence across backend restarts** —
  notifiers are part of the acceleration layer and are allowed
  to drop signals on restart. Durable task redelivery remains the
  guaranteed path.
- **Elimination of shared cache** — the contract requires cache to
  be acceleration-only, not removed. A deployment may continue to
  use `CacheLongPollWakeStore`; it simply may not rely on cache
  for correctness.

## Changing this contract

A change to any named guarantee in this document is a
protocol-level change for the purposes of `docs/api-stability.md`
and downstream SDKs. Reviewers should treat unmotivated changes
to the language above as breaking changes and require explicit
cross-SDK coordination before merge. The Phase 5 roadmap
owns updates to this contract; Phase 6 must extend the
contract rather than silently redefine it.
