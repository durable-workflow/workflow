# Proposed update-handler receive observations

Status: contract for review on [#1030](https://github.com/durable-workflow/workflow/pull/1030),
following the [#1024 disposition](https://github.com/durable-workflow/workflow/issues/1024#issuecomment-6635174187).
The writer and readers are **not implemented or enabled**. The current runtime
and legacy query limitation remain unchanged. This proposal covers embedded
PHP update handlers, not a new service protocol or a release decision.

## History fields and authority

Add exactly two optional payload fields after contract review:

| Event | Field | Meaning |
| --- | --- | --- |
| `UpdateApplied` | `message_receive_observations_version: 1` | Requires the matching completed receipt before replaying this applied handler. |
| `UpdateCompleted` | `message_receive_observations` | The sole complete receipt, on both successful and failed outcomes after handler entry. |

The proposed [JSON Schema](../../resources/contracts/update-message-receive-observations-v1.schema.json)
defines the receipt's shape. It does not validate database identity, event
relationships, budgets or executing code. Unknown fields and versions are errors.
These fields are still rejected by `HistoryEventPayloadContract` in this draft.

The receipt repeats `workflow_instance_id`, `workflow_run_id`,
`workflow_command_id` and `update_id`. All must match the completed event,
immutable command snapshot and official update admission. Missing command
identity is an error, not permission to generate an identity or write legacy
history. `applied_event_sequence` is the actual history event sequence of the
matching `UpdateApplied`, or null when the handler failed before that event.
It is distinct from payload `sequence`, the workflow step. Require exactly one
matching completion per applied event. Duplicate, missing, conflicting or
cross-run matches are errors. A completion with a receipt and an applied event
without the version marker is also an error.

Success is not inferred from receipt presence. Existing outcomes remain
authoritative. A handler that throws before `UpdateApplied` retains its failed
completion receipt for audit and is not invoked during state replay. If the
handler returned and `UpdateApplied` was recorded but result serialization then
failed, the receipt still points to that applied event. Its state is replayed
as it is today. Do not move `UpdateApplied` or change those outcome rules to
simplify capture.

## Per-call observations

`calls` contains every entered `MessageStream::receive()` in the handler's
execution order. `receiveOne()` delegates to one such call and does not add a
second entry. The handler context is shared by kept and newly opened streams.
`peek()` and send behavior are unchanged and are outside this receive contract.
This proposal does not make arbitrary message-reading code replay-safe.

Each call has:

- `ordinal`: contiguous zero-based position across all streams and objects.
- `stream_key_base64`: canonical padded base64 of the exact stream bytes.
  Decode strictly and require encode/decode round-trip equality. Database
  collation, Unicode normalization and case folding cannot select a stream.
- `requested_limit`: the supplied integer, including invalid values when the
  call throws. `receiveOne()` records 1.
- `consumed_by_sequence_override`: null for the existing implicit/null
  semantics, or the supplied integer. Omitted and explicit PHP null are the
  same existing API operation. An explicit integer equal to the implicit key
  remains distinguishable from null.
- `effective_consumed_by_sequence`: the key selected by the unchanged receive
  algorithm, including its existing kept-stream fallback when used, or null
  if the call threw before selecting a key. Do not replace it with the update
  admission sequence, a cursor estimate or an observation ordinal.
- `outcome`: `returned` or `threw`.
- `messages`: ordered retained-row identities for a returned call, explicitly
  empty for an empty return. A thrown call has an empty list and cannot claim
  rolled-back or partially returned rows.

All integer-valued fields other than version use canonical decimal strings.
Ordinals and message sequences are nonnegative and positive respectively.
Ordinals, sequences, limits, overrides and effective keys must fit signed
64-bit integers. No plus
sign, exponent, leading zero or negative zero is allowed. The reader checks
domain bounds before conversion. Message IDs are exact stringified model keys,
with canonical decimal strings for the default incrementing model. They must
never pass through floating-point conversion.

For returned calls require a positive requested limit, a non-null effective
key and no more rows than the limit. Preserve the existing live row selection,
consumption keys, order and send ledger. A receipt records the result actually
returned, including an existing recorded receive; it does not define a new
live selection algorithm. Repeated calls are distinct even if their requested
arguments are equal. `calls: []` means zero calls, not one empty receive.

## Retained-row identity

Each returned identity contains `workflow_run_id`, `message_id`, `sequence`
and `identity_sha256`. Resolve exactly those rows on the configured connection,
in the current namespace and verified same-instance replay lineage. A run ID
in the receipt is not authority to access another run or namespace. Compare
IDs, stream bytes, inbound direction, order and effective consumption key
after lookup. No search for a similar payload or another consumed row is allowed.

`identity_sha256` is `sha256:` followed by the lowercase digest of a fixed
projection, encoded with the existing `json-recursive-ksort-v1` canonicalizer:

```
id (string), sequence (decimal string), workflow_instance_id, workflow_run_id,
direction, channel, stream_key_base64, source_workflow_run_id,
target_workflow_run_id, correlation_id, idempotency_key, payload_reference,
metadata (the model's array/null projection), expires_at (UTC, six fractions)
```

Use null for absent nullable values and the model's enum string values. Preserve
list order and scalar types. Reuse the existing canonical JSON flags and
recursive string-key sorting, not a second cross-language canonicalization.
Invalid encoding or unsupported model identity refuses capture. Golden vectors
must pin nulls, Unicode, nested metadata, floats and IDs above 2^53 before enablement.
Require consumed state and the recorded effective key separately. Exclude mutable
delivery bookkeeping, `consumed_at` and database creation/update timestamps from
the digest. Changing a pinned content field makes replay unavailable.

This is a verified row reference, not a message-value archive. Replay returns
the retained model; excluded mutable fields retain their existing semantics.
The digest does not archive external payload bytes or extend their retention.
Pruned, changed, inaccessible or ambiguously identified rows make replay
unavailable. No inline payload copy or message-row transport is added.

## Reader behavior

Executor and query use the same validator and handler-scoped call ledger.
Validate the complete receipt and every referenced row before invoking an
applied handler. On each receive, compare ordinal, stream bytes, requested
limit and override, then return exactly that entry's verified ordered rows.
Use the recorded effective key without changing the workflow's visible cursor.
An empty list returns an empty collection without looking for pending messages.
Keep ordinary receive-ledger accounting consistent with the recorded actual
keys and returned counts so later workflow-body receives do not borrow rows.

Require complete call exhaustion when the handler returns. Too many/few calls,
different stream, limit or override, unexpected handler failure, unknown
version, missing receipt or invalid row must raise the existing
`HistoryEventShapeMismatchException` with a receive-observation reason. Missing
rows use an explicit unavailable detail. Do not put payloads or row contents in
the error. A caught mismatch must remain latched and fail the outer replay.
Queries must discard partial state on error. Messages, history and stored run
cursor must be byte-for-byte unchanged on both successful and failed replays.

A receipt with a `threw` entry is retained and validated for audit, but replay
of its applied handler is explicitly unavailable in this first version. Do not
rerun an arbitrary database failure, instantiate an arbitrary recorded class,
invent an exception, skip the call or turn it into an empty return. This is an
explicit additional coverage limit for caught receive errors. Failed handlers
without an applied event continue to be skipped as described above.

Absence of both new fields means legacy data. Keep the existing reader and
its #1024 limitation. Do not backfill ordinals, empties or receipts from current
rows or cursor events. Mixed legacy and instrumented updates on one run retain
this per-update distinction. Correct prospective receives do not imply that
old queries have been repaired or that #1024 can close.

## Atomic capture and budgets

Capture belongs to the existing outer workflow-task database transaction, not
an after-commit hook. Start a fresh context for each handler and transaction
attempt. A successfully returned receive must have its observation before
control returns to workflow code. Database receive failures retain their
existing nested-transaction rollback behavior and record a `threw` attempt.
Handler code may catch that original failure and continue. Its successful
completion then retains the explicitly unavailable receipt described above.

Observation allocation/encoding, row-fingerprint, recorder or budget failures
are infrastructure failures. Latch them outside handler control and refuse
the entire task transaction even if user code or the update failure handler
catches them. A failure after consumption cannot be represented as `threw`
with empty effects. Outer rollback must remove consumption, cursor movement,
messages/self-sends, applied/completed events and all attempted receipts.
No successful commit may contain an instrumented consumption without its
complete receipt. Reset the context after rollback/retry and restore prior
in-memory observation scope in finally blocks.

Reuse `history_transaction_bytes` and the existing `HistoryTransactionMeter`.
Bound the builder incrementally by the remaining byte budget before appending
each call/row, then charge the exact final event, including command/task
snapshots, once at the recorder. Preview accounting is not a second charge.
No truncation, external trace spill, new payload archive or fallback to legacy
is allowed. Empty calls consume bytes and can exhaust the same budget.

**Activation boundary requiring review:** embedded `RunWorkflowTask` currently
does not start `HistoryTransactionMeter`; recorder charging is conditional on
an active meter. The proposal requires the existing meter plus the sticky
observation-completeness check around the outer embedded transaction callback
before it returns, reusing an existing meter where present. This makes the
existing whole-task limits effective on newly instrumented embedded attempts.
It is a real admission/compatibility delta, not already-proven behavior.
Initially require a positive `history_transaction_bytes` budget before entering
new instrumented handlers; environments that disable that dimension must receive
an explicit refusal before handler effects, not an unbounded trace. Both this
restriction and budget-enforcement scope require Security/General disposition.
Preserve the existing native task failure handling after transaction rollback.

## Export, import and downgrade

Proposed trace-bearing exports use `durable-workflow.v2.history-export` structural
version **3**, with `required_capabilities` containing exactly
`embedded-update-receive-observations/1`. Preserve event payloads, canonical
integer strings and byte encodings under the normal bundle integrity checksum
and optional signature. Legacy-only bundles remain version 2. Do not mutate
the frozen version-2 envelope or immutable conformance suite resources.

The new verifier must validate version/capability agreement, marker/receipt
pairing and syntactic/semantic receipt shape. Redaction cannot silently drop
the marker, receipt or its required identity data. Exported history alone
cannot prove retained rows exist at the destination.

**Initial import boundary:** dry-run and write import of a trace-bearing bundle
return an explicit `update_receive_retained_rows_unavailable` rejection before
any write, even for terminal runs. The current bundle has no message-row
transport. This proposal does not add one, silently downgrade to visibility-only
import, remap identities or claim portable replay. The prospective import
contract version 3 must advertise this rejection while preserving existing
version-2 legacy import behavior. A later retained-row transport or verified
same-store import mode needs a separate contract and controls.

An older bundle verifier rejects structural version 3. Older local readers can
ignore optional history fields, however, and old recorders can reject them.
There is no safe silent downgrade guarantee. Pin affected runs to an
observation-aware build. Rolling back code alone does not restore replay
correctness. Do not delete receipts, rewrite history or claim that a missing
row can be recovered from a receipt. The next 2.x minor is provisional pending
this reader/import/rollback review.

## Required controls before writer enablement

The [shape vectors](../../tests/Fixtures/V2/Contracts/update-message-receive-observations-v1.json)
are proposal data, not replay-corpus or runtime passes. The unit writer guard
keeps both proposed producer fields rejected during review. Add implementation
controls after contract review, with exact unchanged-runtime fail-before proofs:

| Area | Controls and required result |
| --- | --- |
| Prospective correctness | Original trusted query repro, completed output, repeated queries/updates, kept/new streams, batches/limits, explicit/null overrides and exact case/Unicode stream bytes. |
| Empty and ordering | Preserve the existing empty-update/later-body-consumption guard. Add official empty receive, self-send, receive of self-send, followed by another empty call. Preserve original order and IDs. |
| History positions | Self-sends and messages behind an advanced shared cursor, arrival accepted before execution, repeated empty calls and consumed rows written by legacy live keys. No new position-based inference. |
| Strict reader | Missing/deleted/changed row, wrong namespace/run/lineage/stream/order/key, wrong command/update, duplicate completion, missing receipt, version mismatch, extra/missing call and invalid integer/base64. Error with all durable snapshots unchanged. |
| Exceptions | Handler throws before/after receive; receive failure caught/uncaught; successful handler followed by result-serialization failure. Preserve existing applied/completed outcome rules and explicit caught-error replay limit. |
| Atomicity | Inject failure before/after consumption, during fingerprinting, applied/completed recording, budget refusal and commit. Catch each in handler and failure path. No incomplete committed receipt/effects; retry starts fresh. |
| Budget | Repeated empty calls, large batches, exact remaining-budget boundary, oversized final snapshots, invalid encoding and disabled byte budget. No silent truncation or unbounded builder. |
| Transport/rollback | Legacy export/import unchanged; version-3 shape/integrity verified; trace-bearing imports rejected before writes; redaction/version downgrade refused; old reader risk documented. |
| Corpus | Append the smallest new `replay-regression/v1` fixture plus embedded query/executor consumers under the existing guide. Keep legacy fixtures immutable and enforce the binding counterfactual. |

No schema migration, live receive key change, global cursor change, new receive
authoring API or backfill is part of this proposal. No capture/replay writer
activation, release or downstream acceptance is claimed by this contract draft.
