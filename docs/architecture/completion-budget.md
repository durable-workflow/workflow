# Workflow Task Completion Budget

A workflow task completion is one database transaction. It applies a worker's
commands and everything they cause. The completion budget bounds that
transaction, so a valid completion can neither drive timestamp arithmetic out
of range nor write an unbounded amount of history or external payload data.
Every row it writes is charged before the statement that writes it runs.

## Timing fields of `record_local_activity`

Local activity reports carry elapsed times, not timestamps. Recording walks
each attempt back from the completion instant by its duration and the
following retry backoff. The normalizer bounds every field it walks, with an
ordinary 422 field error:

| Field | Maximum | Constant |
| --- | --- | --- |
| `attempts.*.duration_ms` | 604,800,000 (7 days) | `WorkflowCommandNormalizer::MAX_LOCAL_ACTIVITY_DURATION_MS` |
| `attempts.*.heartbeats.*.elapsed_ms` | 604,800,000 (7 days) | `WorkflowCommandNormalizer::MAX_LOCAL_ACTIVITY_DURATION_MS` |
| `attempts.*.backoff_seconds` | 86,400 (1 day) | `WorkflowCommandNormalizer::MAX_LOCAL_ACTIVITY_BACKOFF_SECONDS` |

With at most 100 attempts, a report reaches back at most about 800 days. The
bridge also sums the walk with overflow-checked arithmetic. Before writing, it
refuses with `local_activity_timestamp_out_of_range` if the earliest derived
timestamp falls before the earliest timestamp every supported column stores
(MySQL `TIMESTAMP`'s 1970-01-01T00:00:01Z).

## The early check

After a run's deadline close (see below) and before the worker's first write,
`complete()` counts the history events and rows the completion's local
activities will write. The count is exact: per command, 1 scheduled event,
then per attempt 1 started event, its heartbeats and a retry event before the
next attempt, then 1 terminal event. Rows are two per event (the event and
the run's history position), plus the execution, each attempt and a failure
when the activity failed or timed out. Every other command counts its lower
bound of one event.

Past a limit, the completion is refused with `completion_budget_exceeded`
before anything is written, and the worker keeps its lease. This refuses the
local-activity amplifier cheaply. It is not the whole budget.

## The history transaction meter

`HistoryTransactionMeter` is the authority. `complete()` and the checkpointed
command paths run each transaction attempt's body under a fresh meter. A
retried attempt starts over with an empty stage.

- **Events and bytes.** `WorkflowHistoryEvent::record()` is the only writer of
  history. It charges one event, and its bytes as `HistoryBudget` measures a
  stored event: the event type length plus the length of
  `json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`.
  If the payload cannot be encoded, the completion fails closed with
  `history_transaction_measure_unavailable`.
- **Rows.** Every write statement is charged before it runs (#1116), by the
  storage connection's before-executing hook, which `HistoryTransactionMeter::run()`
  registers once per connection. The hook charges nothing for a read, one row
  per tuple of an insert or upsert, and one row for an update or delete. A
  statement that can change several existing rows runs through
  `HistoryTransactionMeter::affecting()`, which first counts the rows it will
  change with a bounded read (at most the remaining rows plus one), charges
  that count, and lets the statement run without charging it again. No write
  path inside the completion is outside the meter, including writes added
  later: an unwrapped bulk statement is charged at least one row, and the
  families below list the exact charge. Work run under
  `HistoryTransactionMeter::unmetered()` (the deadline close) is not charged.
- **Refusal.** A charge past a limit throws
  `HistoryTransactionLimitExceededException`. That trips the meter: any later
  charge, and the end of the body, throw again. Code that catches the
  exception therefore cannot continue to a commit. The exception is handled
  only outside the transaction callback. The transaction rolls back, so no
  history, execution, attempt, failure, projection, task or receipt row
  survives, and the task keeps its lease and expiry. The bridge answers
  `completed: false` with `reason: history_transaction_limit_exceeded` and
  `limit` naming the dimension.
- **Lifecycle events.** Public lifecycle events (workflow completed, failed or
  started by continue-as-new, activity failed, failure recorded) report
  committed state. Inside a metered transaction they are held back and
  dispatched once, after the transaction commits. A refused attempt, a failed
  staged put, a failed commit and a retried attempt dispatch none of them.

## Limits

All of these are hard `StructuralLimits`. A value of 0 disables that
dimension. `StructuralLimits::snapshot()` publishes each one, so the health
check, operator metrics and backend capability reports show them.

| Config (`workflows.v2.structural_limits.*`) | Environment | Default | Meaning |
| --- | --- | --- | --- |
| `history_transaction_size` | `DW_V2_LIMIT_HISTORY_TRANSACTION_SIZE` | 5,000 | History events one completion may write |
| `history_transaction_bytes` | `DW_V2_LIMIT_HISTORY_TRANSACTION_BYTES` | 16 MiB | History bytes one completion may write |
| `history_transaction_rows` | `DW_V2_LIMIT_HISTORY_TRANSACTION_ROWS` | 20,000 | Rows one completion may insert, update or delete, counted before each statement |
| `external_payload_transaction_bytes` | `DW_V2_LIMIT_EXTERNAL_PAYLOAD_TRANSACTION_BYTES` | 16 MiB | Raw payload bytes one completion may externalize |
| `payload_size_bytes` (existing) | `DW_V2_LIMIT_PAYLOAD_SIZE_BYTES` | 2 MiB | Each raw payload externalized |


`HistoryBudget`'s continue-as-new thresholds stay advisory. They recommend
continue-as-new and never refuse a write.

## External payloads

A database rollback cannot undo an object stored in external payload storage.
Every payload a metered completion externalizes is first checked against
`payload_size_bytes` and charged to `external_payload_transaction_bytes`.
Every occurrence counts, even when identical content will be stored once.
What happens next depends on the driver.

**Drivers that predict their URI** implement
`PredictableExternalPayloadStorageDriver`. Their payloads are staged, and
stored only after the completion is admitted:

1. `uriFor($sha256, $codec)` returns the URI `put()` will return for that
   content. The payload is registered in the meter's stage under its driver
   and URI, and the final stored envelope, with the predicted URI, is written
   to rows and history. The meter therefore measures the exact bytes that
   commit.
2. If the same driver predicts one URI for different content, hash or codec,
   the completion is refused with `external_payload_staged_uri_collision`.
   Two drivers that predict the same URI keep separate entries.
3. After the body has passed every limit, still inside the transaction and
   before commit, the stage stores each object in order. Each `put()` must
   return its predicted URI. A mismatch fails with
   `external_payload_staged_uri_mismatch`, and any failure rolls back.

A limit refusal happens before the stage is flushed, so it stores nothing.

The interface requires `put()` to return exactly `uriFor()`, refuse content
that does not match its SHA-256, never leave a partial or corrupt object at
the URI, and be idempotent. The built-in local driver implements it. It keeps
an existing object only if its length and hash match. Otherwise it writes the
content aside, checks the exact length and hash, and renames it into place.

**Other drivers** implement only `ExternalPayloadStorageDriver`, as before.
The built-in Laravel filesystem driver is one of them, since a filesystem disk
does not guarantee an atomic write. These drivers keep storing each payload
when it is externalized, after its bytes are charged. If the completion is
then refused or rolled back, the objects it stored stay, unreferenced. The
exposure is bounded by `external_payload_transaction_bytes` per transaction
attempt. To store nothing on refusal, implement `uriFor()` and the guarantees
above.

**Orphans.** If a stage flush or commit fails after some objects are stored,
those objects stay. Content-addressed objects may be shared, so a rollback
never deletes them. Reading a payload checks its size and hash against the
reference, and reachability-based retention collects unreferenced objects.

## Write families and their charges

Each family below is charged before its first statement runs. One row is one
row inserted, updated or deleted, and a history event costs one event, its
bytes, and two rows: the event and the run's history position.

| Family | Writes | Charge |
| --- | --- | --- |
| Task and run bookkeeping | the completed task, the run's status, wait and progress columns | one row per changed model save |
| History | each event and the run position it advances | one event, its bytes, two rows |
| Local activities | execution, attempts, failure, their events | exact early check, then each statement |
| Activities, timers, children, service calls scheduled by commands | the execution, timer, link, supervision, call or dispatch row, its task, its event | one row per insert or update, and the event |
| Signals and updates applied or closed | signal, update and command rows, their events, `PendingSignalCloser` and `PendingUpdateCloser` | one row per model save, and each event |
| Continue-as-new handoff | the new run, its task, its lineage, transferred messages and cursor | one row per insert; transferred messages counted by `affecting()` |
| Parent-close enforcement and child cascades | parent events, child commands, supervision retargeting | one row per statement; `ChildSupervision::followRetry()` counted by `affecting()` |
| Cancellation and selection-cancel leaves | the cancelled executions, timers, tasks, their events | one row per model save, and each event |
| Checkpoints | the checkpoint's local activity and scope rows and events | as a completion: each checkpoint runs under its own meter |
| Projections | summary, wait, timeline, lineage and repair rows | one row per upsert tuple or save; wait, child-repair and stale-projection bulk updates and deletes counted by `affecting()` |
| Memo and search attributes | memo rows, attribute upserts and deletes | one row per upsert tuple; deletes counted by `affecting()` |
| Compensation scopes | scope and obligation rows | one row per statement (each targets one row) |
| External payloads | staged objects | raw bytes against `external_payload_transaction_bytes` |
| After-commit child-call projection | the operator's child-call row, written after commit | one row charged inside the transaction when it is deferred |

Queue dispatch runs after commit and is not completion state: with a database
queue it inserts one job per task the completion created, and each of those
tasks was charged when it was written.

The early check stays exact for local activities and a lower bound for other
commands. A cascade larger than the remaining budget is refused by the charge
before its first statement, so a refused completion rolls back everything the
completion had already written. The tests in
`tests/Unit/V2/CompletionWriteFamilyBudgetTest.php` complete each family once
to learn its exact total, then show that a limit equal to it admits the
completion and a limit one lower refuses it with executions, attempts,
history, projections and receipts unchanged and the task's lease kept.

## A run's deadline close

When a worker completes a task whose run is past its execution, run or
cleanup deadline, `complete()` closes the run before applying any command.
That close is the server's own work on stored state, not the worker's, so it
runs outside the worker's meter (`HistoryTransactionMeter::unmetered()`) and
before the early check. A budget refusal therefore never prevents a close.

The close is bounded instead (#1116). An ordinary run, one without opted-in
timeout cleanup, closes in a single transaction only when its open work fits
one batch. The batch is the completion budget's events less one (for the
terminal event), and at most a fifth of its rows, since a cancelled activity
writes its execution, its task, its event and the run's position. With the
defaults that is 4,000 open activities, timers and tasks. A larger close is
fenced and continued:

1. One transaction cancels the first batch of open work, in the order and
   with the history the single close writes: open activities with their tasks
   and `ActivityCancelled`, then pending timers with `TimerCancelled`, then
   other open tasks. The run stays open and expired. Its workflow task is
   released to Ready, and the completion answers `completed: false` with
   `reason: run_deadline_closing`.
2. An expired run accepts no worker command: every completion and claim on it
   goes to the close first, so the batch is a fence, not a race.
3. The watchdog continues the close without a worker. Each pass takes one
   batch. When the rest fits, the existing close times the run out with the
   same `WorkflowTimedOut`, failure, update closes and parent-close policy.

Every pass removes at least one open item, so a close always finishes, within
one pass per batch. The history of a batched close is the history the single
close writes, so a run closed by an earlier version and a run closed in
batches replay the same way. Setting both limits to 0 restores the single
transaction.

**Selection of expired runs.** The watchdog used to skip an expired run with
any Ready or Leased workflow task, expecting that task's completion to close
it. A run whose task no worker claims then stayed open past its deadline. It
now skips only a run whose workflow task holds a live lease. For an ordinary
run whose task is Ready or whose lease expired, the watchdog closes the run
itself under the run lock, using that task, through the same bounded close. An
ordinary close needs no workflow code, so no worker is needed. Opted-in
timeout cleanup and cooperative cancellation keep their own close
(`docs/architecture/timeout-cleanup.md`).

## Phases

**Phase A** (#930) added the timing caps and the storage-floor guard, the exact
local-activity early check, the writer-level event and byte meter, history
and local-activity row charges, the external payload limits with staging,
lifecycle events held back until commit, and the unmetered deadline close.

**Phase B** (#1116) charges every write statement before it runs, counts
bulk updates and deletes with a bounded read, bounds the deadline close in
fenced batches, and lets the watchdog close an ordinary expired run whose
task is unclaimed or abandoned. Native parity is tracked in
durable-workflow/server#766.
