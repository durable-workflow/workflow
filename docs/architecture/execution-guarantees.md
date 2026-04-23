# Workflow V2 Execution Guarantees and Idempotency Contract

This document freezes the v2 contract for what can execute more than once,
what is replayed deterministically, what the durable state layer observes
exactly once, and what application authors are required to make
idempotent. It is the reference cited by the v2 docs, CLI reasoning,
Waterline diagnostics, and test coverage so the whole fleet speaks one
language about duplicate execution, retries, lease expiry, and
redelivery.

The guarantees below apply to the `durable-workflow/workflow` package at
v2 and to every host that embeds it or talks to it over the worker
protocol. A change to any named guarantee is a protocol-level change and
must be reviewed as such, even if the class that implements it is
`@internal`.

## Scope

The contract covers:

- **workflow tasks** — units of replay-driven workflow execution claimed
  by a workflow worker and acknowledged via a decision batch.
- **activity attempts** — external side-effecting work claimed by an
  activity worker and acknowledged via `complete` / `fail` / `heartbeat`.
- **external commands** — start, signal, update, cancel, terminate,
  archive, query, and schedule commands received from outside the engine
  over the CLI, server HTTP surface, workflow client, or cloud API.
- **durable messages** — the inbox/outbox primitives backing signals,
  updates, workflow-to-workflow messages, and human-input flows.
- **side effects** — `sideEffect(...)`, `uuid4`, `uuid7`, and
  `record_version_marker` calls recorded inline in history.
- **schedules** — the ScheduleTriggered events that launch scheduled
  workflow runs.

It does not cover in-process Laravel queue semantics for the host
application outside v2, application database transactions that the host
runs independently of a workflow task, or the behaviour of third-party
services that an activity calls.

## Terminology

- **At-least-once** means the framework may observe the same logical
  work more than once and the application author must tolerate it.
- **At-most-once** is not promised for any side-effecting operation.
  Activities, signals, updates, starts, and schedule triggers are never
  at-most-once.
- **Deterministic replay** means the engine re-runs the same authoring
  code to rebuild workflow state from history, producing the same
  decisions in the same order. Replay is not application re-execution
  and does not re-invoke external side effects.
- **Exactly-once at the durable state layer** means that a specific
  typed history row for a given identifier is written at most once and
  is the authoritative record consumed by projections, replayers,
  exporters, and operator tools.
- **Redelivery** means the transport requeued a task after lease expiry,
  worker crash, or repair, and a different worker may now claim the same
  task id or a newly repaired task for the same logical work.
- **Replay** means the engine re-reads history events and re-invokes
  workflow authoring code to reconstruct the workflow state machine. It
  does not re-invoke activity code or re-emit external side effects.

## Workflow task execution semantics

Workflow tasks are event-sourced decisions produced by the workflow
authoring layer and persisted as typed history events through the
`WorkflowExecutor`.

Guarantees:

- A workflow task may be claimed, rejected, leased, expired, redelivered,
  repaired, and retried. Any individual workflow task id may be observed
  more than once by a worker.
- The workflow authoring code inside a task is replay-driven. It may run
  many times across a run's lifetime. Authoring code must be
  deterministic with respect to history, must not depend on wall-clock
  time, must not perform IO, and must not mutate external state. See
  `constraints/workflow-constraints.md` for the full prohibited list.
- A decision batch commits atomically per task. The typed history
  events named in `docs/api-stability.md` are the durable record of that
  decision. When the commit is observed, those history rows are
  exactly-once at the durable state layer for the decision ids they
  carry (`workflow_command_id`, `activity_execution_id`,
  `activity_attempt_id`, `timer_id`, `signal_id`, `update_id`,
  `condition_wait_id`, child `workflow_link_id`/`child_call_id`, etc.).
- If a decision batch fails to commit, the workflow task is eligible
  for redelivery. A later successful commit produces exactly one typed
  history row per decision id; the duplicated attempt leaves no
  observable effect in history.
- `RepairRequested` / repair redispatch is not a new task — it is the
  engine re-enqueueing work that a previous worker could not durably
  commit. Repair does not duplicate history. It routes to the same
  decision set and is covered by the same exactly-once-at-commit
  guarantee.

What application authors must assume:

- Anything the workflow body does outside durable primitives can run
  more than once and must be safe to re-run. Use `sideEffect(...)`,
  `uuid4`/`uuid7`, activity results, updates, queries, or search
  attributes/memos to cross the durable boundary.
- Wall-clock reads, live cache reads, and mutable-shared-state reads
  inside the workflow body are prohibited for correctness, not style.

## Activity attempt execution semantics

Activities are the first-class vehicle for external side effects. They
are explicitly at-least-once.

Guarantees:

- Each activity execution has a stable `activity_execution_id` that
  does not change across retries. It is the default idempotency surface
  exposed to the worker on claim (see
  `Workflow\V2\Contracts\ActivityTaskBridge::claim()` and the
  `idempotency_key` field it returns, which is set to the execution id).
- Each activity attempt has a distinct `activity_attempt_id` and a
  sequential `attempt_number` starting at 1. Attempt ids are durable
  and are the correlation key for heartbeats, cancellation, and the
  typed attempt-scoped history events (`ActivityStarted`,
  `ActivityHeartbeatRecorded`, `ActivityRetryScheduled`,
  `ActivityCompleted`, `ActivityFailed`, `ActivityCancelled`,
  `ActivityTimedOut`).
- An activity attempt can complete more than once from the worker's
  point of view: a worker may finish work, lose its lease to a
  redelivery after a heartbeat gap, and still attempt to report
  completion. `ActivityOutcomeRecorder` records at most one terminal
  typed attempt event per `activity_attempt_id`, and reports
  `recorded=false` with a reason code for the late caller. The caller
  MUST NOT treat `recorded=false` as failure — another worker has
  already recorded the outcome or the attempt was superseded.
- The typed attempt-scoped history row for a given
  `activity_attempt_id` is exactly-once at the durable state layer.
  Retry scheduling emits a separate `ActivityRetryScheduled` for the
  new attempt and the next attempt carries the next
  `activity_attempt_id`.
- Heartbeats mirror the latest progress onto the live activity
  execution and renew the task lease. They are not retry checkpoints;
  a heartbeat never splits one attempt into two.
- Cancellation is observed by the activity via cooperative checks and
  does not guarantee termination of in-flight external work. Cancelled
  attempts may still produce external side effects up to the moment
  the worker honours the cancel.

What application authors must assume:

- The same activity execution may be observed more than once. Either
  the activity body or the external service it calls must be safe to
  repeat. The framework's default idempotency-key surface is
  `activity_execution_id` (same across retries) for remote services
  that accept an idempotency key per logical request; use
  `activity_attempt_id` only for systems that need to distinguish
  separate tries of the same logical activity.
- Database writes that must be exactly-once should be wrapped with a
  dedupe key (typically `activity_execution_id`) or placed inside a
  transaction that is idempotent under retry.

## Retry semantics

The v2 engine recognises three distinct retry surfaces. Each has its
own identifier and its own durable row.

### Activity attempt retry

- Governed by the activity's retry policy (`retry_policy` on the
  execution, with defaults from host configuration).
- On failure, the engine emits `ActivityFailed` for the current
  attempt, then — if retries remain — `ActivityRetryScheduled` with
  the new `retry_task_id`, `retry_of_task_id`, and backoff. The next
  attempt runs as a fresh attempt with a new `activity_attempt_id`.
- Non-retryable failures (`non_retryable=true` on `ActivityFailed`,
  structural-limit failures, or policy exhaustion) terminate the
  execution without scheduling a retry.

### Workflow-task retry and repair

- Workflow tasks themselves are not retried against application
  logic; they are replayed. If the worker that holds the task lease
  crashes or loses the lease, `TaskRepair` redispatches the same
  task to another worker. Each repair increments `repair_count` on
  the task and is surfaced through operator tooling, not through
  history, because no application-visible state has changed.
- A workflow-task-level failure that wants to surface as application
  behaviour writes typed `WorkflowFailed` / `WorkflowCancelled` /
  `WorkflowTerminated` history through the normal decision path. The
  engine does not silently retry a workflow decision against the
  application code.

### Child workflow retry

- Child workflows follow the child retry policy and produce
  `ChildRunStarted` events with `retry_attempt` and
  `retry_of_child_workflow_run_id` set when a child is a retry.
- Retries of the child workflow share the same
  `child_workflow_instance_id`, `workflow_link_id`, and
  `child_call_id` as the originally scheduled child; each retried run
  gets a new `child_workflow_run_id`.

## Lease expiry and redelivery

- Every claimed task (workflow task, activity task) carries a
  `lease_owner`, `lease_expires_at`, and, for activities, an
  `activity_attempt_id`. Once a lease expires, the task is eligible
  for redelivery.
- Redelivery is an at-least-once event. The replacement claim may land
  on a different worker, may land after the original worker has
  already reported completion, and may land before the original worker
  has finished. The engine mediates race conditions through:
  - typed outcome recording guarded by attempt/sequence checks
    (`ActivityOutcomeRecorder` / `TaskRepair`),
  - command normalisation that idempotently rejects a second decision
    batch for an already-settled `workflow_command_id`, and
  - the `MessageCursorAdvanced` monotonic cursor for message
    consumption.
- Operators observing two `ActivityStarted` events for the same
  `activity_execution_id` with different `activity_attempt_id` values
  should treat that as a normal redelivery, not a bug, as long as the
  engine eventually records exactly one terminal outcome for each
  `activity_attempt_id`.

## External commands and duplicate-start policy

- Start, signal, update, cancel, terminate, archive, and repair
  commands are recorded with a `workflow_command_id` that is the
  durable dedupe key for that command. External clients that can
  retry their HTTP request should send the same `workflow_command_id`;
  the engine rejects a second decision batch for the same id and
  preserves the original outcome.
- Start commands additionally honour the duplicate-start policy named
  on `DuplicateStartPolicy`:
  - `reject_duplicate` — the second start with the same
    `workflow_instance_id` returns `CommandOutcome::RejectedDuplicate`
    and does not begin a second run.
  - `return_existing_active` — the second start returns the existing
    active run's identity instead of beginning a new run.
  - The public contract on `Workflow\V2\Contracts\WorkflowControlPlane`
    names these values explicitly; callers must pick one deliberately.
- Signals and updates are idempotent at the durable state layer by
  `signal_id` / `update_id`. A `workflow_command_id` that already
  matches an applied signal/update is accepted as a no-op.

## Durable message stream semantics

- `MessageService::sendMessage()` creates paired outbound/inbound rows
  under one reserved instance sequence. An outbound row is durable and
  the matching inbound row carries the same logical identity across
  runs (including across continue-as-new; see
  `MessageService::transferMessagesToContinuedRun()`).
- `peekMessages()` and `receiveMessages()` are non-mutating reads.
  Only `consumeMessage()`/`consumeMessages()` advance the durable
  cursor, and each `MessageCursorAdvanced` event names exactly one
  `stream_key`. Cursor advance is monotonic; a consumed message cannot
  be un-consumed.
- External senders that may retry the same logical message should
  populate the optional `idempotencyKey` on `sendReference()` so the
  durable message stream can recognise and drop duplicates at the
  ingress layer.

## Side effects and version markers

- `sideEffect(...)` records the provided value into history as
  `SideEffectRecorded` exactly once per call site per sequence. Replay
  reads the recorded value; it does not re-invoke the side-effect
  callable. Authors must treat `sideEffect` as the one-shot durable
  snapshot primitive for non-deterministic values.
- `uuid4`/`uuid7` are one-shot value-recording operations. Calling
  them produces a fresh id on first invocation and replays the same id
  afterwards.
- `record_version_marker` is a frozen two-phase primitive. See
  `docs/api-stability.md` for its wire format and the PHP/Python
  parity contract. Adding, renaming, removing, or retyping a field is
  a protocol break, not a minor change.

## Schedule triggers

- `ScheduleTriggered` records an attempted trigger for an individual
  schedule occurrence with an `outcome` and
  `effective_overlap_policy`. Trigger records are exactly-once at the
  durable state layer for a given `schedule_id` and
  `occurrence_time`.
- Overlap policy decides whether a triggered occurrence is skipped,
  queued, or allowed to run concurrently. The application author
  selects the policy deliberately — it is a declared choice, not a
  best-effort behaviour.

## Framework-provided idempotency surfaces

The framework exposes the following stable idempotency keys to
application code and external workers:

| surface | identifier | lifetime |
| --- | --- | --- |
| workflow instance | `workflow_instance_id` | stable across continue-as-new |
| workflow run | `workflow_run_id` | bound to one execution generation |
| activity execution | `activity_execution_id` | stable across activity retries |
| activity attempt | `activity_attempt_id` | one attempt only |
| external command | `workflow_command_id` | one command only |
| message stream | `stream_key` + monotonic cursor position | durable |
| message send | caller-provided `idempotencyKey` on `sendReference()` | caller-controlled |
| side effect | `sequence` on `SideEffectRecorded` | frozen per call site |
| version marker | `change_id` on `VersionMarkerRecorded` | frozen per change id |
| schedule trigger | `schedule_id` + `occurrence_time` | durable per occurrence |

Application authors should prefer `activity_execution_id` as the
idempotency key against external services that accept one, because it
is stable across retries. Use `activity_attempt_id` only when the
external system must distinguish distinct tries of the same durable
activity.

## What developers must make idempotent

- **Activity bodies** that write to a database, call an external API,
  publish a message, or mutate any state outside the workflow engine
  must be safe to repeat. Prefer conditional writes, upserts keyed by
  `activity_execution_id`, or external idempotency keys.
- **Workflow bodies** must be deterministic under replay but are
  otherwise effect-free; the workflow body does not itself need to be
  idempotent because the engine does not re-invoke side effects for
  it.
- **External command senders** that retry must send the same
  `workflow_command_id` across retries so the engine can recognise and
  dedupe.
- **Durable-message senders** that may emit the same logical message
  twice must populate `idempotencyKey` on `sendReference()`.
- **Signal and update handlers** should treat a re-delivery of the
  same command id as a no-op.
- **Query handlers** see a non-durable read at query time. They must
  not produce external side effects; they are pure reads against the
  currently-resolved run state.
- **Compensation handlers** registered via `addCompensation()` run as
  normal activities on failure/cancel and inherit the same activity
  at-least-once contract.

## Operator and diagnostic guidance

- Duplicate execution of an activity attempt or workflow task is a
  normal distributed-system event, not a bug condition. Product docs,
  CLI reasoning, and Waterline incident messaging should describe it
  as an expected outcome of retries, lease expiry, and redelivery, and
  steer the reader toward the appropriate idempotency surface.
- A single `activity_execution_id` with multiple
  `activity_attempt_id` rows is by design. A single
  `activity_attempt_id` with multiple terminal events would be a
  bug — enforce that with the typed outcome recorders.
- Repair requests (`RepairRequested`) and repair redispatches are
  engine-level recovery steps. They should not read as application
  failures in operator UIs; they are the mechanism that keeps the
  engine live when transport or workers fall behind.
- Waterline selected-run detail rebuilds attempt status from typed
  history first (`ActivityStarted` / `ActivityHeartbeatRecorded` /
  `ActivityRetryScheduled` / `ActivityCompleted` / `ActivityFailed` /
  `ActivityCancelled`), with mutable attempt rows kept as
  enrichment. That layering is deliberate and must be preserved when
  adding new diagnostic surfaces.

## Test strategy alignment

- Replay correctness is covered by the PHP `WorkflowReplayer` tests
  and the cross-SDK fixtures referenced from `docs/api-stability.md`.
- Activity at-least-once and outcome-exactly-once behaviour is
  covered by the `ActivityOutcomeRecorder` tests and the recorded
  reason codes (`recorded=false` with a reason is the normal
  redelivery path, not a test failure).
- Command dedupe behaviour is covered by normalisation tests on
  `WorkflowCommandNormalizer` and the `DuplicateStartPolicy` enum
  cases.
- Message cursor monotonicity is covered by the
  `MessageCursorAdvanced` sequencing tests.
- This document is pinned by
  `tests/Unit/V2/ExecutionGuaranteesDocumentationTest.php`. A future
  change that renames, removes, or narrows any named guarantee must
  update the test and this document in the same change so the
  contract does not drift silently.

## Changing this contract

A change to any named guarantee (at-least-once, replay, durable
exactly-once, dedupe key surface, retry identity) is a protocol-level
change for the purposes of `docs/api-stability.md` and downstream
SDKs. Reviewers should treat unmotivated changes to the language above
as breaking changes and require explicit cross-SDK coordination
before merge.
