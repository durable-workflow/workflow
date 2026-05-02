# Workflow V2 Rollout Safety and Coordination Health Contract

This document freezes the v2 contract for how the system keeps itself
safe during rollout and how it surfaces distributed coordination
health to operators. It is the reference cited by product docs, CLI
reasoning, Waterline diagnostics, server deployment guidance, cloud
capacity planning, and test coverage so the whole fleet speaks one
language about boot-time admission, mixed-build safety, routing
drains, stuck-work detection, lease conflict visibility, and
machine-readable coordination health.

The guarantees below apply to the `durable-workflow/workflow` package
at v2, to the standalone `durable-workflow/server` that embeds it, and
to every host that embeds the package directly or talks to the server
over HTTP. A change to any named guarantee is a protocol-level change
and must be reviewed as such, even if the class that implements it is
`@internal`.

This contract builds on the semantics frozen in
`docs/architecture/execution-guarantees.md` (Phase 1), the routing
guarantees frozen in `docs/architecture/worker-compatibility.md`
(Phase 2), the matching and dispatch guarantees frozen in
`docs/architecture/task-matching.md` (Phase 3), the role-split
contract frozen in `docs/architecture/control-plane-split.md`
(Phase 4), and the cache-independence contract frozen in
`docs/architecture/scheduler-correctness.md` (Phase 5). Duplicate
execution, retries, redelivery, compatibility pinning, matching, the
six named roles, and the correctness substrate keep the language they
have there; this document adds the language for how rollout safety is
enforced in-system and how coordination health becomes explicit to
operators rather than inferred from behavior.

## Scope

The contract covers:

- **admission checks** — the boot-time gates that fail closed or warn
  loud when the configured backends, bindings, workflow definitions,
  and protocol versions cannot safely serve v2 traffic.
- **mixed-build safety** — the in-system rules that decide whether a
  fleet spanning more than one build can continue to claim work or
  must drain, and which reason codes operators see when it cannot.
- **schema fencing** — which migrations gate v2 operator surfaces,
  how the readiness contract exposes the schema version, and how a
  partial migration looks to callers.
- **routing drains** — the automatic block, drain, and fail-closed
  behavior that happens when no compatible executor exists for a
  ready task.
- **coordination health metrics** — the authoritative keys on
  `OperatorMetrics::snapshot()`, `HealthCheck::snapshot()`, and
  `OperatorQueueVisibility::forNamespace()` that surface queue
  latency, lease conflicts, duplicate-risk indicators, stuck-task
  detectors, retry rate, routing health, and projection lag.
- **Waterline observability** — the operator-facing surfaces that
  read these metrics and are part of the contract that a role split,
  a schedule leader swap, or a rollout pause stays observable to
  humans.
- **config surface** — the `DW_V2_*` environment variables that tune
  admission strictness, validation mode, fingerprint pinning,
  compatibility TTL, and repair cadence.
- **migration path** — how a deployment moves from today's "mostly
  rollout discipline" posture to in-system enforcement without a
  hard cutover.

It does not cover:

- the Phase 4 role split itself; Phase 4 froze the roles, and
  Phase 6 names the health and rollout surfaces that apply across
  them.
- the Phase 5 cache independence substrate; Phase 5 froze
  which layer is correctness and which is acceleration, and Phase 6
  inherits that split. Rollout safety MUST NOT reintroduce cache as
  a correctness dependency.
- host-level infrastructure choices such as Kubernetes readiness vs
  liveness probe cadence, load-balancer drain timeouts, service
  mesh retry policy, or blue/green vs canary vs in-place rollout
  tooling. Those are deployment concerns that consume this contract;
  they do not define it.
- replacement of the HTTP worker protocol or SQL persistence model.
  Rollout safety is a coordination change, not an engine rewrite.
- multi-region active/active coordination. Cross-region rollout is
  a future roadmap topic and is not covered.

## Terminology

- **Admission check** — a boot-time or per-call gate that decides
  whether a process, request, or task is allowed to proceed. An
  admission check may be configured to warn or to fail.
- **Fail closed** — when a check's configured mode blocks the
  affected operation instead of logging and continuing. A
  fail-closed admission check MUST surface an explicit reason so an
  operator can diagnose the block without log archaeology.
- **Mixed-build state** — a fleet shape where workers or servers
  advertise more than one compatibility marker, build id, or
  negotiated protocol version at the same time. Phase 2 guarantees
  single-step compatibility; Phase 6 guarantees that violations
  surface explicitly rather than silently.
- **Drain** — a cooperative shutdown where a worker refuses new
  claims, finishes in-flight work, and is eventually expired out of
  the fleet snapshot. Drain is not a hard stop.
- **Block** — a refusal to claim or route a specific task because a
  precondition fails. Blocks are scoped to the task or run that
  cannot safely progress; they do not halt the fleet.
- **Routing health** — the state of the match between ready tasks
  and live compatible workers per namespace and queue. Routing is
  healthy when every ready task has at least one eligible claimer;
  it is blocked when no compatible executor exists.
- **Coordination health** — the aggregate health of the durable
  dispatch state, the acceleration layer, the worker compatibility
  fleet, the projection surface, and the repair path. Operators
  read coordination health through the metrics snapshot, not by
  tailing logs.
- **Stuck** — a durable-state condition where progress has halted
  for reasons the system can see (missing next task, expired lease
  with no redelivery, repair_needed marker). Stuck is never silent;
  every stuck state is counted by `OperatorMetrics::snapshot()` and
  by `HealthCheck::snapshot()`.
- **Duplicate-risk indicator** — a metric that rises when conditions
  that could produce duplicate execution are present (overlapping
  claims, expired-then-redelivered leases, compatibility heartbeat
  churn). Duplicate risk is an observability signal; the Phase 1
  contract still governs what the durable state layer observes
  exactly once.
- **Rollout-safety envelope** — the set of admission checks,
  migration guards, and routing drains that together decide whether
  the fleet is in a safe configuration to accept new work.

## Admission authority and mixed-build safety

### Boot-time admission

Every v2 process runs a fixed sequence of admission checks before it
accepts work. Each check has a documented authority class, a
fail-closed behavior, and an operator-visible surface.

The canonical admission layers are:

- `Workflow\V2\Support\BackendCapabilities::snapshot()` — the authority
  on whether the configured database, queue, cache, and structural
  limits satisfy the v2 capability contract. Issues at `severity =
  error` mean the process cannot safely dispatch work; issues at
  `warning` mean the process may run but operators must see the
  gap. `BackendCapabilities::isSupported()` is the single boolean
  the rest of the system consults.
  `Workflow\V2\Support\StructuralLimits::snapshot()` is the
  authority on per-run structural bounds (pending activities,
  pending children, payload size, memo size, etc.) and feeds into
  the admission check through the `structural_limits` roll-up.
- `Workflow\V2\Support\LongPollCacheValidator::checkMultiNodeSafety()`
  — the authority on whether the configured cache store can
  propagate wake signals across nodes. The cache layer is the
  acceleration substrate, not the correctness substrate, so this
  admission check is warning-only by contract: a multi-node
  misconfiguration surfaces a logged diagnostic and the
  `long_poll_wake_acceleration` health check, but never blocks
  boot, regardless of `DW_V2_CACHE_VALIDATION_MODE`. The legacy
  `fail` value is accepted for backwards compatibility and behaves
  the same as `warn` for this check; the historical fail-closed
  posture is retired so a degraded acceleration layer cannot
  refuse traffic that the durable substrate is ready to serve.
- `Workflow\V2\Support\WorkflowModeGuard::check()` — the authority
  on replay-safety of registered workflow classes. Controlled by
  `DW_V2_GUARDRAILS_BOOT`; supported modes are `silent`, `warn`
  (default), and `throw`. `throw` is the fail-closed mode used in
  CI or in deployments that forbid replay-unsafe code from
  reaching production.
- `Workflow\V2\Support\ReadinessContract::definition()` — the
  authority on whether the v2 operator surface is available and on
  which HTTP status the server's `/api/health`, `/api/ready`, and
  stats routes return when a required surface is missing. The
  readiness contract is what the server hands to its platform
  (Kubernetes, Nomad, or the Cloudflare edge) as a machine-readable
  statement of "v2 is safe to serve."

Guarantees:

- An admission check that returns `error` severity MUST NOT be
  silently downgraded at runtime. The configured mode
  (`warn` / `fail` / `throw`) decides whether the process exits,
  refuses to serve, or continues with a logged warning, but the
  severity itself is authoritative.
- Admission checks are visible to operators through
  `OperatorMetrics::snapshot()` under `backend`, `structural_limits`,
  and `workers.required_compatibility`, and through
  `HealthCheck::snapshot()` as named `checks`. Operators MUST NOT
  have to read logs to know why a boot refused or warned.
- Admission is not optional to skip in production. A deployment MAY
  choose `warn` mode for individual checks while rolling out a
  change, but MUST NOT disable the check entirely.

### Mixed-build safety

Phase 2 already freezes the worker-compatibility marker
contract (`DW_V2_CURRENT_COMPATIBILITY`, `DW_V2_SUPPORTED_COMPATIBILITIES`),
the single-step compatibility rule across the fleet, and
`Workflow\V2\Support\TaskCompatibility` / `WorkerCompatibility`. Phase 6
adds the **enforcement** side of that contract.

The canonical fleet snapshot is
`Workflow\V2\Support\WorkerCompatibilityFleet::detailsForNamespace()`,
which returns the set of active worker heartbeats per namespace and
queue, including the compatibility markers each worker advertises
and the build ids it reports. This snapshot is the authority the
rest of the system consults for "who is live, and what can they
safely execute?"

Guarantees:

- A fleet state where the required compatibility marker for a
  namespace has no supporting live worker (i.e.
  `active_workers_supporting_required = 0`) surfaces through
  `OperatorMetrics::snapshot()` under `workers.required_compatibility`
  and `HealthCheck::snapshot()` under the `worker_compatibility`
  check. This is the canonical "the fleet cannot take this work"
  signal. The check reports `warning` by default and escalates to
  `error` when `DW_V2_FLEET_VALIDATION_MODE=fail` so the readiness
  contract returns 503 in fail-closed deployments. The check's
  `data.validation_mode` echoes the loaded posture so operators
  can confirm which mode is active without re-reading the env.
- Under `DW_V2_FLEET_VALIDATION_MODE=fail`, `TaskDispatcher` also
  blocks queue dispatch when the task's required compatibility
  marker has no supporting live worker in the task's
  connection/queue scope and the fleet has at least one active
  worker there. The task stays `Ready` with a
  `last_dispatch_error` that begins `Dispatch blocked under fail
  validation mode.`, and `repair_available_at` defers the retry
  through `TaskRepairPolicy::repairAvailableAtAfterFailure` so the
  watchdog redispatches once a compatible worker heartbeats.
  Scopes with no heartbeats yet and runs without a required
  marker fall back to normal dispatch so the first worker
  heartbeat never races an incoming dispatch.
- `Workflow\V2\Support\WorkflowStartGate` is the authority on
  per-call admission for new workflow runs under
  `DW_V2_FLEET_VALIDATION_MODE=fail`. It refuses to admit a start
  when the run's resolved connection/queue has at least one
  active worker heartbeat but none of them advertise the
  `WorkerCompatibility::current()` marker the producer is about
  to write onto the run. The start callers
  (`Workflow\V2\WorkflowStub::attemptStart`,
  `Workflow\V2\WorkflowStub::attemptSignalWithStart`, and
  `Workflow\V2\Support\DefaultWorkflowControlPlane::startWorkflow`)
  consult the gate inside the same transaction that would have
  created the run, persist a rejected `WorkflowCommand` carrying
  `CommandOutcome::RejectedCompatibilityBlocked` with
  `rejection_reason = compatibility_blocked`, and surface the
  refusal as
  `Workflow\V2\Exceptions\WorkflowExecutionUnavailableException`
  with `blockedReason() = compatibility_blocked` so
  `Workflow\V2\Support\ScheduleManager` can record a `skipped`
  trigger without creating a run. Scopes with no heartbeats yet
  and producers with no required marker fall back to normal
  start admission so the first worker heartbeat never races an
  incoming start. The rejection is observable through the
  `runs.compatibility_blocked` and
  `backlog.oldest_compatibility_blocked_started_at` keys on
  `OperatorMetrics::snapshot()`.
- Operators see mixed-build state explicitly through
  `workers.active_worker_scopes` (how many distinct
  namespace/queue/compatibility tuples are live) and through the
  Waterline workers view. Mixed-build state is never hidden; it
  is always a first-class metric.
- A control-plane request that pins a workflow to a recorded
  definition fingerprint MUST route only to workers that match that
  fingerprint. `Workflow\V2\Support\WorkflowDefinitionFingerprint`
  is the authority on fingerprint resolution; the matching role
  MUST refuse a claim whose worker fingerprint does not match the
  run's `workflow_definition_fingerprint` when
  `DW_V2_PIN_TO_RECORDED_FINGERPRINT=1`.
- Mixed-build admission is a fleet property, not a per-request
  property. A single worker advertising a new marker cannot change
  the fleet-wide required marker; the required marker is still
  read from the configured `DW_V2_CURRENT_COMPATIBILITY` on the
  control-plane side.

### Protocol version coordination

Phase 4 names three protocol-version surfaces (worker
protocol, control-plane protocol, internal role-to-role). Phase 6
adds the rollout-safety enforcement:

- `App\Http\WorkerProtocolVersionResolver` and
  `App\Http\ControlPlaneVersionResolver` are the two middleware
  classes that enforce the negotiated protocol version per request.
  Neither is allowed to serve a request that advertises a
  protocol version outside the single-step compatibility window
  documented by Phase 2.
- A version negotiation failure MUST surface as a stable reason
  code on the response, not as a 500. The contract reserves
  `compatibility_unsupported` for version gaps; the ingress layer
  is the one place that maps negotiation failures to HTTP.

## Schema fencing and migration safety

The workflow v2 runtime depends on a known set of durable tables
and projections. Phase 6 freezes how those dependencies are
advertised to callers and how a partial migration looks.

Guarantees:

- The readiness contract names the `boot_install` surface
  authority as `WaterlineEngineSource::status` and its readiness
  key as `v2_operator_surface_available`. When any configured v2
  operator model does not resolve to a readable table, the
  readiness contract reports the surface unavailable; Waterline
  `auto` engine source falls back to v1 and `v2` pinned engine
  source returns 503. Callers MUST NOT infer readiness from
  process liveness.
- `Workflow\V2\Support\RunSummaryProjector::SCHEMA_VERSION` is the
  authority on which projection shape the system is writing. A
  version mismatch surfaces through the
  `run_summary_projection` check on `HealthCheck::snapshot()`; a
  rebuild-needed marker is never silent.
- Worker registration rows carry a `workflow_definition_fingerprints`
  column after migration
  `2026_04_21_000300_add_workflow_definition_fingerprints_to_worker_registrations`;
  this is the authority surface the matching role reads to pin
  runs to their recorded fingerprint under
  `DW_V2_PIN_TO_RECORDED_FINGERPRINT`.
- Build-id drain state lives in the
  `workflow_worker_build_id_rollouts` table created by migration
  `2026_04_22_000200_create_workflow_worker_build_id_rollouts_table`.
  Rows carry `drain_intent` and `drained_at` so the matching role
  and Waterline surfaces can reason about ongoing fleet drains
  without inferring them from heartbeat absence.
- A migration that lands on one server before another MUST NOT
  corrupt the readiness surface. The contract requires that every
  v2 migration be additive or explicitly coordinated; a schema
  change that invalidates a running peer is a rollout-safety
  regression.

Reversibility:

- Every v2 migration named here is reversible by the standard
  Laravel `down()` path. Rolling back a schema change MUST NOT
  leave the readiness contract reporting the v2 surface as
  available when it is not; a rollback surfaces through the
  boot_install readiness key just like an unapplied forward
  migration.

## Routing safety: drain, block, and fail-closed

Routing safety is the per-task enforcement of mixed-build and
compatibility guarantees. The authority classes are the Phase 3
matching surfaces plus the Phase 2 compatibility types.

- `Workflow\V2\Support\ActivityTaskClaimer::claimDetailed()` is the
  authority on whether an activity task claim succeeds. Every
  refusal carries one of the frozen reason codes
  (`compatibility_unsupported`, `backend_unsupported`,
  `claim_contended`, `claim_expired`). Claim refusals update the
  task's `last_claim_error` and `last_claim_failed_at` columns so
  operators can see the most recent reason without reading logs.
- `Workflow\V2\Support\DefaultActivityTaskBridge` and
  `Workflow\V2\Support\DefaultWorkflowTaskBridge` are the poll-time
  filters that prevent a worker from ever seeing a task whose
  compatibility the worker does not support. The bridges MUST NOT
  return tasks the claimer would have refused; poll-time and
  claim-time filtering agree.
- `Workflow\V2\Support\ActivityWorkerBridgeReason::claim()` is the
  authority on how an internal reason code maps to the public HTTP
  reason surface. The ingress layer MUST route every refusal
  through this map; it MUST NOT collapse distinct reasons into a
  generic error.
- `Workflow\V2\Support\TaskRepair::repairRun()` is the authority on
  redispatch of ready-but-unclaimed tasks. Under
  `DW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS`, a task that has
  been ready without a claim for longer than the configured
  window is redispatched through the matching role rather than
  being assumed claimed by a missing worker. Repair never
  duplicates work that the durable state layer already observes as
  completed; it bumps attempts that have stalled waiting on a
  claim.
- `Workflow\V2\TaskWatchdog::runPass()` is the authority on
  fleet-wide repair sweeps. It is the execution surface behind the
  `POST /api/system/repair/pass` route and is what schedules
  redispatch passes in embedded and server topologies.

Guarantees:

- A ready task whose required compatibility has no live worker
  MUST remain ready. The matching role MUST NOT fabricate a
  claimer, MUST NOT drop the task, and MUST NOT exhaust retries
  because no worker polled. The task's visibility is preserved
  through the `compatibility_blocked_runs` backlog metric.
- A worker advertising `drain_intent = drain` MUST stop seeing
  new ready tasks for its drained build id. The drain is
  cooperative; the worker is expected to finish in-flight work
  and exit.
- A claim refusal for `compatibility_unsupported` or
  `backend_unsupported` MUST be observable through the task's
  `last_claim_error` field and through the `tasks.claim_failed`
  metric. Operators diagnose routing blocks from the metric, not
  from correlating worker logs.
- Routing safety never silently escalates to task loss. A task
  that cannot be routed safely is preserved, counted, and
  surfaced; the Phase 1 contract's at-least-once execution
  guarantee still applies after routing drains.

## Coordination health: metrics, checks, and visibility

Coordination health is the machine-readable view of the fleet.
Every rollout-safety decision operators make should be answerable
by reading a metric, not by correlating logs.

The canonical surfaces are:

- `Workflow\V2\Support\OperatorMetrics::snapshot($now, $namespace)` —
  the authoritative metrics object. Its keys are frozen by this
  contract and are consumed by the standalone server's
  `/api/system/metrics` route, by Waterline's system overview, and
  by cloud's status-page aggregation.
- `Workflow\V2\Support\HealthCheck::snapshot($now)` — the
  authoritative roll-up of the metrics snapshot into a
  named-check shape with `status = ok | warning | error`. The
  HTTP mapping is `HealthCheck::httpStatus()` (200 for
  `ok`/`warning`, 503 for `error`).
- `Workflow\V2\Support\OperatorQueueVisibility::forNamespace()` and
  `::forQueue()` — per-partition visibility showing active long
  pollers, current lease owners, queue stats, and repair stats.
  This is the matching-role view operators need to diagnose
  routing drains and lease conflicts.
- `Workflow\V2\Support\StandaloneWorkerVisibility` — the
  standalone-server adapter that projects worker registration rows
  (with build ids and fingerprints) into the same shape
  `OperatorQueueVisibility` uses.

### Frozen metric keys

The following keys on `OperatorMetrics::snapshot()` are part of this
contract. Renaming or removing any of them is a protocol-level
change.

| Section | Key | Meaning |
| ------- | --- | ------- |
| `runs` | `repair_needed` | open runs with `liveness_state = repair_needed` |
| `runs` | `oldest_repair_needed_at`, `max_repair_needed_age_ms` | earliest "stuck since" timestamp across runs whose `liveness_state` is exactly `repair_needed` and the largest stuck age in milliseconds, mirroring the `backlog.oldest_compatibility_blocked_started_at` / `max_compatibility_blocked_age_ms` shape on the routing path so operators can read "how long has the worst-case run been stuck without progress?" — the canonical stuck-workflow duplicate-risk age indicator paired with the `durable_resume_paths` health check — from the metric alone without walking `workflow_run_summaries`. The summary's `updated_at` is sourced by `RunSummaryProjector` from `WorkflowRun::last_progress_at`, so it advances with forward progress and stalls when the run stops progressing; for runs already at `repair_needed` it is the closest available proxy for "when this run last made progress before being marked broken." Falls back to the run's `started_at` when the projection has not recorded a progress boundary |
| `runs` | `claim_failed` | runs whose most recent task claim failed |
| `runs` | `compatibility_blocked` | runs blocked by compatibility mismatch |
| `runs` | `waiting`, `oldest_wait_started_at`, `max_wait_age_ms` | running runs currently parked at a durable resume point (`status_bucket = 'running'` and `wait_started_at IS NOT NULL`), the earliest `wait_started_at` among them, and the largest wait age in milliseconds. Mirrors the `backlog.oldest_compatibility_blocked_started_at` / `max_compatibility_blocked_age_ms` and `tasks.oldest_lease_expired_at` / `max_lease_expired_age_ms` shapes so operators can answer "how long has the worst-case run been waiting at a signal, update, timer, or compatible-worker arrival?" from the metric alone. The signal is unconditional and includes compatibility-blocked waits; consumers that want the non-compatibility share can subtract `runs.compatibility_blocked` and `backlog.oldest_compatibility_blocked_started_at`. |
| `tasks` | `ready`, `ready_due`, `delayed`, `leased` | queue depth by phase |
| `tasks` | `dispatch_failed`, `claim_failed` | transport failure counts |
| `tasks` | `dispatch_overdue`, `lease_expired` | lease and dispatch timing |
| `tasks` | `oldest_lease_expired_at`, `max_lease_expired_age_ms` | earliest `lease_expires_at` among leased tasks whose lease has expired at snapshot time and the largest expired-lease age in milliseconds, mirroring the `backlog.oldest_compatibility_blocked_started_at` / `max_compatibility_blocked_age_ms` shape so operators can answer "how long has the worst leased task been expired without redelivery?" (the primary stuck-lease duplicate-risk age indicator) from the metric alone |
| `tasks` | `oldest_ready_due_at`, `max_ready_due_age_ms` | earliest "ready since" timestamp among ready-due tasks (the effective `COALESCE(available_at, created_at)` — `available_at` when the task was delayed, otherwise the creation time that made it immediately actionable) and the largest ready-age in milliseconds, mirroring the `oldest_lease_expired_at` / `max_lease_expired_age_ms` shape so operators can read queue latency ("how long has the oldest actionable task been waiting to dispatch?") from the metric alone without walking `workflow_tasks` |
| `tasks` | `oldest_dispatch_overdue_since`, `max_dispatch_overdue_age_ms` | earliest `COALESCE(last_dispatched_at, created_at)` among dispatch-overdue tasks — the timestamp the worst-case ready-but-unclaimed task has been waiting for a successful dispatch wake since (either its last attempted dispatch that didn't stick or its creation time if it was never dispatched) — and the largest age in milliseconds, mirroring the `oldest_ready_due_at` / `max_ready_due_age_ms` shape so operators can read wake-latency ("how long has the oldest ready-but-unclaimed task been waiting for a working dispatch wake?") from the metric alone without walking `workflow_tasks` |
| `tasks` | `oldest_claim_failed_at`, `max_claim_failed_age_ms` | earliest `last_claim_failed_at` among claim-failed tasks (Ready tasks whose most recent claim attempt recorded an uncleared `last_claim_error`) and the largest claim-failed age in milliseconds, mirroring the `oldest_dispatch_overdue_since` / `max_dispatch_overdue_age_ms` shape for the dispatch path so operators can read "how long has the worst-case task been sitting with an uncleared claim error?" — the primary lease-conflict and duplicate-risk age indicator for the claim path — from the metric alone without walking `workflow_tasks` |
| `tasks` | `oldest_dispatch_failed_at`, `max_dispatch_failed_age_ms` | earliest `last_dispatch_attempt_at` among dispatch-failed tasks (Ready tasks whose most recent dispatch attempt recorded an uncleared `last_dispatch_error` that has not been superseded by a later successful dispatch) and the largest dispatch-failed age in milliseconds, mirroring the `oldest_claim_failed_at` / `max_claim_failed_age_ms` shape for the claim path so operators can read "how long has the worst-case task been sitting with an uncleared dispatch error?" — the primary transport-failure age indicator on the dispatch path — from the metric alone without walking `workflow_tasks` |
| `tasks` | `max_attempt_count`, `max_repair_count` | largest `attempt_count` and largest `repair_count` among open tasks (`status` in `Ready` or `Leased`) — the worst-case task-transport retry burn currently in flight. `max_attempt_count` is incremented by `ActivityTaskClaimer` and `DefaultWorkflowTaskBridge` on each claim or dispatch attempt; `max_repair_count` is incremented by `TaskRepair::repairRun()` on each redispatch. Closed tasks (`Completed` or `Failed`) are excluded so the signal tracks active retry burn rather than accumulating against historical rows. Operators can read "what is the largest number of claim/dispatch attempts and `TaskRepair` redispatches any in-flight task has accumulated?" — the primary task-side retry-rate indicator — from the metric alone without walking `workflow_tasks`. A sustained non-zero `max_repair_count` paired with a non-zero `dispatch_overdue` count is the canonical "dispatch wakes are not landing on a compatible claimer" reading on the task path |
| `tasks` | `unhealthy`, `oldest_unhealthy_at`, `max_unhealthy_age_ms` | sum of transport failure and lease expiry counts (the primary duplicate-risk indicator), the earliest of `oldest_dispatch_failed_at` / `oldest_claim_failed_at` / `oldest_dispatch_overdue_since` / `oldest_lease_expired_at` (`null` when `unhealthy = 0`), and the largest unhealthy age in milliseconds across those four contributing paths so operators can read "how stale is my worst-case duplicate-risk task overall?" from the metric alone without taking a max over four separate per-path age fields |
| `activities` | `retrying`, `oldest_retrying_started_at`, `max_retrying_age_ms` | activity executions currently in the retry window (Pending status with `attempt_count > 0`), the earliest `started_at` among them, and the largest retrying age in milliseconds, mirroring the `tasks.oldest_lease_expired_at` / `max_lease_expired_age_ms` shape on the task path so operators can answer "how long has the worst-case activity been chewing retries?" — the primary retry-rate age indicator on the activity path — from the metric alone without walking `activity_executions` |
| `activities` | `timeout_overdue`, `oldest_timeout_overdue_at`, `max_timeout_overdue_age_ms` | open activity executions whose `schedule_deadline_at` (Pending), `close_deadline_at` (Running), `schedule_to_close_deadline_at`, or `heartbeat_deadline_at` (Running) is `<= $now` and is therefore waiting for `ActivityTimeoutEnforcer` to enforce the timeout, the earliest such expired deadline timestamp across the four enforcement-relevant columns, and the largest overdue age in milliseconds. The `timeout_overdue` predicate mirrors `ActivityTimeoutEnforcer::expiredExecutionIds()` exactly, so the count is the operator-visible view of the same enforcement backlog — the activity-path counterpart of `tasks.lease_expired`. Both surface stuck work that the corresponding sweep has not yet reclaimed; sustained non-zero readings indicate the activity-timeout sweep is lagging or stalled and that worker liveness via heartbeat or start-to-close has stopped on at least one execution. The age data lets operators read "how long has the worst-case activity been past a timeout deadline without enforcement?" — the primary stuck-activity duplicate-risk age indicator on the activity path — from the metric alone without walking `activity_executions` |
| `backlog` | `runnable_tasks`, `delayed_tasks`, `leased_tasks` | authoritative backlog counts |
| `backlog` | `tasks_added_last_minute`, `tasks_dispatched_last_minute` | trailing-60-second queue-flow facts: distinct durable task rows created in the last minute and distinct durable task rows whose latest successful `last_dispatched_at` falls in the last minute. These are intentionally task-row facts, not a transport-attempt counter stream; repeated redispatches of the same durable task collapse to one count because `workflow_tasks` retains only the latest successful dispatch timestamp |
| `backlog` | `unhealthy_tasks`, `repair_needed_runs`, `claim_failed_runs`, `compatibility_blocked_runs` | stuck/blocked roll-ups |
| `backlog` | `oldest_compatibility_blocked_started_at`, `max_compatibility_blocked_age_ms` | earliest wait-start timestamp among compatibility-blocked runs and the largest blocked age in milliseconds, mirroring the `repair.oldest_missing_run_started_at` / `max_missing_run_age_ms` shape so operators can answer "how stale is the worst mixed-build block?" from the metric alone |
| `repair` | `missing_task_candidates`, `selected_missing_task_candidates`, `oldest_missing_run_started_at`, `max_missing_run_age_ms` | stuck-run detectors per `TaskRepairCandidates` |
| `projections.run_summaries` | `oldest_missing_run_started_at`, `max_missing_run_age_ms` | earliest `COALESCE(workflow_runs.started_at, workflow_runs.created_at)` among runs whose id is not present in `workflow_run_summaries` and the largest missing-projection age in milliseconds, mirroring the `repair.oldest_missing_run_started_at` / `max_missing_run_age_ms` shape so operators can read "how long has the worst-case run been without a run-summary projection?" — the primary projection-lag age indicator on the run-summary path — from the metric alone without walking `workflow_runs` |
| `workers` | `required_compatibility`, `active_workers`, `active_worker_scopes`, `active_workers_supporting_required` | routing-health signals per `WorkerCompatibilityFleet` |
| `workers` | `fleet` | per-scope fleet entries (`worker_id`, `namespace`, `connection`, `queue`, `supported`, `supports_required`, `recorded_at`, `expires_at`, `source`, `host`, `process_id`) so mixed-build state is legible to Waterline and other consumers without reinferring it from the summary counts |
| `schedules` | `active`, `paused`, `missed`, `oldest_overdue_at`, `max_overdue_ms`, `fires_total`, `failures_total` | scheduler-role health: active and paused schedules in namespace, active schedules whose `next_fire_at` is overdue at snapshot time, the earliest overdue `next_fire_at` among them, the largest overdue age in milliseconds, and running totals of fires and failures so scheduler lag and failure trends are legible without reading `workflow_schedules` directly |
| `matching_role` | `queue_wake_enabled`, `shape`, `wake_owner`, `task_dispatch_mode` | matching-role deployment shape on the process serving the snapshot: `queue_wake_enabled` reports `workflows.v2.matching_role.queue_wake_enabled` exactly as the `WorkflowServiceProvider` `Looping` listener consumes it, `shape` is `in_worker` when this process still runs the in-worker broad-poll wake and `dedicated` when the process has opted out so the broad sweep runs under `php artisan workflow:v2:repair-pass` instead, `wake_owner` names the cooperating owner for that sweep (`worker_loop` when this process still runs it, `dedicated_repair_pass` when a separate repair process should own it), and `task_dispatch_mode` reports the configured dispatch mode (`queue` or `poll`). The snapshot is process-local; in mixed-shape fleets, operators read one snapshot per node to confirm the opt-out was applied to the right nodes without inspecting config files |
| `matching_role.discovery_limits` | `poll_batch_cap`, `availability_ceiling_seconds`, `wake_signal_ttl_seconds`, `workflow_task_lease_seconds`, `activity_task_lease_seconds` | numeric matching-role contract values surfaced from the package source-of-truth: `poll_batch_cap` is the maximum batch of ready-task rows returned per poll, `availability_ceiling_seconds` is the cross-backend tolerance applied to `available_at` so freshly-available tasks survive sub-second timestamp drift, `wake_signal_ttl_seconds` is the default `CacheLongPollWakeStore` signal TTL, and `workflow_task_lease_seconds` / `activity_task_lease_seconds` are the default workflow and activity task lease durations. Operators and downstream tooling read these to verify the deployment matches the documented matching-role contract without grepping the source |
| `backend` | `issues`, `severity` | admission check roll-up from `BackendCapabilities` |
| `structural_limits` | per-limit snapshot | payload/memo/history size gates |
| `repair_policy` | `redispatch_after_seconds`, `loop_throttle_seconds`, `scan_limit`, `failure_backoff_max_seconds` | tuning knobs consulted by `TaskRepair` |

Consumers (Waterline, cloud, CLI, test coverage) may add their own
derived keys; they MUST NOT rename the frozen keys above.

### Frozen health check names

`HealthCheck::snapshot()` returns an array under `checks` with one
entry per named check. The following names are frozen:

- `backend_capabilities`
- `run_summary_projection`
- `selected_run_projections`
- `history_retention_invariant`
- `command_contract_snapshots`
- `task_transport`
- `activity_path`
- `routing_health`
- `durable_resume_paths`
- `worker_compatibility`
- `scheduler_role`

Each check carries `status`, `message`, and `data`. `routing_health`
is the authoritative drain-focused roll-up: it combines
`backlog.compatibility_blocked_runs`, `tasks.dispatch_overdue`, and
`tasks.claim_failed` with the process-local matching-role shape
(`queue_wake_enabled`, `matching_shape`, `wake_owner`, and
`task_dispatch_mode`) so operators can distinguish compatibility
drains from dispatch wake lag or claim churn — and read which
cooperating process is expected to own the broad wake on the node
serving the snapshot — without re-aggregating metrics across the
`matching_role` block. `wake_owner` is `worker_loop` on nodes that
still run the in-worker broad-poll wake and `dedicated_repair_pass`
on nodes that have opted out so the broad sweep runs as
`php artisan workflow:v2:repair-pass` instead. Adding a new check
is allowed; renaming or removing one is a protocol-level change.
The canonical check names above match the strings emitted by
`Workflow\V2\Support\HealthCheck::snapshot()` verbatim, and a runtime
pinning test in the workflow package asserts the match so doc/code
drift fails loudly.

### Queue visibility

`OperatorQueueVisibility::forNamespace()` returns per-queue
`QueueVisibilityDetail` with:

- `pollers` — active long pollers, including `build_id` so a
  drain is visible without inspecting worker logs.
- `stats` — queue depth and lease counts plus the trailing-60-second
  queue-flow facts `tasks_added_last_minute` and
  `tasks_dispatched_last_minute` so operators can read recent queue
  inflow (distinct durable task rows created in the last minute) and
  dispatch throughput (distinct durable task rows whose latest
  successful `last_dispatched_at` falls in the last minute) per
  partition without re-aggregating the namespace-wide
  `backlog.tasks_added_last_minute` /
  `backlog.tasks_dispatched_last_minute` row from
  `OperatorMetrics::snapshot()`. The same trailing-60-second window is
  also broken out by task type on `workflow_tasks.added_last_minute` /
  `workflow_tasks.dispatched_last_minute` and
  `activity_tasks.added_last_minute` /
  `activity_tasks.dispatched_last_minute`, alongside the existing
  per-type `ready_count`, `leased_count`, and `expired_lease_count`,
  so operators can answer "is the recent queue flow surge driven by
  the workflow path or the activity path?" without resampling
  `workflow_tasks` per task type. The flat `tasks_added_last_minute`
  and `tasks_dispatched_last_minute` keys are exactly the sum of the
  per-type breakdowns. These are intentionally task-row facts, not a
  transport-attempt counter stream; repeated redispatches of the same
  durable task collapse to one count because `workflow_tasks` retains
  only the latest successful dispatch timestamp.
- `currentLeases` — live lease owners with expiry times so lease
  conflicts surface as overlapping or soon-to-expire leases.
- `repairStats` — per-queue repair candidates so routing health
  is readable per partition.

Operators MUST be able to diagnose a routing drain or a scheduler
cache outage by reading this snapshot. The contract protects the
shape of the snapshot across role splits: moving the matching role
out of process MUST preserve these keys.

## Stuck detectors and the repair path

Stuck work is any durable-state condition where progress has halted
for reasons the system can observe. Phase 6 freezes which detectors
are authoritative and how they surface.

- **Missing next task.** An open run with no next ready task is
  counted by `TaskRepairCandidates::snapshot()` under
  `missing_task_candidates`. The `HealthCheck::snapshot()`
  `durable_resume_paths` check surfaces it as a warning with
  `repair_needed_runs > 0`.
- **Lease expired without redelivery.** A leased task whose
  `lease_expires_at` is in the past is counted under
  `tasks.lease_expired` and its worst-case expiry age is surfaced
  through `tasks.oldest_lease_expired_at` and
  `tasks.max_lease_expired_age_ms`, both forwarded on the
  `task_transport` health check. `TaskRepair::leaseExpired()` is the
  authority for the redelivery decision.
- **Ready but unclaimed.** A ready task that has sat past the
  repair window without being claimed is counted under
  `tasks.dispatch_overdue`, its worst-case waiting-for-dispatch
  timestamp is surfaced through
  `tasks.oldest_dispatch_overdue_since` and
  `tasks.max_dispatch_overdue_age_ms`, and both keys are forwarded on
  the `task_transport` health check (`dispatch_overdue_tasks`,
  `oldest_dispatch_overdue_since`, `max_dispatch_overdue_age_ms`).
  `TaskRepairPolicy::readyTaskNeedsRedispatch()` is the authority for
  the redispatch decision; the age data is observability so operators
  can tell the difference between "dispatch wake is sporadically
  slow" and "dispatch wake has stalled on this task for minutes".
- **Claim failed without clearing.** A ready task whose most recent
  claim attempt recorded an uncleared `last_claim_error` is counted
  under `tasks.claim_failed`, its worst-case claim-failed timestamp
  is surfaced through `tasks.oldest_claim_failed_at` and
  `tasks.max_claim_failed_age_ms`, and all three keys are forwarded
  on the `task_transport` health check (`claim_failed_tasks`,
  `oldest_claim_failed_at`, `max_claim_failed_age_ms`). The age data
  is observability so operators can tell the difference between "one
  worker briefly rejected a claim" and "the whole fleet has been
  rejecting this task for minutes" — a lease-conflict and
  duplicate-risk indicator on the claim path that mirrors the
  dispatch-path `dispatch_overdue` age signal.
- **Repair-needed runs.** Runs whose projected state shows
  `liveness_state = repair_needed` are counted under
  `runs.repair_needed`. The worst-case stuck age is surfaced through
  `runs.oldest_repair_needed_at` and `runs.max_repair_needed_age_ms`,
  both forwarded on the `durable_resume_paths` health check
  (`oldest_repair_needed_at`, `max_repair_needed_age_ms`) so the
  blocking health surface can tell operators how long the oldest
  repair-needed run has been stalled without forcing a separate
  operator-metrics read.
- **Activity timeout overdue.** An activity execution whose
  schedule-to-start, start-to-close, schedule-to-close, or heartbeat
  deadline has already passed without enforcement is counted under
  `activities.timeout_overdue`, and its worst-case overdue age is
  surfaced through `activities.oldest_timeout_overdue_at` and
  `activities.max_timeout_overdue_age_ms`. All three are forwarded on
  the `activity_path` health check (`timeout_overdue`,
  `oldest_timeout_overdue_at`, `max_timeout_overdue_age_ms`).
  `ActivityTimeoutEnforcer` is the authority for enforcement; the age
  data is observability so operators can tell the difference between
  "the timeout sweep is running normally between passes" and "the
  timeout sweep has stalled and a still-running attempt is past its
  deadline" — the activity-side counterpart of the task-path
  `lease_expired` signal and, on heartbeat-based deadlines, the
  primary activity-side duplicate-risk age indicator because a stalled
  enforcement pass leaves a still-running attempt past its heartbeat
  budget while a retry could be scheduled.
- **Sustained activity retry backlog.** Activity executions that have
  recorded at least one failed attempt are counted under
  `activities.retrying`, with worst-case age surfaced through
  `activities.oldest_retrying_started_at` and
  `activities.max_retrying_age_ms`. Both age keys are forwarded on the
  `activity_path` health check (`retrying`,
  `oldest_retrying_started_at`, `max_retrying_age_ms`) so a sustained
  retry backlog — worker, payload, or downstream-service health
  pressure that is keeping activities in the retry path — is legible
  from the metric alone without re-aggregating attempt history.
- **Stale projection.** A projection behind the authoritative
  history surfaces through the `run_summary_projection` and
  `selected_run_projections` checks on `HealthCheck::snapshot()`.
- **Long-parked wait.** A running run whose projector has recorded
  a `wait_started_at` is counted under `runs.waiting`, and its
  worst-case wait age is surfaced through `runs.oldest_wait_started_at`
  and `runs.max_wait_age_ms`, both forwarded on the
  `durable_resume_paths` health check (`waiting_runs`,
  `oldest_wait_started_at`, `max_wait_age_ms`). The signal includes
  every kind of wait — signal, update, timer, and compatibility-blocked
  wait — because each is a durable resume point the system is
  parked on. The check itself escalates only on `repair_needed_runs`;
  the wait-age data is observability so operators can decide whether
  the worst-case wait reflects healthy long-running work or a lost
  signal/update that the application must resend.

Guarantees:

- A stuck condition is never silent. Every named detector above
  surfaces through `OperatorMetrics::snapshot()` and through at
  least one `HealthCheck::snapshot()` check.
- The repair path never fabricates work. `TaskRepair` redispatches
  only tasks that were already in the durable store and whose
  observed state indicates redispatch is safe. Repair MUST NOT
  create a new logical task for a run that has already completed.
- `POST /api/system/repair/pass`, `POST /api/system/activity-timeouts/pass`,
  and `POST /api/system/retention/pass` are the three admin-only
  surfaces that execute repair, timeout enforcement, and retention
  passes on demand. They are fail-closed on missing authentication
  and authoritative for the repair execution path.

## Waterline observability surfaces

Coordination health is only useful if humans can see it. The
Waterline UI is the operator-facing consumer of the metrics and
visibility surfaces above. Phase 6 names the canonical screens and
cards so a role split, a schema change, or a new rollout-safety
signal has a defined home.

Canonical screens:

- `resources/js/screens/dashboard.vue` — the system-wide overview
  that reads `OperatorMetrics::snapshot()` to show queue depth,
  worker health, and compatibility coverage. The dashboard is the
  default rollout-safety view.
- `resources/js/screens/workers.vue` — the worker fleet view that
  reads `WorkerCompatibilityFleet::detailsForNamespace()` and
  `StandaloneWorkerVisibility` to show live heartbeats, build ids,
  drain intents, and compatibility markers per worker.
- `resources/js/screens/flows/index.vue` and `flow.vue` — the run
  list and run detail views that surface `repair_needed`,
  `claim_failed`, and `compatibility_blocked` state per run, and
  show the recorded workflow definition fingerprint alongside the
  current one so operators can see pinning state at a glance.
- `resources/js/components/WorkerHealth.vue` — the worker health
  card that shows lease freshness, heartbeat recency, and the
  supported compatibility markers for a single worker.
- `resources/js/components/ScheduleView.vue` — the schedule view
  that reads `schedules.active`, `schedules.missed`, and per-schedule
  fire history so scheduler-role health is visible to humans.

Guarantees:

- Every frozen `OperatorMetrics::snapshot()` key is rendered
  somewhere in Waterline. A metric that no screen renders is not
  contract-observable to humans; a new frozen key MUST come with a
  Waterline surface that displays it.
- Waterline reads through the server's `/api/system/*` routes or
  the package's `OperatorObservabilityRepository` binding, never
  directly against the database. This preserves the Phase 4
  read/write boundary between the API ingress role and the rest.
- The schema, topology, and rollout-safety state a Waterline
  screen renders MUST NOT silently diverge from
  `HealthCheck::snapshot()`. If the snapshot reports `error`, the
  screen indicates `error`; if it reports `warning`, the screen
  surfaces the warning.

## Config surface for rollout safety

The following `DW_V2_*` environment variables tune admission
strictness, validation mode, fingerprint pinning, compatibility
TTL, and repair cadence. Variable names are frozen; defaults may
change only in a major version bump.

| Variable | Purpose |
| -------- | ------- |
| `DW_V2_NAMESPACE` | Default namespace for v2 surfaces when the host does not pin one. |
| `DW_V2_CURRENT_COMPATIBILITY` | The compatibility marker the process advertises and, on the control plane, the marker ready tasks require. |
| `DW_V2_SUPPORTED_COMPATIBILITIES` | Comma-separated list of markers this worker can safely execute; falls back to the current marker. |
| `DW_V2_COMPATIBILITY_NAMESPACE` | Isolated compatibility namespace for multi-tenant deployments. |
| `DW_V2_COMPATIBILITY_HEARTBEAT_TTL` | TTL for compatibility heartbeat entries; controls how long a missing worker counts as live. |
| `DW_V2_PIN_TO_RECORDED_FINGERPRINT` | Pin runs to the workflow definition fingerprint recorded at start; forces matching-role refusal on fingerprint drift. |
| `DW_V2_GUARDRAILS_BOOT` | Boot-time workflow determinism guardrail mode (`silent`, `warn`, `throw`). |
| `DW_V2_MULTI_NODE` | Declares multi-node intent; enables cache validation gates and mixed-build signal collection. |
| `DW_V2_VALIDATE_CACHE_BACKEND` | Enables the Phase 5 cache validation gate at boot. |
| `DW_V2_CACHE_VALIDATION_MODE` | Diagnostic mode for the cache acceleration admission (`silent`, `warn`, `fail`). The cache admission is warning-only by contract: `silent` suppresses the diagnostic, and `warn` and `fail` both log a warning without blocking boot. |
| `DW_V2_FLEET_VALIDATION_MODE` | Worker-compatibility fleet admission posture (`warn` or `fail`); `fail` escalates the `worker_compatibility` health check from `warning` to `error` so the readiness contract returns 503, and blocks queue dispatch so ready tasks stay retained under `repair_available_at` until a compatible worker heartbeats, both only when the required compatibility marker has no supporting live worker. |
| `DW_V2_TASK_DISPATCH_MODE` | Task delivery mode (`queue` or `poll`). |
| `DW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS` | Ready-task redispatch grace period. |
| `DW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS` | Minimum interval between repair passes. |
| `DW_V2_TASK_REPAIR_SCAN_LIMIT` | Maximum tasks a single repair pass inspects. |
| `DW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS` | Upper bound on repair failure backoff. |

Guarantees:

- Every variable above is read through the package's `Env::dw()`
  resolver so the legacy `WORKFLOW_V2_*` names remain supported
  for backward-compatibility. Phase 6 does not retire the legacy
  names; it names the `DW_V2_*` form as the canonical one.
- A process MUST advertise its effective values through
  `OperatorMetrics::snapshot()` (`repair_policy`, `workers`,
  `backend`) so operators can see the config surface the
  runtime actually loaded, not only what the env file declares.

## Migration path

The enforcement side of rollout safety lands incrementally. No
deployment is required to change shape; deployments that want the
tighter fail-closed posture can adopt each step independently.

1. **Audit admission wiring.** Confirm every v2 process loads
   `BackendCapabilities`, `LongPollCacheValidator`,
   `WorkflowModeGuard`, and `ReadinessContract` at boot. A process
   that does not run the admission checks is by definition
   rollout-unsafe.
2. **Turn on validation in `warn` mode.** Set
   `DW_V2_VALIDATE_CACHE_BACKEND=1`,
   `DW_V2_CACHE_VALIDATION_MODE=warn`, and
   `DW_V2_GUARDRAILS_BOOT=warn`. Operators observe warnings
   without blocking existing traffic.
3. **Surface mixed-build state.** Set `DW_V2_MULTI_NODE=1` so the
   fleet snapshot collects cross-node compatibility markers. The
   Waterline workers view begins showing mixed-build
   distributions.
4. **Pin fingerprints.** Set `DW_V2_PIN_TO_RECORDED_FINGERPRINT=1`
   so runs already in flight remain pinned to their recorded
   workflow definition fingerprint. The matching role starts
   refusing claims whose worker does not match the pin.
5. **Move repair cadence under operator control.** Tune
   `DW_V2_TASK_REPAIR_*` to match the production latency budget;
   the defaults are conservative.
6. **Tighten to fail mode.** Set
   `DW_V2_FLEET_VALIDATION_MODE=fail` so the readiness contract
   refuses traffic when no active worker advertises the required
   compatibility marker, and if the replay-safety guardrail is
   green, switch `DW_V2_GUARDRAILS_BOOT=throw` in
   CI. Production may stay on `warn` if that matches the
   operator's risk posture. `DW_V2_CACHE_VALIDATION_MODE` is
   warning-only by contract and does not participate in this
   step — the cache admission stays a diagnostic regardless of
   the configured value.

Each step is reversible; every environment variable can be
downgraded without data migration.

## Test strategy alignment

- `tests/Unit/V2/V2HealthCheckTest.php` covers the
  `HealthCheck::snapshot()` shape and the fixed check names.
- `tests/Feature/V2/V2OperatorMetricsTest.php` covers the frozen
  metric keys under `OperatorMetrics::snapshot()`.
- `tests/Feature/V2/V2OperatorQueueVisibilityTest.php` covers
  `OperatorQueueVisibility::forNamespace()` and
  `::forQueue()` including build id surfacing.
- `tests/Feature/V2/V2CompatibilityWorkflowTest.php` covers the
  Phase 2 + Phase 6 interaction: fleet compatibility state,
  routing blocks, and the `compatibility_blocked_runs` metric.
- `tests/Feature/V2/V2TaskDispatchTest.php` and the
  `TaskRepair`/`TaskWatchdog` feature coverage pin the stuck
  detector and repair path behavior.
- `tests/Unit/V2/LongPollCacheValidatorTest.php` pins the cache
  validation modes that Phase 5 and Phase 6 share.
- `tests/Unit/V2/ReadinessContractTest.php` pins the readiness
  surface shape and HTTP status behavior.
- This document is pinned by
  `tests/Unit/V2/RolloutSafetyDocumentationTest.php`. A change
  that renames, removes, or narrows any named guarantee (the
  admission layers, the frozen metric keys, the frozen health
  check names, the routing safety rules, the stuck detectors,
  the Waterline screen list, the config surface, or the
  migration-path steps) MUST update the pinning test and this
  document in the same change so rollout-safety behavior does
  not drift silently.

## What this contract does not yet guarantee

The following are explicitly deferred to later roadmap work and
MUST NOT be assumed:

- Scheduler leader election across replicas. Pre-Phase-6
  deployments ran a single scheduler server replica; Phase 6 freezes
  the drain/block surfaces the scheduler exposes but does not
  itself implement multi-replica leader election. That work, if
  taken, is a follow-on roadmap issue and must extend rather than
  redefine the surfaces named here.
- Cross-region active/active coordination. The metrics, health,
  and routing surfaces above assume a single workflow database.
- Automatic rollback of a failed migration. Migration reversibility
  is guaranteed by the Laravel `down()` path; the decision to roll
  back is an operator action, not an in-system reflex.
- Client-side rollout tooling (blue/green controllers, canary
  splitters, Helm chart overlays). Those are deployment tooling
  concerns that consume this contract.
- Replacement of the shared cache acceleration layer with a
  stronger primitive. That seam is part of Phase 5.
- Any change to the Phase 4 role split. Phase 6 must be
  implementable inside each role as defined; it does not move
  authority between roles.

## Changing this contract

A change to any named guarantee in this document is a
protocol-level change for the purposes of `docs/api-stability.md`
and downstream SDKs. Reviewers should treat unmotivated changes to
the language above as breaking changes and require explicit
cross-SDK coordination before merge. The Phase 6 roadmap
owns updates to this contract; any follow-on architecture phase
must extend the contract rather than silently redefine it. The
Phase 1, Phase 2, Phase 3, Phase 4,
and Phase 5 contracts remain the foundation this document
builds on.
