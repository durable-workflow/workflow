# Proposed update-handler receive observations

Status: contract for review on [#1030](https://github.com/durable-workflow/workflow/pull/1030),
following the [#1024 disposition](https://github.com/durable-workflow/workflow/issues/1024#issuecomment-6635174187).
The writer and readers are **not implemented or enabled**. The current runtime
and legacy query limitation remain unchanged. This proposal covers embedded
PHP update handlers, not a new service protocol or a release decision.

This revision incorporates the [Security conditions](https://github.com/durable-workflow/workflow/pull/1030#issuecomment-6635745022)
and [General's explicit-mode decision](https://github.com/durable-workflow/workflow/pull/1030#issuecomment-6635838425).
It supersedes the earlier caught-error and operational-only downgrade proposals.

## Explicit selection

Proposed application configuration follows the existing v2 configuration pattern:

```php
'update_receive_observations' => [
    'enabled' => false,
    'workflow_types' => [],
],
```

Both true enablement and an exact recorded workflow-type match are required for
new capture. A run without a workflow type uses its recorded workflow class.
The explicit list `['*']` selects the whole application. Empty, malformed or
omitted selection does not select anything. A package upgrade, existing marker
or positive budget cannot select new capture. Selection applies to new live
handlers, never to replay or backfill. Readers always honor existing marked
histories, including when capture is disabled later.

Whole-task metering applies only to selected workflow-task attempts, starting
at the outer transaction callback before instrumented effects. Reuse an active
meter instead of nesting another. Unselected attempts and legacy-only version-2
transport retain their existing behavior, including disabled-limit semantics.
Selected attempts require a positive byte budget. Mode documentation must state
the retained-row, error, transport and downgrade limits before enablement.

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

There is also a proposed durable run capability event,
`UpdateMessageReceiveObservationsRequired`, with payload `version: 1`,
`capability: embedded-update-receive-observations/1`, `workflow_instance_id`
and `workflow_run_id`. Record it once before the first selected handler enters,
in the same transaction as that handler's outcome. It must precede every v1
receipt on that run. New readers require and validate the event before workflow
entry or handler invocation. The event is not registered or produced in this
draft. Its exact old-reader counterproof remains a writer-activation gate.

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
direction, channel, stream_key_base64, source_workflow_instance_id,
source_workflow_run_id, target_workflow_instance_id, target_workflow_run_id,
correlation_id, idempotency_key, payload_reference,
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

Validate both source and target instance/run pairs, at capture and before replay,
on the configured connection and namespace. A non-null run requires a non-null
instance, and the actual run must belong to that instance. Resolve declared
instances in that namespace. A null pair declares no endpoint authority and
cannot be filled from current rows. The inbound owning instance must equal the
receipt instance, its owning run must be verified same-instance replay lineage,
and a declared target instance must equal that owner. A declared target run must
belong to the target instance and the verified destination lineage. Reject an
unverifiable source/target pair, including a pruned source run, before capture
can apply or replay can invoke the handler. No cross-namespace inference is
introduced. Changing either instance identity invalidates the digest and the
authority checks even if the run ID or payload reference still matches.
Revalidate the retained identities before an applied outcome can commit,
including rows that handler code modified after receiving them. A known
identity/state mismatch must refuse the selected transaction rather than
commit an applied receipt that the reader will immediately reject.

This is a verified row reference, not a message-value archive. Replay returns
the retained model; excluded mutable fields retain their existing semantics.
The digest does not archive external payload bytes or extend their retention.
Pruned, changed, inaccessible or ambiguously identified rows make replay
unavailable. No inline payload copy or message-row transport is added.

## Input bounds before traversal

Let `H` be `StructuralLimits::DEFAULT_HISTORY_TRANSACTION_BYTES` (16 MiB).
The receipt's `admitted_byte_limit` records `B`, the positive configured
transaction-byte limit capped at `H`. The builder is additionally constrained
by that transaction's remaining budget. Raising application limits cannot
raise the hard receipt input ceiling. A later ceiling change needs contract
review. `B` is a canonical decimal string in `[1, H]`, never a float or an
unchecked allocation size supplied by the receipt.

Reject raw receipts larger than `H` before JSON decode. For database history,
also bound the entire raw event payload, including command/task snapshots,
before invoking the model's JSON cast. The writer must enforce that same hard
event-payload ceiling after snapshots are assembled, even if configured
transaction limits were raised. Decode to the known shallow shape with a fixed
depth limit, then require the original encoded receipt length to be at most
`B` before traversing calls. Validate the root scalar fields first. Reject a
decoded-only v1 input that cannot supply an independently bounded raw source;
re-encoding an arbitrary graph is not a pre-decode bound.

Use the existing storage domains: instance IDs have at most 191 characters;
run, command and update IDs have at most 26; default incrementing message keys
are canonical positive decimal strings of at most 20 digits, checked against
the unsigned bigint maximum. Unsupported custom message-key domains refuse
this first explicit mode rather than silently bypassing identity validation.
Bound UTF-8 bytes before character counting. A stream's `varchar(191)` domain
allows at most 764 UTF-8 bytes and 191 characters, giving at most 1,020 base64
bytes. Validate raw length, strict decoding, UTF-8/storage length and canonical
round-trip before using a stream key. Preserve the admitted bytes exactly.

Before iterating, check list type and count. The minimum encoded call is at
least 165 bytes and a message identity at least 147 bytes in this closed shape.
Require at most `floor(B / 165)` calls and `floor(B / 147)` total message
identities. Per call, also enforce `WorkflowMessage::MAX_MESSAGES_PER_STREAM`
(10,000) and the recorded positive receive limit. Reject excess without
truncation. Check collection counts before list scans or canonicalization;
validate all bounded entries before any message/run/instance lookup. Use
checked arithmetic for totals, byte counts and integer-domain conversions.
Depth, size, count, scalar length, encoding and overflow errors cannot invoke
workflow code or cause database lookups. The JSON Schema carries the fixed
worst-case bounds; the reader must also enforce the tighter `B`-derived limits.

For version-3 raw bundles, require an `H` byte bound before full decode,
canonicalization, signature checking or recursive semantic traversal. Large
trace-bearing exports must explicitly refuse this initial transport ceiling;
it is not a claim that every retained run fits one export. The decoded-array
verifier must reject an unbounded version-3 input before traversing it.

**Raw-ingress proof still required:** existing import/verify CLI paths read and
decode legacy JSON before inspecting its version. A new v3 path may not pass
through that unbounded decoder. Demonstrate bounded admission for every v3
CLI/HTTP/programmatic entry point, including reordered headers and a caller
claiming v2 for a v3 body. Do not silently impose a new shared input cap on
valid legacy-only v2 imports. If these constraints require a legacy admission
change, return that concrete compatibility boundary to General before
implementation or activation. Neither the schema nor a decoded-array size
check proves this transport property.

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

A receipt with a `threw` entry is permitted only on a failed completion with
no applied event. It is retained and validated for audit; the failed handler is
not invoked during replay, as today. A received-error latch survives user catch
blocks. If the handler catches a receive failure and would otherwise return or
apply, refuse the entire task transaction before `UpdateApplied`, successful
completion or a capability event can commit. A stored applied receipt containing
`threw` is invalid history. Preserve the original failure on the uncaught,
failed/no-applied audit path. Do not rerun a database failure, instantiate an
arbitrary recorded class, invent an exception, skip the call or fabricate an
empty return. No successfully applied state may be knowingly unreplayable.

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
Handler code may catch that original failure, but it cannot commit an applied
outcome afterward. The external latch forces whole-task rollback, including
state changes and self-sends made after the catch. If the handler instead fails
before applying, its existing failure outcome can retain the no-applied audit
receipt. Infrastructure refusal always rolls back and cannot use that audit
exception to commit partial capture.

The no-applied audit path is not an exception to receive atomicity: prove that
the failed call's partial consumption/association effects rolled back at its
receive boundary. If that cannot be established, refuse the outer transaction
even when the handler fails. Do not label a partially committed receive as an
empty failed observation.

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

**Accepted selected-mode boundary, implementation unqualified:** embedded
`RunWorkflowTask` currently
does not start `HistoryTransactionMeter`; recorder charging is conditional on
an active meter. The proposal requires the existing meter plus the sticky
observation-completeness check around the outer embedded transaction callback
before it returns, reusing an existing meter where present. This makes the
existing whole-task limits effective on newly instrumented embedded attempts.
It is a real admission/compatibility delta, not already-proven behavior, and
is confined to explicitly selected attempts as described above.
Initially require a positive `history_transaction_bytes` budget before entering
new instrumented handlers; environments that disable that dimension must receive
an explicit refusal before handler effects, not an unbounded trace. Security/General accepted this restriction and whole-task scope for explicit
selection. The implementation and countercontrols remain unqualified.
Preserve the existing native task failure handling after transaction rollback.

## Export, import and downgrade

Proposed trace-bearing exports use `durable-workflow.v2.history-export` structural
version **3**, with `required_capabilities` containing exactly
`embedded-update-receive-observations/1`. Preserve event payloads, canonical
integer strings and byte encodings under the normal bundle integrity checksum
and optional signature. Legacy-only bundles remain version 2. Do not mutate
the frozen version-2 envelope or immutable conformance suite resources.

The new verifier must validate version/capability agreement, durable run fence,
marker/receipt pairing and syntactic/semantic receipt shape. A redactor must
preserve every required identity only when its policy permits doing so. If it
requires any such identity to be removed or changed, export must refuse before
emitting a bundle. Do not drop the marker/receipt/capability, bypass the policy
or disclose an identity that policy requires redacted. Exported history alone
cannot prove retained rows exist at the destination.

**Initial import boundary:** dry-run and write import of a trace-bearing bundle
return an explicit `update_receive_retained_rows_unavailable` rejection before
any write, even for terminal runs. The current bundle has no message-row
transport. This proposal does not add one, silently downgrade to visibility-only
import, remap identities or claim portable replay. The prospective import
contract version 3 must advertise this rejection while preserving existing
version-2 legacy import behavior. A later retained-row transport or verified
same-store import mode needs a separate contract and controls.

An older bundle verifier rejects structural version 3. Optional payload fields
alone do not fence an old local reader. The proposed unknown capability event
uses the accepted pre-feature `HistoryEventType` enum cast as the fail-closed
primitive. On accepted source, `VersionDecisions::replaceMarkers()` accesses
every event type before `WorkflowExecution::start()` in both query and executor
initialization. This is source evidence for a candidate fence, not a completed
downgrade qualification. Prove it against exact `4cfa985b`, with constructors,
entry/update/query calls observed separately, including warm continuation and
the executor's exception/failure/projection paths. No handler/state execution,
run failure, task/cursor/message/history change may commit in that control.
If any old path skips or mutates through the event, the fence is insufficient
and writer activation remains held pending a different reviewed design.

New readers reject a receipt without the durable fence, duplicate/conflicting
fences and unknown fence versions before workflow entry. Turning capture off
does not remove or ignore a committed fence. No receipt deletion, history
rewrite or operational-only build pin substitutes for the mechanical refusal.
The next 2.x minor remains provisional pending the counterproof and the
reader/import/rollback review. Export refusal is not restore or portability
acceptance, and writer rollout/release cannot claim recovery elsewhere while
retained-row transport or verified same-store import remains unimplemented.

## Required controls before writer enablement

The [shape vectors](../../tests/Fixtures/V2/Contracts/update-message-receive-observations-v1.json)
are proposal data, not replay-corpus or runtime passes. The unit writer guard
keeps both proposed producer fields rejected during review. The unknown-event
unit control covers only the current marker scanner, not the complete query,
executor, continuation or rollback counterproof. Add implementation
controls after contract review, with exact unchanged-runtime fail-before proofs:

| Area | Controls and required result |
| --- | --- |
| Selection | Default off produces unchanged history/source behavior. Exact type/app selection alone enables eligibility. Upgrade, budget, marker presence and replay never opt in. Capture disabled after a v1 write still reads that trace strictly. |
| Prospective correctness | Original trusted query repro, completed output, repeated queries/updates, kept/new streams, batches/limits, explicit/null overrides and exact case/Unicode stream bytes. |
| Empty and ordering | Preserve the existing empty-update/later-body-consumption guard. Add official empty receive, self-send, receive of self-send, followed by another empty call. Preserve original order and IDs. |
| History positions | Self-sends and messages behind an advanced shared cursor, arrival accepted before execution, repeated empty calls and consumed rows written by legacy live keys. No new position-based inference. |
| Strict reader | Missing/deleted/changed row, changed source or target instance/run pair, wrong namespace/run/lineage/stream/order/key, wrong command/update, duplicate completion, missing receipt/fence, version mismatch, extra/missing call and invalid integer/base64. Error with all durable snapshots unchanged. |
| Exceptions | Handler throws before/after receive; receive failure caught/uncaught; successful handler followed by result-serialization failure. A handler catching a receive failure and mutating state/self-sending must commit no applied/completed success or durable effects. Preserve the original failure and no-applied audit path. |
| Atomicity | Inject failure before/after consumption, during fingerprinting, applied/completed recording, budget refusal and commit. Catch each in handler and failure path. No incomplete committed receipt/effects; retry starts fresh. |
| Budget/input | Repeated empty calls, large batches, exact remaining-budget boundary, existing-meter reuse, oversized final snapshots, invalid encoding, zero/disabled budget and rollback/retry. Raw oversize, depth/count/ID/stream/overflow refusals precede traversal/lookup. Unselected/legacy disabled-limit behavior is unchanged. |
| Transport/rollback | Legacy export/import unchanged; bounded version-3 ingress including reordered/dishonest headers; trace-bearing imports rejected before writes; redaction policy refusal without identity leaks; exact old-reader query/executor/continuation refusal with zero workflow calls and unchanged durable snapshots. |
| Corpus | Append the smallest new `replay-regression/v1` fixture plus embedded query/executor consumers under the existing guide. Keep legacy fixtures immutable and enforce the binding counterfactual. |

No schema migration, live receive key change, global cursor change, new receive
authoring API or backfill is part of this proposal. No capture/replay writer
activation, release or downstream acceptance is claimed by this contract draft.
