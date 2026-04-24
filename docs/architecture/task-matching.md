# Workflow V2 Task Matching and Dispatch Contract

This document freezes the v2 contract for how ready workflow tasks and
activity tasks are discovered, claimed, dispatched, and routed to
workers. It is the reference cited by the v2 docs, CLI reasoning,
Waterline diagnostics, server deployment guidance, and test coverage so
the whole fleet speaks one language about ready-task discovery, queue
partitioning, worker wake notification, lease-based ownership, and
backpressure.

The guarantees below apply to the `durable-workflow/workflow` package at
v2 and to every host that embeds it or talks to it over the worker
protocol. A change to any named guarantee is a protocol-level change
and must be reviewed as such, even if the class that implements it is
`@internal`.

This contract builds on the semantics frozen in
`docs/architecture/execution-guarantees.md` (Phase 1) and the routing
guarantees frozen in `docs/architecture/worker-compatibility.md`
(Phase 2). Duplicate execution, retries, redelivery, and compatibility
keep the language they have there; this document adds the language for
how ready tasks find compatible workers without making every node
poll every shared surface.

## Scope

The contract covers:

- **task matching role** — the explicit role responsible for turning a
  set of durable ready-task rows into individual task assignments to
  live workers, without prescribing whether that role runs as a
  library inside the worker process, an in-server module, or an
  out-of-process matching service.
- **ready-task discovery** — how a poller finds the workflow tasks and
  activity tasks that are ready to run, what filters apply, and which
  rows are eligible.
- **claim and lease ownership** — how a poll result is converted to an
  exclusive lease for one worker, what reason codes a rejected claim
  surfaces, and how lease expiry interacts with redelivery.
- **dispatch and enqueue** — how a newly available task becomes
  visible to pollers and, in queue-dispatch mode, how it is published
  to the host's Laravel queue.
- **wake notification** — how pollers learn that work has arrived
  without constant database probing, including the channel naming,
  signal TTL, and snapshot/changed semantics.
- **queue partitioning and routing primitives** — what `connection`,
  `queue`, `compatibility`, and `namespace` partition on, and what
  is intentionally left as host policy.
- **backpressure and fairness** — how lease ownership, lease expiry,
  and redelivery bound the work a single worker holds and how starved
  partitions become visible to operators.
- **operator-visible matching state** — the metrics and queue surfaces
  that report ready depth, leased depth, and stuck-claim conditions
  per partition.

It does not cover:

- the control-plane/data-plane role split described by Phase 4.
  The split will move matching onto a dedicated control-plane role,
  but must preserve the discovery, claim, and wake guarantees below.
- the scheduler cache independence work described by Phase 5.
  Cache independence will replace the shared-cache wake backend with
  a stronger primitive but must preserve the snapshot/changed contract
  named here.
- the rollout safety enforcement work described by Phase 6.
- host-level queue infrastructure choices such as which Laravel queue
  driver to deploy, how to size queue workers, or whether to run a
  dedicated supervisor process. Those are deployment concerns that
  consume this contract; they do not define it.

## Terminology

- **Matching role** — the logical responsibility of selecting eligible
  task rows from durable storage and surfacing them to one compatible
  worker. Today the role is implemented as a library inside each
  worker process via `Workflow\V2\Support\DefaultWorkflowTaskBridge`
  and `Workflow\V2\Support\DefaultActivityTaskBridge`; future phases
  may concentrate the role into a dedicated service. The contract
  is independent of the deployment shape.
- **Ready task** — a row in `workflow_tasks` whose `status` is
  `ready` and whose `available_at` is null or in the past, after the
  one-second availability ceiling described under Ready-task
  discovery. Ready tasks are eligible for poll surfacing; they are
  not yet claimed.
- **Eligible task set** — the subset of ready tasks that match the
  filters supplied by a poller (`connection`, `queue`,
  `compatibility`, `namespace`, and for activity polls the
  `activity_types` filter).
- **Claim** — the act of converting one ready row into a leased row
  owned by a named `lease_owner`. A claim either succeeds with a
  lease expiry or returns a typed reason code.
- **Lease** — `(lease_owner, lease_expires_at)` on a leased task row.
  The lease owner is the only worker authorised to commit progress
  for that task until the lease is renewed, released, or expired.
- **Redelivery** — the same logical work surfacing again to a poller
  after the previous lease expired or the previous attempt failed
  to commit. Redelivery is at-least-once by Phase 1 contract.
- **Wake channel** — a named string under which the matching layer
  records that new work has arrived. Pollers snapshot a channel set
  before probing the database and re-probe when any version stamp
  has changed. See `Workflow\V2\Contracts\LongPollWakeStore`.
- **Partition primitive** — one of `connection`, `queue`,
  `compatibility`, or `namespace`. Partition primitives let workers
  subscribe to a slice of the eligible task set without coordinating
  with peers.
- **Backpressure** — the property that the number of in-flight
  leases for a worker is bounded by the worker's claim cadence and
  the lease expiry, not by an arbitrary scheduler decision. Slow
  workers naturally drop out of the eligible-claimer set as their
  leases hold.

## The matching role

The matching role has one job: take a stream of ready-task rows and
produce one assignment per task to a live, compatible worker. The
role is bound by the following guarantees regardless of where it
runs:

- Every assignment terminates either as a successful claim, a typed
  rejection, or a lease expiry that returns the row to the eligible
  set. The role does not silently drop work.
- Every assignment is observable. Successful claims are recorded on
  the task row (`leased_at`, `lease_owner`, `lease_expires_at`,
  `attempt_count`); rejections are recorded as `last_claim_failed_at`
  / `last_claim_error` on the row through
  `Workflow\V2\Support\TaskBackendCapabilities` and surfaced through
  the operator metrics under `tasks.claim_failed`.
- Every assignment honours the Phase 2 compatibility contract.
  `Workflow\V2\Support\TaskCompatibility::supported()` is the single
  authoritative check; the matching role does not invent its own
  compatibility predicate.
- Every assignment honours the Phase 1 idempotency contract. A claim
  for an already-leased task returns the typed
  `task_not_claimable` rejection rather than transferring ownership.

The role is allowed to run in three deployment shapes today and in
the future:

- **In-worker library shape** — the worker process calls
  `DefaultWorkflowTaskBridge::claim()` /
  `Workflow\V2\Support\ActivityTaskClaimer::claimDetailed()` directly.
  This is the current shape and it MUST remain supported.
- **In-server HTTP shape** — workers reach the matching role through
  `POST /api/worker/workflow-tasks/poll` and
  `POST /api/worker/activity-tasks/poll` on the standalone server,
  which delegates to the same bridge classes.
- **Dedicated matching role shape** — a deployment may concentrate
  the matching role into a separate process or service that owns
  ready-task discovery and claim assignment for the cluster. The
  HTTP and library shapes above MUST continue to work against such
  a deployment.

Operators opt a fleet into the dedicated matching role shape by
running `php artisan workflow:v2:repair-pass` in a dedicated process
and disabling the in-worker broad-poll wake on execution nodes with
`workflows.v2.matching_role.queue_wake_enabled = false` (env
`DW_V2_MATCHING_ROLE_QUEUE_WAKE=0`). Disabling the wake suppresses
the `TaskWatchdog::wake()` call the `Illuminate\Queue\Events\Looping`
listener in `WorkflowServiceProvider` makes on every queue-worker
poll, so execution-only nodes stop broad-polling the durable task
table; claim-time fencing stays authoritative on those nodes through
the bridge classes. The default remains `true` so existing
single-role deployments are unchanged.

## Ready-task discovery

The matching role discovers ready tasks by querying the durable task
table with the partition primitives below. The query is intentionally
narrow:

- `task_type` matches the bridge that issued the poll
  (`workflow` or `activity`).
- `status = 'ready'`.
- `available_at` is null or `<=` `now() + 1 second`. The one-second
  availability ceiling is a deliberate cross-backend tolerance so
  that tasks created in the same request tick are reliably surfaced
  on backends with sub-second timestamp drift (notably SQLite). It
  is part of the contract; tightening it would silently de-list
  freshly-available tasks.
- Optional `connection`, `queue`, `compatibility`, and `namespace`
  equality filters are applied when the poller passed them.
- Activity polls additionally filter on the `activity_types` array
  the worker advertises.
- Results are ordered by `available_at` then `id` and capped at a
  maximum batch of 100 rows per poll.

The poll surface is the same whether it is reached as a library
call (`DefaultWorkflowTaskBridge::poll()` /
`DefaultActivityTaskBridge::poll()`) or over HTTP. The shape of the
returned row is part of the worker protocol and cannot be silently
narrowed.

Ready-task discovery returns an opportunity, not a reservation. The
returned rows are not yet claimed; another poller may claim them
before this poller gets there. Callers MUST handle a
`task_not_claimable` reason code as a normal race outcome, not a
bug condition.

## Claim and lease ownership

The matching role serialises claims through a per-task transaction
that takes a row-level lock on the task row and the owning workflow
run row. Claim outcomes are typed:

### Workflow task claim outcomes

`Workflow\V2\Support\DefaultWorkflowTaskBridge::claimStatus()` returns
one of:

- `task_not_found` — the task id does not exist.
- `task_not_workflow` — the row exists but is not a workflow task.
- `task_not_claimable` — the row is not in `ready` status. This is
  the normal lost-race outcome and MUST be treated as such.
- `run_not_found` — the owning workflow run row does not exist.
- `run_closed` — the owning run is in a terminal status. The task
  is left in place for repair / cleanup; the claim is rejected.
- `backend_unavailable` — the configured backend cannot satisfy the
  capabilities the task requires (recorded through
  `TaskBackendCapabilities::recordClaimFailureIfUnsupported()`).
- `compatibility_blocked` — the worker's `supported` compatibility
  set does not cover the task's required marker, per Phase 2.

A successful claim writes `status = leased`, `leased_at`,
`lease_owner`, `lease_expires_at`, and increments `attempt_count` on
the task row, then projects the run summary so operator surfaces
reflect the new lease.

### Activity task claim outcomes

`Workflow\V2\Support\ActivityTaskClaimer::claimDetailed()` returns
one of:

- `task_not_found`, `task_not_activity`, `task_not_ready`,
  `task_not_due` (with `retry_after_seconds`),
  `activity_execution_missing`, `workflow_run_missing`,
  `backend_unsupported`, `compatibility_unsupported`.
- A successful claim creates an `ActivityAttempt` row, transitions
  the activity execution to `Running`, records `ActivityStarted`,
  and dispatches the lifecycle event hook.

### Worker-facing reason translation

`Workflow\V2\Support\ActivityWorkerBridgeReason` is the canonical
translation between internal claim reason codes and the worker-facing
reason codes returned over HTTP. The Phase 2 reason codes
`compatibility_blocked` and `compatibility_unsupported` are part of
the worker protocol and MUST be surfaced verbatim to workers; the
remaining internal codes may be collapsed by the bridge into the
worker-facing `task_not_claimable` / `backend_unavailable` codes.

### Lease expiry and redelivery

Lease ownership is bounded:

- Workflow task leases expire 5 minutes after issue unless renewed.
- Activity task leases expire per
  `Workflow\V2\Support\ActivityLease::expiresAt()` and are renewed
  by `DefaultActivityTaskBridge::heartbeat()`.
- Once a lease expires, the row is eligible for redelivery to any
  worker. Redelivery is at-least-once by the Phase 1 contract; the
  matching role does not promise the original worker gets the next
  attempt. Workers MUST treat lease loss as a normal redelivery
  event, not a fault.

`Workflow\V2\Support\TaskRepair` owns the recovery path for tasks
that have been abandoned, lost, or never picked up. Repair does not
duplicate history (per Phase 1) and routes through the same claim
contract above.

## Dispatch and enqueue

`Workflow\V2\Support\TaskDispatcher` is the publication side of the
matching role. It is invoked when the engine has just persisted a
new ready task and wants to wake any waiting workers. The contract:

- Dispatch publication is deferred to `DB::afterCommit()` whenever
  it is invoked inside a transaction. A task that is rolled back
  is never published.
- In `task_dispatch_mode = 'poll'` the dispatcher records
  `last_dispatched_at` on the row and signals the wake channels
  but does not enqueue a Laravel job. Pollers discover the row
  through ready-task discovery alone.
- In the default `task_dispatch_mode = 'queue'` the dispatcher also
  enqueues one of `RunWorkflowTask`, `RunActivityTask`, or
  `RunTimerTask` onto the task's `connection` / `queue`, with a
  delay derived from `available_at`. Failures are captured into
  `last_dispatch_attempt_at` / `last_dispatch_error` and the task
  remains discoverable through poll. Operators see dispatch
  failures through `tasks.dispatch_failed` on
  `OperatorMetrics::snapshot()`.
- The two modes are equivalent for correctness. They differ only in
  how aggressively the host wakes workers between polls. Dispatch
  publication does not bypass the claim contract; an enqueued job
  still goes through the same claim path.

The mode is configured by `workflows.v2.task_dispatch_mode`
(env `DW_V2_TASK_DISPATCH_MODE`).

## Wake notification

Workers do not poll the database in a tight loop. The matching
layer signals named wake channels through
`Workflow\V2\Contracts\LongPollWakeStore`; pollers snapshot the
channel versions before probing the database and re-probe when a
version changes.

Guarantees:

- **Channel naming is opaque to the contract.** The current
  implementation builds workflow channels as
  `workflow-tasks:{namespace|shared}:{connection|*}:{queue|*}` and
  activity channels symmetrically, with shared and per-namespace
  channels signalled in parallel so a worker that subscribes only
  to its namespace and a worker that subscribes globally both
  wake. Other implementations of the contract may use other
  naming, but the per-namespace and shared channels MUST both be
  signalled when a relevant task arrives.
- **Snapshot/changed semantics are mandatory.** A poller calls
  `snapshot()` to capture per-channel version stamps, then later
  calls `changed()` to ask whether any channel moved. The store
  MUST report a change whenever a relevant signal occurred between
  the snapshot and the check, even if the version was overwritten
  multiple times. Implementations may collapse rapid signals into
  one observable change.
- **Signal TTL is bounded.** The cache-backed implementation uses
  a 60-second signal TTL by default. Wake notification is a
  performance optimisation; it is not the correctness boundary,
  so a missed wake does not lose work — the next poll cycle will
  still discover the task. The TTL upper bound is part of the
  contract because automation that observes wake-channel staleness
  needs a documented ceiling.
- **History waits use the same primitive.** A worker waiting on
  history for a specific run subscribes to
  `LongPollWakeStore::historyRunChannel($runId)`. New history
  events for that run signal the run channel through
  `CacheLongPollWakeStore::signalHistoryEvent()`.
- **Multi-node deployment requires a shared backend.** The
  default cache-backed implementation MUST be configured against
  Redis, a database cache, or another backend reachable from every
  server node. File or in-memory backends do not coordinate
  signals across nodes and break the multi-node guarantee. This
  is the explicit Phase 5 seam.

Wake notification is observability of new work, not an assignment.
A signalled channel does not promise a specific task to the
poller; it promises that the eligible task set may have grown
since the poller last probed. Multiple workers may wake on the same
signal and race for the same row; the claim contract above arbitrates
the race.

## Queue partitioning and routing primitives

The matching role exposes four partition primitives. Each is a
column on the task row and a filter on the poll surface:

- **`connection`** — the host-side queue connection name. Tasks are
  produced with the connection the engine selected for them; pollers
  may subscribe to one connection or to all (null filter). The
  engine does not interpret connection names beyond equality.
- **`queue`** — the host-side queue name within the connection.
  Same equality semantics. Operators wanting stronger isolation
  between traffic classes (priority lanes, regulated workloads,
  etc.) MUST use distinct queue names; the contract does not bake
  priority semantics into queue names.
- **`compatibility`** — the Phase 2 compatibility marker. Pollers
  filter by their advertised marker(s). This is poll-time
  optimisation only; claim-time enforcement remains authoritative.
- **`namespace`** — the value of
  `workflows.v2.compatibility.namespace`. Multiple cooperating apps
  share one workflow database by scoping reads through the
  namespace filter. `null` means "not scoped"; pollers that do not
  pass a namespace see every namespace they have access to.

What the contract intentionally does not partition on:

- The matching role does not encode compatibility into queue names.
  Compatibility is enforced at claim time; routing through queue
  names is host policy.
- The matching role does not shard tasks by hash of `task_id` across
  pollers. Sharding policy is intentionally a queue-naming choice
  so it can be changed without protocol coordination.
- The matching role does not implement queue-level priorities. A
  host that needs priority traffic must run priority queues.

These intentional non-guarantees are how Phase 4 and later
phases keep room to introduce a real matching service without
re-litigating the partition primitives.

## Backpressure and fairness

The contract relies on lease ownership for backpressure rather than
an explicit per-worker quota:

- A worker holds a task only as long as its lease has not expired.
  Slow workers naturally fall out of the eligible-claimer set as
  their leases occupy the row.
- Workers advertise `max_concurrent_workflow_tasks` and
  `max_concurrent_activity_tasks` at registration time so operator
  surfaces can show the planned capacity. The matching role does
  not enforce these limits at claim time today; that enforcement
  is explicitly part of the Phase 6 rollout-safety work.
- Long lease expiry (5 minutes for workflow tasks) is the
  fail-stop boundary for an unresponsive worker. Operators sizing
  rollout windows MUST treat this as the upper bound on how long
  a crashed worker can hold work before redelivery.
- Repair via `Workflow\V2\Support\TaskRepair::repairRun()` /
  `recoverExistingTask()` is the safety net for runs whose tasks
  have been lost or never enqueued. It is observable through
  `repair.existing_task_candidates` and
  `repair.missing_task_candidates` on `OperatorMetrics::snapshot()`.

Fairness across pollers is a property of the underlying queue
driver and the partition primitives, not a guarantee of the
matching role itself. A fair queue driver plus distinct queue
names per traffic class produces fair scheduling; a single shared
queue produces best-effort first-claim wins. This split keeps
fairness policy out of the engine.

## Operator-visible matching state

Operators and automation must be able to answer "is the matching
layer healthy" without reading task rows directly:

- `Workflow\V2\Support\OperatorMetrics::snapshot()` returns a
  `tasks` block with `open`, `ready`, `ready_due`, `delayed`,
  `leased`, `dispatch_failed`, `claim_failed`,
  `dispatch_overdue`, `lease_expired`, and `unhealthy` counts; a
  `backlog` block with `runnable_tasks`, `delayed_tasks`,
  `leased_tasks`, `retrying_activities`, `unhealthy_tasks`,
  `claim_failed_runs`, and `compatibility_blocked_runs`; and a
  `workers` block with the Phase 2 compatibility roll-up.
- `Workflow\V2\Support\OperatorQueueVisibility::forNamespace()` /
  `::forQueue()` returns per-partition queue depth, leased depth,
  poller heartbeats, and stale-worker detection.
- The standalone server exposes the metrics snapshot at
  `POST /api/system/metrics`.

Guarantees:

- Ready depth, leased depth, and dispatch-failed depth are visible
  per partition. Operators MUST be able to identify a starved
  partition (large `ready` with zero `leased`) without reading
  task rows directly.
- Stuck-claim conditions are visible. `claim_failed_runs` and
  `compatibility_blocked_runs` separate the operationally normal
  "no compatible worker is registered yet" case from the
  capability-mismatch case.
- Dispatch errors do not silently disappear. A row with a
  populated `last_dispatch_error` is reported through
  `tasks.dispatch_failed` and remains discoverable to pollers, so
  a transport outage does not drop work.

## Coupling boundaries with durable history

The matching role today writes to several adjacent surfaces inside
the claim transaction:

- `RunSummaryProjector::project()` is invoked on successful and
  failed claims to keep the visibility table fresh.
- `LifecycleEventDispatcher::activityStarted()` fires from the
  activity claim path so external subscribers see lifecycle
  transitions.
- `ActivityStarted` history is recorded in the same transaction
  as the activity claim.

These couplings are correct as semantics — every claim must be
immediately visible to operators and immediately reflected in
history. They are the seam where Phase 4 will split the
control-plane and execution-plane roles. The contract guarantees:

- Visibility (`RunSummaryProjector`) and lifecycle dispatch
  remain synchronously coupled to claim outcomes from the worker's
  point of view, even after the role moves out of process. A
  worker that observes a successful claim MUST be able to read
  the projection for the run immediately afterwards.
- History event recording for `ActivityStarted` remains
  exactly-once per `activity_attempt_id` per the Phase 1 contract,
  whether the recorder runs in-process or through a Phase 4
  dedicated history writer.
- The matching role does not invent new typed history events.
  Any history-event addition follows the
  `docs/api-stability.md` change process.

## Test strategy alignment

- `tests/Feature/V2/V2TaskDispatchTest.php` exercises the
  publication contract for both the after-commit deferral and the
  failure-recording path.
- `tests/Feature/DispatchWorkflowInTransactionTest.php` exercises
  dispatch within an outer transaction.
- `tests/Feature/V2/V2OperatorQueueVisibilityTest.php` and
  `tests/Feature/V2/V2OperatorMetricsTest.php` cover the operator
  surfaces that expose ready depth, leased depth, and partition
  state.
- `tests/Feature/V2/V2CompatibilityWorkflowTest.php` exercises the
  Phase 2 claim-time compatibility enforcement that Phase 3 must
  preserve.
- This document is pinned by
  `tests/Unit/V2/TaskMatchingDocumentationTest.php`. A change that
  renames, removes, or narrows any named guarantee (the matching
  role's three deployment shapes, the ready-task discovery query
  shape, the claim reason codes, the wake snapshot/changed
  contract, the partition primitives, or the lease-expiry-driven
  backpressure model) must update the pinning test and this
  document in the same change so the contract does not drift
  silently.

## What this contract does not yet guarantee

The following are explicitly deferred to later roadmap phases and
must not be assumed:

- Per-worker concurrency caps are advertised but not enforced at
  claim time. Enforcement belongs to Phase 6 rollout
  safety, where it can land alongside the broader coordination
  health story.
- A dedicated out-of-process matching service is not provided. The
  in-worker library shape and the in-server HTTP shape are the
  two supported deployment shapes today; a future Phase 4 service
  shape will preserve the contract above without breaking either.
- Cache independence for wake notification is deferred to Phase 5.
  The current cache-backed wake store is the explicit seam Phase 5
  will replace.
- Priority queues, weighted fair scheduling, and per-tenant
  isolation primitives are intentionally not part of this
  contract. They are policy choices a host implements through
  distinct queue and connection names plus its own queue driver
  configuration.
- Cross-cluster routing (sending a task from one workflow database
  to a worker pool in another) is outside the contract.

## Changing this contract

A change to any named guarantee in this document is a protocol-level
change for the purposes of `docs/api-stability.md` and downstream
SDKs. Reviewers should treat unmotivated changes to the language
above as breaking changes and require explicit cross-SDK
coordination before merge. The Phase 3 roadmap owns updates to
this contract; Phase 4, Phase 5, and Phase 6 must extend the
contract rather than silently redefine it.
