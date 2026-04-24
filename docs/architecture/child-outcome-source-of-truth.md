# Workflow V2 Child-Outcome Source-of-Truth Contract

This document freezes the v2 contract for how a parent workflow resolves
the outcome of a child workflow call. It is the reference cited by the
v2 docs, CLI reasoning, Waterline diagnostics, server run-detail
surfaces, and test coverage so the whole fleet speaks one language about
which durable row decides whether a child is open, completed, failed,
cancelled, or terminated, and which row decides the child's return
value or thrown exception.

The guarantees below apply to the `durable-workflow/workflow` package at
v2 and to every host that embeds it or talks to it over the worker
protocol. A change to any named guarantee is a protocol-level change
and must be reviewed as such, even if the class that implements it is
`@internal`.

This contract builds on the semantics frozen in
`docs/architecture/execution-guarantees.md` (Phase 1). Duplicate
execution, typed exactly-once history, and replay keep the language
they have there; this document adds the language that decides, at
replay time, at query time, and on operator surfaces, which row is
authoritative when a parent history event, a child-run history event,
a mutable child row, and a child link disagree.

It also builds on the routing and authority boundaries frozen in
`docs/architecture/control-plane-split.md` (Phase 4). Who writes to
`workflow_runs`, `workflow_links`, and history events is named there;
this contract adds who READS those surfaces and in what order.

## Scope

The contract covers:

- **the child-call sequence slot** — the parent-side view of a single
  child workflow invocation identified by `(parent_run_id, sequence)`.
- **resolution precedence** — the ordered list of rows the engine
  consults to decide whether the child-call slot is open, resolved,
  or unsupported.
- **the three history-authority modes** — typed history, mutable
  open fallback, and unsupported terminal fallback, named as
  constants on `Workflow\V2\Support\ChildRunHistory`.
- **payload authority** — which row decides the child's output or
  exception when the slot is resolved.
- **parent-history blocking** — the invariant that parent history is
  never bypassed even when the child-run row has advanced past it.
- **continue-as-new traversal** — how `followContinuedRun` resolves a
  chain of continued child runs into the current run for display.
- **consumers** — which engine, replay, and diagnostic paths are
  bound by this precedence.

It does not cover:

- the choice of typed history event names or their payload shape.
  That is Phase 1's execution-guarantees contract plus the wire
  format pinning in `docs/api-stability.md`.
- how the parent schedules or cancels a child call. That lives in
  `docs/architecture/workflow-child-calls-architecture.md` and the
  `ChildCallService` / `ParentClosePolicyEnforcer` surfaces.
- the authoritative data store for a given row. The control-plane
  split contract names the authority boundaries for
  `workflow_runs`, `workflow_links`, and history events.
- non-child workflow outcomes. Activity outcomes, timer outcomes,
  and signal outcomes are each covered by their own authoring-call
  branches in the executor and by the execution-guarantees contract.

## Terminology

- **Parent run** — the workflow run whose authoring code issued a
  child workflow call. The parent run is identified by a
  `WorkflowRun` row plus its `historyEvents` collection.
- **Child call sequence** — the `sequence` value assigned to the
  child call in the parent's authoring cursor. The parent refers to
  the child by `(parent_run_id, sequence)`.
- **Parent history events** — rows in the parent run's
  `historyEvents` that describe the child-call slot:
  `ChildWorkflowScheduled`, `ChildRunStarted`, and one of
  `ChildRunCompleted` / `ChildRunFailed` / `ChildRunCancelled` /
  `ChildRunTerminated` if the child has resolved.
- **Resolution event** — any of the four parent-side terminal
  `ChildRun*` events. Resolution events carry the authoritative
  `child_status` and `output` or `exception` payload from the
  parent's perspective.
- **Child run** — the `WorkflowRun` row for the child workflow
  itself, loaded through `workflow_links` or via the ids on parent
  history events. The child run has its own `historyEvents`
  collection including its own `WorkflowCompleted` /
  `WorkflowFailed` / `WorkflowCancelled` / `WorkflowTerminated`
  terminal event.
- **Child link** — a `WorkflowLink` row with
  `link_type = 'child_workflow'` that connects the parent run's
  sequence slot to the child run. The link is mutable during the
  child's lifetime.
- **Authoritative** — the row the contract binds the engine to
  consult. When the contract says "parent resolution event is
  authoritative", no downstream reader may substitute a different
  row even if the other row appears more advanced.

## The resolution precedence

Every consumer of child-call outcomes MUST consult the following
rows in this exact order and MUST stop at the first row that
resolves the slot:

1. **Parent resolution event** — if the parent run has committed one
   of `ChildRunCompleted`, `ChildRunFailed`, `ChildRunCancelled`,
   or `ChildRunTerminated` for this `sequence`, that row is the
   authoritative outcome. The child's `child_status`, the `output`
   payload, and the `exception` payload all come from the resolution
   event itself, with the child run used only as fallback for
   payloads the resolution event does not carry inline.
2. **Parent open-slot block** — if step 1 did not resolve the slot
   and the parent run has committed a `ChildWorkflowScheduled` or
   `ChildRunStarted` event for this `sequence` but no resolution
   event yet, the slot stays open. The child run's DB state MUST
   NOT be used to synthesise a resolution because
   `ChildRunHistory::parentHistoryBlocksResolutionWithoutEvent()`
   returns `true`. Replay and query paths remain suspended at the
   child call and await a resolution event.
3. **Typed child terminal history fallback** — if the parent has
   open-slot parent history (step 2 holds) and ALSO has a child run
   row whose status is a terminal non-continued status
   (`Completed`, `Failed`, `Cancelled`, `Terminated`), the contract
   still binds step 2. The child-run terminal history is NOT
   promoted over the parent-side open slot; the parent resolution
   event must arrive through the normal typed-history path.
4. **Mutable open fallback** — if the parent has not committed any
   scheduled, started, or resolution event for this sequence, the
   slot falls back to the mutable child link plus the child run's
   current status. The child is treated as open while the child
   run is `Pending`, `Running`, or `Waiting`, and closed once the
   child run carries a terminal status.
5. **Unsupported terminal fallback** — if step 4 would resolve the
   slot as terminal but the parent has no typed history for the
   sequence at all, the slot is reported with history authority
   `HISTORY_AUTHORITY_UNSUPPORTED_TERMINAL` and the reason code
   `terminal_child_link_without_typed_parent_history`. This is a
   diagnostic surface only. Replay MUST NOT resume the parent
   through this fallback; operator surfaces MAY display the
   terminal status with an explicit "unsupported" marker.

The three history-authority modes carried on the
`ChildRunHistory::waitSnapshotForSequence()` snapshot are:

- `HISTORY_AUTHORITY_TYPED` (`'typed_history'`) — the slot was
  resolved or opened through parent-side typed history events.
  Replay, queries, and operator surfaces trust this slot verbatim.
- `HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK` (`'mutable_open_fallback'`)
  — the slot is still open and no parent typed history exists for
  it, so the link plus child run are used to describe an open
  child. The fallback MUST NOT be used to synthesise a resolution.
- `HISTORY_AUTHORITY_UNSUPPORTED_TERMINAL`
  (`'unsupported_terminal_without_history'`) — the child run is in
  a terminal non-continued status but the parent run has no typed
  history for the sequence. The contract surfaces this as an
  unsupported diagnostic condition rather than silently falling
  through to step 4.

## Payload authority

Once the slot is resolved, the output value and exception thrown to
the parent authoring code come from the precedence below. Consumers
MUST consult these in order and use the first payload present:

### Output payload

1. `output` on the parent resolution event payload.
2. `terminalEventForRun($childRun)->payload['output']` — the child
   run's own terminal history event.
3. `$childRun->output` — the mutable child run column.

`ChildRunHistory::outputForResolution()` implements steps 1-3 for the
resolution-event path. `ChildRunHistory::outputForChildRun()`
implements steps 2-3 for the follow-child-run path.

The child run's `payload_codec`, if set, is the envelope used to
deserialise the payload. The codec is always read from the child
run, never from the parent resolution event.

### Exception payload

1. `exception` on the parent resolution event payload
   (`is_array($resolutionEvent->payload['exception'])`).
2. `terminalEventForRun($childRun)->payload['exception']` — the
   child run's own terminal event exception payload.
3. `failureRow($childRun)` — the first `WorkflowFailure` row owned
   by the child run.
4. A synthesised failure carrying the class name
   `RuntimeException::class` with a message built from the resolved
   child status. Consumers MUST NOT invent a different fallback
   class.

`ChildRunHistory::exceptionForResolution()` and
`ChildRunHistory::exceptionForChildRun()` implement these
precedences. `FailureFactory::restoreForReplay()` is the single
canonical constructor for replay-restored throwables; consumers MUST
route through it and not `new RuntimeException(...)` inline.

### Parent-perspective message for cancelled or terminated children

When the resolved status is `RunStatus::Cancelled` or
`RunStatus::Terminated`, `exceptionForResolution()` replaces the
child-side message with a parent-perspective message of the form
`"Child workflow <run_id> closed as <status>"`, appending
`: <reason>` when the child's own terminal event carries a `reason`
string. This guarantees a deterministic, parent-readable message
regardless of whether the child-side cancel was a plain cancel or a
`cancel(reason)` call.

## Child-run identity resolution

When a consumer needs the child `WorkflowRun` model for a sequence
slot, `ChildRunHistory::childRunForSequence()` implements the
contract:

1. Collect the child run id from
   `resolutionEventForSequence`, `startedEventForSequence`,
   `scheduledEventForSequence`, and `latestLinkForSequence`
   (in that order). The first non-empty id wins.
2. If a child run id was collected, load the run through
   `ConfiguredV2Models::query('run_model', WorkflowRun::class)`
   with `summary`, `instance`, `failures`, and `historyEvents`
   eager-loaded.
3. If the run is not loaded but a child instance id is present on
   any of the above sources, load the current run for the child
   instance through `CurrentRunResolver::forInstance()`.
4. Apply `followContinuedRun()` to descend through any chain of
   `closed_reason = 'continued'` runs, stopping at the first run
   whose `closed_reason` is not `continued` or at a detected cycle.

`followContinuedRun()` uses a `$visited` cycle guard on the run id
so a malformed continue-chain cannot infinite-loop the resolver.

## Resolved status decoding

`ChildRunHistory::resolvedStatus()` decodes the `RunStatus` of a
child from the resolution event and the child run:

1. If the resolution event payload carries a `child_status` string
   and `RunStatus::tryFrom()` recognises it, that value wins.
2. Otherwise, the resolution event type maps directly:
   `ChildRunCompleted => Completed`, `ChildRunFailed => Failed`,
   `ChildRunCancelled => Cancelled`, `ChildRunTerminated =>
   Terminated`.
3. Otherwise, the child run's own terminal event type maps:
   `WorkflowCompleted => Completed`, `WorkflowFailed => Failed`,
   `WorkflowCancelled => Cancelled`, `WorkflowTerminated =>
   Terminated`.
4. Otherwise, the child run's current `status` column is used.

Consumers MUST route through this decoder and not read
`child_status` payloads directly so that legacy payloads missing
`child_status` are still decoded correctly from the event type.

## Consumers bound by this contract

Every read path that surfaces a child outcome MUST follow the
precedence above. The canonical consumers are:

- `Workflow\V2\Support\QueryStateReplayer::replayState()` —
  query-time replay that resolves a parent's in-flight child calls
  without dispatching commands.
- `Workflow\V2\Support\WorkflowExecutor` — the main decision-batch
  executor, including the single-child and parallel-group
  resolution branches.
- `Workflow\V2\Support\ParallelChildGroup` — the parallel group
  collector that reports aggregate child status for `all()`
  constructs.
- `Workflow\V2\Support\RunLineageView` — the operator surface that
  builds parent/child lineage trees and carries the history-authority
  mode through to Waterline and CLI consumers.
- `Workflow\V2\Support\RunWaitView` — the "why is this run waiting"
  surface that describes open child calls with their history
  authority.
- `Workflow\V2\Support\DefaultWorkflowTaskBridge` — the bridge
  that records parent history for resolved children inside the
  claim/complete path.
- `Workflow\V2\Support\RunSummaryProjector` — the projection that
  feeds operator metrics and list views with child-counts.
- `Workflow\V2\Webhooks` — lifecycle hook dispatch for child-run
  resolution, which uses the same precedence for payload selection.
- `Workflow\V2\WorkflowStub` — the workflow-client API that reads
  child outcomes for stored-resolve-result style callers.

Any new consumer that reports child outcomes MUST route through
`ChildRunHistory` rather than read `workflow_runs`, `workflow_links`,
or history events directly. Opening a new path that reads the raw
tables is a protocol-level change for the purposes of this contract.

## Parent-history blocking invariant

The invariant at the heart of this contract is:

> Once the parent has committed `ChildWorkflowScheduled` or
> `ChildRunStarted` for a sequence, no consumer may resolve that
> slot until the parent commits a matching resolution event, even
> if the child run row has advanced to a terminal status.

This keeps replay, queries, and operator surfaces consistent with
durable typed history and guards against the following races:

- a cross-run repair or cleanup process updating the child run row
  before the parent engine has reconciled the child back into its
  own typed history,
- a test that manipulates the child run directly and leaves the
  parent history untouched,
- a concurrent child worker racing the parent bridge and committing
  the child-side terminal event before the parent has reaped it into
  a `ChildRun*` resolution event.

The invariant is implemented by
`ChildRunHistory::parentHistoryBlocksResolutionWithoutEvent()` and
is consulted in every replay path. See
`QueryStateReplayer::replayState()` line 338 and
`WorkflowExecutor` parallel-group handling around the
`scheduledEventForSequence` / `startedEventForSequence` checks.

## Continue-as-new across child boundaries

When a child workflow calls `continueAsNew`, the child run row is
closed with `closed_reason = 'continued'` and a new run is created
for the same workflow instance. Continue chains are resolved by
`ChildRunHistory::followContinuedRun()`:

- descend through successive `continued` runs via
  `CurrentRunResolver::forRun()` until reaching a run whose
  `closed_reason` is not `continued` or until a cycle is detected
  by the `$visited` set;
- the descended-to run is returned to the caller, which then
  applies resolution precedence to that run's events.

The parent-side resolution precedence still controls whether the
slot is considered resolved. `followContinuedRun` only picks the
current run of the child instance; it does not decide whether the
parent has observed that run yet. A child may be in its third
continued run and still appear as open to the parent until the
parent commits a resolution event.

`ChildRunHistory::continuedFromRunId()` returns the
`continued_from_run_id` payload on the child run's
`WorkflowStarted` event so continue chains can be traversed
upward from a child run when building lineage views.

## Parent reference and child-call id

`ChildRunHistory::parentReferenceForRun()` returns the parent
coordinates for a child run:

- `parent_workflow_instance_id` — from the child's
  `WorkflowStarted` event payload, falling back to the child's own
  `workflow_instance_id` when the parent payload is missing.
- `parent_workflow_run_id` — from the child's `WorkflowStarted`
  event payload only. If the child has no parent run id, the method
  returns `null` and the run is treated as a top-level run.
- `parent_sequence` — the parent-side sequence slot that scheduled
  the child.
- `child_call_id` — the shared id used by parent and child to
  coordinate a single logical child call across retries.

`ChildRunHistory::childCallIdForSequence()` and
`ChildRunHistory::childCallIdForRun()` resolve the child-call id
with the same precedence used for run identity: resolution event,
started event, scheduled event, link, then legacy payload fallbacks
on the parent event payload.

Consumers that emit new parent-side events for a child slot MUST
carry the resolved `child_call_id` on the event payload so the
precedence continues to resolve to the same logical call across
continue-as-new boundaries and retries.

## Waterline and CLI surfacing

The operator view contract:

- Waterline's run-detail child lineage block MUST render the
  `history_authority` returned by `waitSnapshotForSequence()` as
  either "typed history", "open (mutable)", or an
  "unsupported terminal" warning with the reason code.
- The CLI `dw run describe` child-lineage formatter MUST surface
  the same authority labels so operators can tell whether a
  displayed outcome came from typed history or from the mutable
  fallback paths.
- `unsupported_terminal_without_history` is an operator-visible
  diagnostic condition. It is NOT a hard failure for the parent
  run, but it indicates that the terminal child state reached the
  operator without a matching parent resolution event and needs
  repair or explicit acknowledgement.

Aggregate roll-ups:

- `RunSummaryProjector` counts typed-history-resolved children
  separately from mutable-open children. Ready counts and waiting
  counts MUST NOT merge across authority modes.
- `ParallelChildGroup` aggregate status for an `all()` call is
  decided by applying this contract to every leaf sequence first
  and THEN combining; the aggregate MUST NOT short-circuit a leaf
  through the mutable fallback when the parent slot is blocked.

## Test strategy alignment

- `tests/Feature/ChildWorkflowSignalingTest.php` and
  `tests/Feature/ParentContinueAsNewChildWorkflowTest.php`
  exercise the end-to-end resolution behaviour across child
  completion, failure, cancel, terminate, and continue-as-new.
- `tests/Feature/V2/V2ChildWorkflowNamespaceProjectionTest.php`
  pins the namespace-aware projection path for child resolution.
- `tests/Feature/V2/V2ParentClosePolicyTest.php` pins the
  `ParentClosePolicy` interaction with this contract, including
  the lineage surface carried by `RunLineageView`.
- `tests/Unit/V2/ChildCallServiceTest.php` pins the parent-side
  scheduling path that produces the events consulted by this
  contract.
- `tests/Feature/V2/V2QueryWorkflowTest.php` pins the query-time
  application of the precedence — parent-history blocking holds
  across an advanced child run.
- This document is pinned by
  `tests/Unit/V2/ChildOutcomeSourceOfTruthDocumentationTest.php`. A
  future change that renames, removes, or narrows any named
  guarantee (the five-step resolution precedence, the three
  history-authority modes, the payload precedence for output and
  exception, the parent-history blocking invariant, or the
  continued-run traversal rule) must update the pinning test and
  this document in the same change so the contract does not drift
  silently.

## What this contract does not yet guarantee

The following are explicitly deferred to later roadmap phases and
must not be assumed:

- A parent-initiated `ChildRunResumed` event for re-observed open
  children is not part of this contract. The open-slot
  precedence above is the sole in-flight path.
- Cross-instance child-run routing (sending a parent run on one
  workflow database to a child run on another) is outside the
  contract. `parent_workflow_run_id` is assumed to live on the
  same workflow database.
- Archived runs are outside this precedence. When a child run has
  been archived, the parent resolution event payload is the sole
  source of truth; consumers MUST NOT rehydrate the archived child
  run mid-resolution.

## Changing this contract

A change to any named guarantee in this document is a protocol-level
change for the purposes of `docs/api-stability.md` and downstream
SDKs. Reviewers should treat unmotivated changes to the language
above as breaking changes and require explicit cross-SDK
coordination before merge. Phase 1 (execution guarantees) and
Phase 4 (control-plane split) own the upstream boundaries this
contract reads from; any change to this contract must preserve the
authority boundaries those phases froze.
