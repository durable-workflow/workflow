# Workflow Task Completion Budget

A workflow task completion is one database transaction. It applies a worker's
commands and everything they cause. The completion budget bounds that
transaction, so a valid completion can neither drive timestamp arithmetic out
of range nor write an unbounded amount of history or external payload data.
Rows are bounded for the write sites listed under Phases; the rest follow in
Phase B.

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
  It also charges two rows: the event and the run position it advances. If the
  payload cannot be encoded, the completion fails closed with
  `history_transaction_measure_unavailable`.
- **Rows.** A metered write site charges the rows its statement will affect
  before the statement runs. Phase A meters every history write and every
  local activity row. Other command writes are not counted yet; Phase B
  charges them (see below).
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
| `history_transaction_rows` | `DW_V2_LIMIT_HISTORY_TRANSACTION_ROWS` | 20,000 | Metered rows one completion may write. Phase A counts history events with their run positions, and local activity rows. |
| `external_payload_transaction_bytes` | `DW_V2_LIMIT_EXTERNAL_PAYLOAD_TRANSACTION_BYTES` | 16 MiB | Raw payload bytes one completion may externalize |
| `payload_size_bytes` (existing) | `DW_V2_LIMIT_PAYLOAD_SIZE_BYTES` | 2 MiB | Each raw payload externalized |

Until Phase B lands, `history_transaction_rows` does not bound the rows of
other command writes or the cascades they trigger. The pending-count limits
bound those.

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

## A run's deadline close

When a worker completes a task whose run is past its execution, run or
cleanup deadline, `complete()` closes the run before applying any command. No
other path closes a run while its workflow task is open. That close is the
server's own work on stored state, not the worker's, so it runs unmetered
(`HistoryTransactionMeter::unmetered()`) and before the early check. A run
whose close is larger than one completion's budget still closes, instead of
refusing every completion.

## Phases

**Phase A** (this change) covers:

- the timing caps and the storage-floor guard;
- the exact local-activity early check;
- the writer-level event and byte meter on every path, checkpoints included;
- history and local-activity row charges;
- the raw external payload byte limits, with staging for drivers that predict
  their URI;
- lifecycle events held back until commit;
- the unmetered deadline close.

**Phase B** charges every remaining write site before its statement runs:

- the pending-signal and pending-update closers;
- the continue-as-new handoffs;
- parent-close enforcement;
- selection-cancel leaves;
- service operations;
- projections;
- memo and search-attribute writes.

Each bulk site reads at most the remaining rows plus one before it decides.
The row limit bounds a whole completion only when Phase B lands.

Phase B also depends on three separate fixes: service operations taking their
namespace and caller identity from the server instead of the worker, no
sleeps inside the completion transaction, and pending-count limits on
external-worker completions.
