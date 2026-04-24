# Workflow V2 Operational Liveness and Transport Repair Contract

This document freezes the v2 contract for how the system keeps runs
moving: how ready work is delivered to workers, how leases expire
and get redelivered, how work that was committed but never enqueued
is repaired, how every non-terminal run exposes a durable next-resume
source, how operators see stuck conditions, and how worker-loss
recovery stays inside the durable state layer instead of rewriting
run status. It is the reference cited by product docs, CLI reasoning,
Waterline diagnostics, server deployment guidance, cloud capacity
planning, and test coverage so the whole fleet speaks one language
about queue delivery, lease recovery, repair cadence, heartbeat
renewal, and ingress serialization.

The guarantees below apply to the `durable-workflow/workflow` package
at v2, to the standalone `durable-workflow/server` that embeds it,
and to every host that embeds the package directly or talks to the
server over HTTP. A change to any named guarantee is a protocol-level
change and must be reviewed as such, even if the class that
implements it is `@internal`.

This contract builds on the semantics frozen in
`docs/architecture/execution-guarantees.md` (Phase 1), the routing
guarantees frozen in `docs/architecture/worker-compatibility.md`
(Phase 2), the matching and dispatch guarantees frozen in
`docs/architecture/task-matching.md` (Phase 3), the role-split
contract frozen in `docs/architecture/control-plane-split.md`
(Phase 4), the cache-independence contract frozen in
`docs/architecture/scheduler-correctness.md` (Phase 5), and the
rollout-safety envelope frozen in
`docs/architecture/rollout-safety.md` (Phase 6). Duplicate
execution, redelivery, compatibility pinning, matching, the six
named roles, the correctness substrate, and the rollout-safety
envelope keep the language they have there; this document adds the
language for the operational liveness path so the machinery that
keeps runs alive stays observable, bounded, and reversible.

## Scope

The contract covers:

- **bootstrap** — how a start command materialises an instance, a
  run, and a first ready task without relying on a sweeper or a
  second daemon, and the transactional rules that make that
  materialisation durable.
- **transport jobs** — the shape of the queue payload a worker
  consumes to claim a task, including what the payload carries, what
  it MUST NOT carry, and which authority loads the durable row under
  a lease.
- **lease management** — the authorities and durations that govern
  workflow-task leases, activity-attempt leases, and claim
  transitions, and the rule that lease state is a projection of
  durable rows rather than in-memory worker state.
- **redelivery vs repair** — the two distinct flows for recovering
  stuck work: redelivery of a leased task whose lease expired, and
  repair of a committed-but-never-enqueued task or run.
- **repair cadence** — the bounded backoff, scan limit, loop
  throttle, and idempotent dedupe rules that govern the repair path
  so an automated recovery pass cannot duplicate work or overwhelm
  the queue.
- **heartbeats** — the durable effect of an activity heartbeat on
  attempt lease renewal and heartbeat state, and the rule that a
  heartbeat never splits one attempt into two.
- **durable next-resume source** — the rule that every non-terminal
  run projects a single authoritative resume path through the run
  summary so operators and repair passes agree on what comes next.
- **worker-loss recovery** — how a worker crash, redeploy, or drain
  resolves through lease expiry and reassignment, never through
  run-status mutation.
- **sweeper scope** — the narrow, thin-transport-repair envelope of
  any optional sweeper: it dispatches durable tasks, it never
  interprets workflow state, and it never fabricates history.
- **compatibility preservation** — the rule that repair-driven
  redelivery preserves the task's compatibility markers so the
  originally-routed worker class remains the only eligible claimer.
- **ingress serialization** — the run-level lease and append-domain
  rules that make every mutating ingress against a run a single
  ordered sequence.
- **stuck-state observability** — the machine-readable signals,
  operator metrics, and health check names that make every stuck
  condition visible without log archaeology.
- **config surface** — the `DW_V2_TASK_REPAIR_*` environment
  variables that tune redispatch backoff, scan limit, loop throttle,
  and failure backoff maximum.

It does not cover:

- the Phase 1 exactly-once-at-the-durable-state-layer guarantee,
  which is frozen in `docs/architecture/execution-guarantees.md`.
  Operational liveness re-enqueues work; it never duplicates typed
  history rows.
- the Phase 2 single-step compatibility window, which is frozen in
  `docs/architecture/worker-compatibility.md`. Operational liveness
  preserves compatibility markers; it does not redefine them.
- the Phase 3 matching decision, which is frozen in
  `docs/architecture/task-matching.md`. Operational liveness
  re-enqueues tasks that have already matched; it does not re-run
  the matching decision on repair.
- the Phase 4 role split, which is frozen in
  `docs/architecture/control-plane-split.md`. Operational liveness
  is a concern of the matching and dispatch roles; it does not move
  authority between roles.
- the Phase 5 cache-independence substrate, which is frozen in
  `docs/architecture/scheduler-correctness.md`. Operational liveness
  never reintroduces cache as a correctness dependency; the durable
  task and run tables are the authority, and cache is acceleration
  only.
- the Phase 6 rollout-safety envelope, which is frozen in
  `docs/architecture/rollout-safety.md`. Operational liveness
  inherits the frozen health check names and metric keys defined
  there; Phase 6 owns the rollout-safety envelope and Phase 7 owns
  the liveness-and-transport substrate underneath it.
- host-level queue backend choices (Redis vs SQS vs database
  queues). The liveness contract is what the engine requires of any
  queue backend; it is not a recipe for configuring a specific one.
- replacement of the HTTP worker protocol or SQL persistence model.
  Operational liveness is a coordination contract, not an engine
  rewrite.
- scheduler leader election across replicas, which remains deferred
  to a follow-on roadmap issue.

## Terminology

- **Bootstrap** — the single-transaction materialisation of an
  instance row, a first run row, a first ready task row, and the
  first dispatched transport job that happens when an external
  `start` command is accepted. Bootstrap is not a sweeper pass; it
  is part of the start command itself.
- **Transport job** — the queue-framework payload that carries a
  durable task id and is consumed by `RunWorkflowTask`,
  `RunActivityTask`, or `RunTimerTask`. A transport job carries no
  workflow state of its own; every decision it makes loads from the
  durable row.
- **Lease** — a durable claim on a task row or an activity-attempt
  row that binds the row to a specific worker for a bounded time.
  Leases are projections of `lease_owner` and `lease_expires_at`
  columns; they are not in-memory worker state.
- **Lease expiry** — the wall-clock point at which
  `lease_expires_at` has passed and the lease is eligible for
  redelivery. `TaskRepairPolicy::leaseExpired()` is the authority on
  whether a task lease has expired.
- **Redelivery** — a repair-path decision to re-enqueue a task whose
  lease expired or whose previous dispatch failed. Redelivery loads
  the same durable task row under a fresh lease; it does not
  duplicate history rows.
- **Repair** — a repair-path decision to re-materialise the next
  durable task for a run whose committed state indicates a task
  should exist but does not. Repair is distinct from redelivery:
  redelivery operates on an existing task row, repair creates a
  missing one.
- **Repair cadence** — the bounded backoff, throttle, and scan-limit
  knobs that govern how aggressively the repair path runs.
  `TaskRepairPolicy::snapshot()` exposes every knob as a single
  durable snapshot.
- **Repair attention flag** — a column on a task row or a
  run-summary row that tells the repair path this row is a
  candidate for its next pass. Attention flags are durable and
  reversible; a healthy pass clears them without deleting history.
- **Durable resume source** — the single authoritative next-step
  for a non-terminal run, projected as the `liveness_state`,
  `next_task_id`, `resume_source_kind`, and `resume_source_id`
  columns on `workflow_run_summaries`. Every non-terminal run has
  exactly one durable resume source.
- **Ingress** — any caller that mutates run state: a worker task
  ack, an external signal, an external update, a schedule trigger,
  a cancel or terminate command, or a repair redispatch. Every
  ingress serialises through the run-level lease or the append
  domain.
- **Run-level lease** — the row-level lock taken on a
  `workflow_runs` row (via `lockForUpdate()`) to serialise
  mutating ingress. Run-level leases are held for the duration of
  the mutating transaction only; they are not long-lived daemons.
- **Append domain** — the durable append-only history stream for a
  run. Operations that write through the append domain serialise
  against the run's last known sequence number; operations that
  mutate projection rows serialise through the run-level lease.
- **Sweeper** — the optional background pass that re-enqueues
  stuck transport jobs. The sweeper is thin transport repair only;
  it never interprets workflow code.
- **Compatibility markers** — the workflow fingerprint, required
  compatibility string, and backend capability markers that Phase 2
  pins to a task at dispatch time. Operational liveness preserves
  these markers through redelivery and repair.
- **Heartbeat renewal** — the durable effect of a worker-originated
  activity heartbeat on `lease_expires_at` and `heartbeat_state`.
  Heartbeat renewal never creates a new attempt; it mirrors progress
  onto the existing attempt row.

## Bootstrap: the start command owns the first task

A v2 `start` command is the only supported way to bring a new
workflow run into the system. It MUST materialise the instance
row, the first run row, the first ready task row, and the first
dispatched transport job in a single durable sequence with no
reliance on a second daemon or a sweeper pass.

Rules:

- The start command writes the `workflow_instances`, `workflow_runs`,
  and `workflow_tasks` rows atomically under the same database
  transaction. The projection-producing `RunSummaryProjector::project()`
  call is issued inside the same transaction so the run summary's
  `liveness_state` and `next_task_id` are visible before any
  consumer reads the run.
- The start command dispatches the first transport job via
  `TaskDispatcher::dispatch()`. `TaskDispatcher` uses
  `DB::afterCommit()` to defer the queue publish so the transport
  job cannot be delivered before the durable row is visible. A
  pre-commit crash leaves no orphaned queue job; a post-commit crash
  leaves a committed task that the repair path can dispatch.
- A normal queue worker is sufficient to consume the first transport
  job. No second daemon is required for the start path to make
  progress. The sweeper is an optional accelerator only; a deployment
  that runs no sweeper is still correct because
  `TaskRepair::repairRun()` runs on demand through the control-plane
  `repair()` contract and through the admin HTTP surface.
- A start command that fails mid-transaction rolls back every row
  it wrote; no partial instance, run, or task survives. The caller
  sees the failure through the standard start-command reject path.
- A start command MUST NOT write history through a side channel. The
  first `WorkflowStarted` event is written through the same
  `WorkflowExecutor` commit path that subsequent tasks use, which
  keeps Phase 1's exactly-once-at-commit guarantee intact.

## Transport jobs carry durable ids only

Transport jobs are the queue-framework payload that delivers a task
id to a worker. They MUST NOT carry the task row itself, the run
summary, the workflow snapshot, or the authoring-layer closure. The
authoritative source of every decision a worker makes is the
durable row loaded under the fresh lease.

Frozen transport job classes:

- `Workflow\V2\Jobs\RunWorkflowTask` — consumes a workflow task id.
- `Workflow\V2\Jobs\RunActivityTask` — consumes an activity task id.
- `Workflow\V2\Jobs\RunTimerTask` — consumes a timer task id.

Rules:

- Every transport job constructor accepts a single `task_id` string
  argument and nothing else. Adding a field to a transport job
  payload is a protocol change.
- On consumption, the transport job loads the `workflow_tasks` row
  by `task_id`. If the row is missing, the transport job ACKs the
  queue message and records a dispatch error; it MUST NOT fabricate
  the row.
- A transport job MUST claim the task row through
  `ActivityTaskClaimer::claimDetailed()` (for activity tasks), or
  the workflow-task equivalent bound by `DefaultWorkflowTaskBridge`,
  before executing any workflow or activity code. Claim sets
  `status=Leased` and `lease_expires_at` on the row; the consumer
  MUST NOT execute before the claim commits.
- Claim returns a structured decision, not a boolean. The reason
  codes `task_not_found`, `task_not_activity`, `task_not_ready`,
  `task_not_due`, `activity_execution_missing`,
  `activity_execution_not_found`, `workflow_run_missing`,
  `backend_unsupported`, and `compatibility_unsupported` are
  frozen; adding a reason is a protocol change.
- A transport job whose claim reports
  `compatibility_unsupported` or `backend_unsupported` MUST return
  the task to `Ready` without consuming it, so the next eligible
  worker can claim it. Dropping the task on an incompatible claim
  is a contract violation.

## Lease management

Leases are projections of durable columns. They are never
in-memory worker state.

Authorities:

- `TaskRepairPolicy::leaseExpired(WorkflowTask, ?now): bool` is the
  authority on whether a workflow or activity task lease has
  expired.
- `ActivityLease::expiresAt()` is the authority on activity attempt
  lease duration. `ActivityLease::DURATION_MINUTES` is frozen at 5
  minutes; a change is a protocol change.
- `ActivityTaskClaimer::claimDetailed()` is the authority on every
  activity task claim transition; it sets `lease_owner`,
  `lease_expires_at`, `status=Leased`, and creates the
  `ActivityAttempt` row atomically.

Rules:

- A lease is authoritative only if `lease_expires_at` is strictly in
  the future. A task whose `lease_expires_at` is in the past is
  eligible for redelivery regardless of what the last worker
  reported.
- A lease transition is a single-row transactional update. A worker
  that observes `status != Leased` or a different `lease_owner`
  after its own claim MUST surrender the task; concurrent writes
  never split ownership.
- A lease is never renewed implicitly by the repair path. The only
  supported renewal is an activity heartbeat from the owning worker,
  which extends `lease_expires_at` without changing `lease_owner`.
- A claim that fails the Phase 2 compatibility gate records
  `last_claim_failed_at` and `last_claim_error` without taking the
  lease. The row stays `Ready` so a compatible worker can claim it.
- A lease released by the worker through the control-plane
  `release()` path transitions the task back to `Ready` with a fresh
  `available_at`; the task is not deleted and history is not
  rewritten.

## Redelivery vs repair are two distinct flows

Recovering stuck work is two separate paths, not one. The engine
treats them as distinct because the recovery invariants differ.

Redelivery:

- Applies to a task row whose lease expired or whose last dispatch
  failed and whose `status` is `Leased` or `Ready` with a failed
  dispatch flag.
- Authority: `TaskRepair::recoverExistingTask()` loads the row,
  applies `TaskRepairPolicy::leaseExpired()` or
  `TaskRepairPolicy::readyTaskNeedsRedispatch()`, and re-enqueues
  via `TaskDispatcher::dispatch()`.
- Dedupe: redelivery MUST NOT write a new task row. The existing
  `task_id` is the idempotency surface; the transport job may
  observe the same id more than once and the worker's own
  claim-transition serialises ownership.
- Compatibility: redelivery preserves the task's Phase 2
  compatibility markers so the originally-routed worker class
  remains the only eligible claimer.

Repair:

- Applies to a run whose `workflow_run_summaries` row reports
  `liveness_state = 'repair_needed'` and whose projected next
  action has no corresponding `workflow_tasks` row.
- Authority: `TaskRepair::repairRun()` loads the run stub, applies
  the command context, and delegates to `attemptRepair()` through
  the workflow engine, which re-creates the missing task row on
  the same run.
- Dedupe: repair MUST be safe against concurrent repair passes. The
  repair path takes a run-level lock via `lockForUpdate()` on the
  `workflow_runs` row; a second repair pass observes the newly
  created task and exits without writing a second one.
- Repair never fabricates history. It creates a task row, not a
  history event. The typed history row for the task is written when
  the task is claimed and commits through the standard decision
  batch path.

The repair path MUST be explicit about why it cannot act. The
`RepairBlockedReason::catalog()` enumerates the five frozen reason
codes that a caller may observe:

- `unsupported_history` — the run's history contains events the
  current engine build cannot interpret; the operator must roll
  forward a compatible worker or manually resolve.
- `waiting_for_compatible_worker` — a task exists, but no live
  worker advertises the required compatibility marker.
- `selected_run_not_current` — the caller selected a historical
  run, not the currently open one; repair targets the current run
  only.
- `run_closed` — the run is already terminal; repair is a no-op.
- `repair_not_needed` — the run has a healthy durable resume source
  and a valid projected next task; no action.

Adding a blocked reason is a protocol change; deleting one is a
protocol change.

## Repair cadence and bounded backoff

The repair path is bounded. An automated recovery pass cannot
overwhelm the queue or duplicate work, because every knob is a
durable config value with a documented default.

Authority: `TaskRepairPolicy::snapshot()` exposes the full cadence
envelope:

| Knob                               | Env var                                             | Default |
| ---------------------------------- | --------------------------------------------------- | ------- |
| `redispatch_after_seconds`         | `DW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS`        | 3       |
| `loop_throttle_seconds`            | `DW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS`           | 5       |
| `scan_limit`                       | `DW_V2_TASK_REPAIR_SCAN_LIMIT`                      | 25      |
| `failure_backoff_max_seconds`      | `DW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS`     | 60      |
| `SCAN_STRATEGY`                    | n/a                                                 | `scope_fair_round_robin` |
| `FAILURE_BACKOFF_STRATEGY`         | n/a                                                 | `exponential_by_repair_count` |

Rules:

- `redispatch_after_seconds` is the minimum observed gap between a
  task transitioning to `Ready` and the repair path treating it as
  a redispatch candidate. Below this gap the repair path leaves the
  task alone so the normal queue worker has a chance to pick it up.
- `loop_throttle_seconds` is the minimum gap between two repair
  passes. A caller that invokes repair more aggressively MUST wait;
  the repair path enforces the throttle itself.
- `scan_limit` is the upper bound on the number of candidate task
  ids returned per pass by `TaskRepairCandidates::taskIds()`. A
  caller that needs a larger sweep issues multiple bounded passes;
  the scan limit is not advisory.
- `failure_backoff_max_seconds` is the upper bound on the
  exponential backoff that `FAILURE_BACKOFF_STRATEGY` applies to a
  repeatedly-failing repair candidate. The backoff is monotonic in
  `repair_count`; a task that has failed repair many times backs off
  to at most this bound.
- `SCAN_STRATEGY = scope_fair_round_robin` guarantees that a repair
  pass does not starve one namespace, instance, or run scope. A
  single noisy run cannot monopolise the scan.
- The scan strategies and the backoff strategy are named string
  constants, not magic numbers; a change to either is a protocol
  change.

## Heartbeats renew the owning lease

Activity heartbeats are the only mechanism for a live worker to
extend its own lease. They MUST renew the owning attempt lease and
MUST NOT split one attempt into two.

Rules:

- A heartbeat call mirrors progress onto the activity execution row
  and updates `lease_expires_at` on the attempt row using
  `ActivityLease::expiresAt()` as the duration authority.
- A heartbeat from a worker that does not own the lease is rejected;
  the worker receives a structured response indicating lease loss.
- A heartbeat never creates a new `activity_attempts` row. A worker
  that loses its lease and heartbeats after the gap MUST observe
  `recorded=false` from `ActivityOutcomeRecorder` and surrender.
- Heartbeat progress is validated through `HeartbeatProgress` so the
  shape recorded to the durable row is uniform across workers.
- Heartbeat cadence is a worker-side concern; the engine does not
  require a specific heartbeat interval. A worker that falls silent
  loses its lease after `ActivityLease::DURATION_MINUTES` minutes.

## Every non-terminal run has a durable next-resume source

A run is only resumable if its `workflow_run_summaries` row names
exactly one next step. The projector is the authority.

Authority: `RunSummaryProjector::project(WorkflowRun): WorkflowRunSummary`
derives `liveness_state`, `next_task_id`, `next_task_type`,
`next_task_status`, `next_task_lease_expires_at`, `next_task_at`,
`wait_kind`, `wait_reason`, `wait_started_at`, `wait_deadline_at`,
`open_wait_id`, `resume_source_kind`, and `resume_source_id` for
every non-terminal run. `RunSummaryProjector::SCHEMA_VERSION` is
frozen at 1; a schema change requires a migration and a new
version.

Frozen `liveness_state` values:

- `closed` — the run is terminal (Completed, Failed, Cancelled,
  Terminated).
- `repair_needed` — the run is non-terminal but no durable
  next-resume source is currently available; the repair path MUST
  act.
- `workflow_replay_blocked` — the run's history cannot be replayed
  by the current engine build; a compatible worker or a manual
  repair is required.
- `activity_running_without_task` — an activity attempt is running
  with no workflow task enqueued; the task is deferred to avoid
  duplication, and the attempt completion re-enqueues the next
  workflow task.
- `waiting_for_condition` — an open condition wait with no
  imminent task.
- `waiting_for_signal` — an open signal wait with no imminent task.
- `waiting_for_child` — an open child-workflow wait.
- `activity_task_waiting_for_compatible_worker` — an activity task
  is leased or ready but no live worker advertises the required
  compatibility marker.
- `activity_task_claim_failed` — an activity task has a recorded
  claim failure.
- `activity_task_leased` — an activity task is leased to a worker.
- `activity_task_ready` — an activity task is ready to be claimed.
- `workflow_task_waiting_for_compatible_worker` — a workflow task
  awaits a compatible worker.
- `workflow_task_claim_failed` — a workflow task has a recorded
  claim failure.
- `workflow_task_leased` — a workflow task is leased.
- `workflow_task_ready` — a workflow task is ready.
- `timer_task_leased` — a timer task is leased.
- `timer_scheduled` — a timer task is scheduled to fire.

A `liveness_state` that is not in this enumeration is a protocol
violation; adding a value requires updating this contract and the
pinning test.

Rules:

- Every non-terminal run MUST project exactly one `liveness_state`
  and at most one `next_task_id`. A run that projects no
  next-resume source is a bug, not a state.
- `resume_source_kind` and `resume_source_id` MUST be consistent
  with `liveness_state`. A run whose `liveness_state` is
  `waiting_for_signal` MUST name the signal wait as its resume
  source; a run whose `liveness_state` is `workflow_task_ready`
  MUST name the workflow task id.
- The projector is idempotent. Re-running it on the same run state
  produces the same durable row. See the projector-idempotency
  contract under `docs/architecture/scheduler-correctness.md` for
  the shared idempotency rules.
- A run whose projection lags behind its durable state reports
  `needs_rebuild` through the `run_summary_projection` health check
  and through the metric surface, so an operator can see the lag
  without log archaeology.

## Worker-loss recovery is lease-driven

A worker crash, redeploy, or drain MUST resolve through lease
expiry and reassignment of the durable task or attempt row. It MUST
NOT mutate run status.

Rules:

- A lost worker's tasks become eligible for redelivery when
  `lease_expires_at` passes. The repair path reassigns them to a
  fresh lease via `TaskRepair::recoverExistingTask()`. The run's
  `status` column is untouched.
- A lost worker's activity attempts follow the same path. The
  attempt lease expires and the attempt remains Running until the
  next worker reports an outcome. A replacement worker claims the
  same `activity_execution_id` under a new
  `activity_attempt_id`, subject to Phase 1's at-least-once
  contract.
- The server's `Durable-Workflow-Server\Workflows\WorkflowTaskLeaseRecovery`
  is the server-side counterpart of this rule; a task whose lease
  expires on the server is recovered through the same bounded path.
- Worker-loss recovery MUST preserve the run's current
  `liveness_state` semantics. If the projection reported
  `activity_task_leased` before the loss, a reassigned claim
  re-observes the same `activity_task_leased` or transitions to
  `activity_task_claim_failed` with a recorded reason; it never
  re-derives from scratch.
- An operator-issued `cancel`, `terminate`, or `archive` may
  transition run status through the standard command path. Those
  transitions are ingress and serialise through the run-level lease;
  they are not a worker-loss recovery mechanism.

## Sweeper scope is thin transport repair only

Any optional background sweeper MUST be a thin transport repair
pass over durable rows. It MUST NOT interpret workflow code, it
MUST NOT fabricate history, and it MUST NOT bypass the run-level
lease.

Rules:

- A sweeper's only action is to call `TaskRepair::repairRun()` or
  `TaskRepair::recoverExistingTask()` on candidate rows returned by
  `TaskRepairCandidates::taskIds()` and
  `TaskRepairCandidates::runIds()`. A sweeper that touches any other
  row is out of scope.
- A sweeper MUST NOT run the workflow authoring layer. Replay is a
  worker responsibility; the sweeper never instantiates a
  `WorkflowExecutor`.
- A sweeper MUST respect `TaskRepairPolicy::loopThrottleSeconds()`.
  A second sweeper that scans inside the throttle window exits
  without writing.
- A deployment that runs no sweeper at all is still correct. The
  control-plane `repair()` contract and the admin HTTP
  `/api/system/repair/pass` route provide on-demand repair for every
  use case the sweeper would handle automatically.

## Repair preserves compatibility markers

A repair-driven redispatch MUST preserve the Phase 2 compatibility
markers on the durable task row so the originally-routed worker
class remains the only eligible claimer.

Rules:

- `WorkflowDefinitionFingerprint`, `required_compatibility`, and
  `TaskCompatibility`-managed backend capability markers on the
  task row are copied forward by every redispatch. The repair path
  never rewrites them.
- A redispatched task MUST NOT suddenly become eligible for a
  different compatibility scope. If the required marker has no
  live worker, the task stays `Ready` with
  `waiting_for_compatible_worker` on its run summary until a
  compatible worker advertises a heartbeat.
- The matching role's `ActivityTaskClaimer::claimDetailed()` still
  runs the Phase 2 gate on every claim. A redispatched claim that
  no longer matches records `compatibility_unsupported` the same
  way a fresh claim would.

## Ingress serialization

Every run-mutating ingress serialises through the same run-level
lease or the append domain. There is no second path.

Rules:

- A run-mutating call takes `lockForUpdate()` on the
  `workflow_runs` row before reading, deciding, and writing. The
  lock is scoped to the mutating transaction only; it is not a
  long-lived daemon hold.
- Non-mutating ingress (queries, describes, list calls) does not
  need the lock. Those calls read through projections and MUST
  tolerate a brief skew between the durable run row and the
  `workflow_run_summaries` projection.
- The append domain is the durable history write path. Every
  decision batch, external signal, external update, cancel,
  terminate, and archive appends through this domain so the
  exactly-once-at-commit guarantee in Phase 1 applies.
- A repair-driven redispatch serialises through the run-level lease
  for the task row's run. Two repair passes on the same run
  serialise against each other; two repair passes on different runs
  run concurrently with no contention.
- A bulk operation that touches multiple runs MUST take each
  run-level lock in a stable order (by run id) to avoid deadlock.

## Stuck-state observability

A stuck condition is never silent. Every durable signal that
indicates stuck work is counted by `OperatorMetrics::snapshot()`,
checked by `HealthCheck::snapshot()`, or both.

Frozen metric keys on `OperatorMetrics::snapshot()` relevant to
liveness (sub-keys under the named groups):

- `runs.repair_needed` — open runs whose `liveness_state` is
  `repair_needed`.
- `runs.claim_failed` — open runs whose next task has a recorded
  claim failure.
- `runs.compatibility_blocked` — open runs whose next task awaits a
  compatible worker.
- `tasks.ready` — tasks with `status = Ready`.
- `tasks.ready_due` — ready tasks whose `available_at` is in the
  past.
- `tasks.delayed` — ready tasks whose `available_at` is in the
  future.
- `tasks.leased` — tasks with `status = Leased` and a live lease.
- `tasks.dispatch_failed` — tasks that failed transport dispatch.
- `tasks.claim_failed` — tasks that failed claim.
- `tasks.dispatch_overdue` — tasks that transitioned to ready but
  were never dispatched within the policy window.
- `tasks.lease_expired` — tasks with `lease_expires_at` in the past
  whose status is still `Leased`.
- `tasks.unhealthy` — aggregate count across the above unhealthy
  sub-buckets.
- `backlog.repair_needed_runs` — snapshot of the repair-needed
  backlog.
- `backlog.claim_failed_runs` — snapshot of the claim-failed
  backlog.
- `backlog.compatibility_blocked_runs` — snapshot of the
  compatibility-blocked backlog.
- `repair.missing_task_candidates` — candidates for repair that
  have no task row.
- `repair.selected_missing_task_candidates` — candidates narrowed
  by `selected_run_id` scope.
- `repair.oldest_missing_run_started_at` — the oldest start time
  among repair-needed runs.
- `repair.max_missing_run_age_ms` — the age of the oldest
  repair-needed run.
- `activities.open`, `activities.pending`, `activities.running`,
  `activities.retrying`, `activities.failed_attempts` — activity
  state visibility.
- `workers.required_compatibility` — the required compatibility
  marker the fleet currently needs to claim ready tasks.
- `workers.active_workers` — count of workers with live heartbeats.
- `workers.active_workers_supporting_required` — count of workers
  advertising the required compatibility marker.
- `repair_policy.redispatch_after_seconds`,
  `repair_policy.loop_throttle_seconds`,
  `repair_policy.scan_limit`,
  `repair_policy.failure_backoff_max_seconds` — policy knobs
  surfaced as metrics so a dashboard can read them without reading
  config.

The eight frozen health check names under `HealthCheck::snapshot()`:

- `backend_capabilities`
- `run_summary_projection`
- `selected_run_projections`
- `history_retention_invariant`
- `command_contract_snapshots`
- `task_transport`
- `durable_resume_paths`
- `worker_compatibility`

Rules:

- Every metric key and every health check name is frozen. Renaming
  or deleting one is a protocol change.
- `task_transport` is the authoritative check for transport-layer
  liveness: it reports unhealthy when `tasks.dispatch_failed`,
  `tasks.dispatch_overdue`, `tasks.claim_failed`, or
  `tasks.lease_expired` are non-zero.
- `durable_resume_paths` is the authoritative check for
  next-resume-source completeness: it reports unhealthy when any
  open run has no projected `next_task_id` or a missing
  `resume_source_kind`.
- `worker_compatibility` is the authoritative check that every
  required compatibility marker has at least one live worker. A
  marker with zero supporting workers fails the check.
- Waterline MUST render every frozen metric key and every frozen
  check status somewhere in its operator UI. The Waterline surface
  list is frozen in `docs/architecture/rollout-safety.md`; this
  contract does not re-pin them.
- Per-queue liveness visibility reads through
  `OperatorQueueVisibility::forNamespace()` and
  `OperatorQueueVisibility::forQueue()`. The partition shape
  (connection, queue, compatibility) is frozen in Phase 6; this
  contract inherits it and reuses it for stuck-task scoping.

## Admin HTTP surface for liveness

The server exposes the liveness repair surface through the admin
HTTP routes listed in `docs/architecture/rollout-safety.md`. The
two routes directly load-bearing for operational liveness are:

- `/api/system/repair/pass` — runs one bounded repair pass.
  Body: JSON-encoded `scan_limit`, `namespace`, optional
  `instance_id`. Authority: `TaskRepair::repairRun()` and
  `TaskRepair::recoverExistingTask()` under the policy envelope.
- `/api/system/activity-timeouts/pass` — runs one bounded pass
  over activity lease expiry and timeout resolution. Authority:
  `ActivityLease::expiresAt()` and the attempt-level recovery path.

Rules:

- Both routes are bounded per call by `TaskRepairPolicy::scanLimit()`
  and by `TaskRepairPolicy::loopThrottleSeconds()`. A caller that
  hits the route more frequently than the throttle sees a no-op
  response rather than a flood of repairs.
- Both routes MUST be idempotent at the task-id level; a second
  call during the same repair window is a no-op because the run-level
  lock serialises against the first.
- The response MUST report the number of rows touched, the number
  of rows skipped, and the reason (throttled, no candidates,
  `selected_run_not_current`, etc.) so a sweeper that wraps the
  route can log progress without reading logs on the server.

## Config surface for operational liveness

Operators control operational liveness through a small set of
frozen `DW_V2_TASK_REPAIR_*` variables and the Phase 6 rollout-
safety variables that also influence liveness.

Frozen variables:

- `DW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS` — minimum gap before
  the repair path redispatches a ready task. Default 3.
- `DW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS` — minimum gap between
  repair passes. Default 5.
- `DW_V2_TASK_REPAIR_SCAN_LIMIT` — upper bound on candidate ids per
  pass. Default 25.
- `DW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS` — upper bound on
  the exponential backoff for repeatedly-failing repair candidates.
  Default 60.

The legacy `WORKFLOW_V2_TASK_REPAIR_*` names remain supported via
`Env::dw()`; the contract forbids silently dropping the legacy
names while a `DW_V2_*` equivalent exists. Rollout-safety variables
such as `DW_V2_TASK_DISPATCH_MODE` and
`DW_V2_PIN_TO_RECORDED_FINGERPRINT` retain their Phase 6
semantics.

Rules:

- Every variable has a documented default. A deployment that sets
  none of them runs under the documented defaults; the engine does
  not infer aggressive values from fleet size.
- A deployment that widens a knob above its policy maximum is
  clipped to the maximum, not silently run at the wider value. The
  snapshot reports the effective value.
- Config changes are observable through
  `OperatorMetrics::snapshot().repair_policy.*` so an operator can
  verify the policy the engine actually loaded without reading
  env vars from the host.

## Migration path

Rolling a deployment from lease-only recovery to the full
liveness-and-repair envelope is reversible and bounded.

1. **Audit transport delivery.** Confirm the queue worker is
   consuming `RunWorkflowTask`, `RunActivityTask`, and
   `RunTimerTask` without a second daemon. A deployment that has a
   second daemon MUST be able to turn it off without regressing
   throughput; operational liveness is sweeper-optional.
2. **Turn on projection reads.** Switch callers that read run
   status from `workflow_runs` directly to
   `workflow_run_summaries.liveness_state` and
   `workflow_run_summaries.next_task_id`. The projector is
   authoritative; direct reads against `workflow_runs` lose the
   durable resume source.
3. **Enable repair metrics and checks.** Expose
   `OperatorMetrics::snapshot()` and `HealthCheck::snapshot()`
   through the HTTP surface so a dashboard reads the frozen keys.
4. **Pin `DW_V2_TASK_REPAIR_*` values.** Move the four knobs out of
   default and into explicit config so changes are reviewable.
5. **Dry-run the admin repair routes.** Issue a
   `/api/system/repair/pass` with a small `scan_limit` on a
   staging namespace and confirm the bounded response.
6. **Decommission the optional sweeper if present.** A sweeper that
   does anything more than call the frozen `TaskRepair` entry
   points MUST be brought into scope or removed.

Every v2 migration named under this contract is reversible by the
standard Laravel `down()` path. A rollback from step 2 back to
step 1 MUST NOT lose history or repair attention flags.

## Tables and migrations

Operational liveness depends on durable columns added by the v2
schema.

- `workflow_tasks`
  (`src/migrations/2026_04_05_000103_create_workflow_tasks_table.php`)
  owns `leased_at`, `lease_owner`, `lease_expires_at`,
  `repair_count`, `repair_available_at`, `last_dispatch_attempt_at`,
  `last_dispatched_at`, `last_dispatch_error`,
  `last_claim_failed_at`, `last_claim_error`, and the
  `[status, available_at]` index that bounds the repair scan.
- `workflow_run_summaries`
  (`src/migrations/2026_04_05_000106_create_workflow_run_summaries_table.php`)
  owns `liveness_state`, `next_task_id`, `next_task_type`,
  `next_task_status`, `next_task_lease_expires_at`, `next_task_at`,
  `wait_kind`, `wait_reason`, `wait_started_at`, `wait_deadline_at`,
  `open_wait_id`, `resume_source_kind`, and `resume_source_id`.
- `activity_attempts`
  (`src/migrations/2026_04_08_000124_create_activity_attempts_table.php`)
  owns `lease_owner` and `lease_expires_at` for the attempt lease,
  and the attempt status enum (`Running`, `Completed`, `Failed`,
  `Expired`, `Cancelled`).
- `worker_compatibility_heartbeats`
  (`src/migrations/2026_04_08_000126_create_worker_compatibility_heartbeats_table.php`)
  owns the live heartbeat surface that `worker_compatibility`
  health check and `workers.active_workers_supporting_required`
  metric read.

Rules:

- Every migration named here is reversible by the standard Laravel
  `down()` path. A rollback drops the columns added on the way up
  without touching unrelated data.
- A column added to any of these tables MUST appear in both the
  `up()` and the `down()` of its migration; a migration that only
  has an `up()` is a contract violation.
- Schema changes are coordinated through the `ReadinessContract`
  check defined in Phase 6; an incompatible durable shape surfaces
  through the `v2_operator_surface_available` readiness key before
  it can break callers.

## Test strategy alignment

This contract is pinned by a dedicated unit test so that the
guarantees here are reviewed with the implementation.

- `tests/Unit/V2/OperationalLivenessDocumentationTest.php` asserts
  the presence of every heading, term, class, migration, env var,
  health check, claim reason code, liveness state, frozen metric
  key, and migration-path step documented above.
- Behaviour tests for redelivery, repair, heartbeat renewal, claim
  transitions, and admin HTTP routes live under
  `tests/Feature/V2/` and `tests/Unit/V2/` with class names
  including `TaskRepair`, `TaskRepairPolicy`, `TaskRepairCandidates`,
  `ActivityTaskClaimer`, `ActivityLease`, `TaskDispatcher`, and
  `RunSummaryProjector`.
- A change to any named guarantee requires both a doc update here
  and a test update. A change that drops a test assertion without
  updating the doc is a contract violation.

## What this contract does not yet guarantee

The following are intentionally out of scope for this phase of the
roadmap and are tracked as follow-on roadmap issues:

- **Scheduler leader election across replicas.** A multi-replica
  deployment still relies on Phase 5's database-backed
  coordination. Electing a single leader per schedule is a
  follow-on roadmap issue.
- **Cross-region coordination.** The liveness contract assumes a
  single primary database region. Cross-region failover and
  hand-off are out of scope.
- **Automatic migration rollback on lease-contract regressions.**
  The contract requires every migration to be reversible; it does
  not yet require the engine to auto-roll-back on a detected
  regression.
- **Client-side rollout tooling.** Helm overlays, blue/green
  templates, and canary scripts that consume this contract are
  out of scope. The contract describes the substrate; rollout
  tooling is a separate deployment concern.
- **Queue-backend-specific repair optimisations.** Redis, SQS,
  database queue, and Beanstalk each have different delivery
  semantics; this contract describes the engine's requirements,
  not per-backend tuning.

## Changing this contract

A change to any guarantee named here MUST ship alongside:

1. A matching change to
   `tests/Unit/V2/OperationalLivenessDocumentationTest.php` that
   adds, renames, or removes the assertion for the changed
   guarantee.
2. A matching change to the class, method, migration, or config
   variable cited so that the code matches the doc.
3. A matching change to `docs/architecture/rollout-safety.md` if
   the change also affects the rollout-safety envelope.
4. A matching change to `docs/architecture/execution-guarantees.md`
   if the change also affects duplicate-execution semantics.
5. An entry in the repository's public release notes that calls
   out the protocol impact.

This contract builds on Phase 1 execution guarantees,
Phase 2 worker compatibility, Phase 3 task matching,
Phase 4 control-plane split, Phase 5 scheduler
correctness, and Phase 6 rollout safety. Removing a
citation from that lineage without adding the explicit
re-derivation is a contract violation. Any future extension
(stuck-detector strategy changes, sweeper replacement, heartbeat
protocol changes) extends this contract rather than silently
redefining it.
