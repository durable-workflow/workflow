# Cooperative cancellation model

Design work for [the shared cancellation issue](https://github.com/durable-workflow/.github/issues/136).
This document describes the next model. It does not advertise these additions
as released capabilities.

## Customer outcome

A cancellation request should let an application stop work and recover its
resources within an explicit budget. The user should be able to inspect the
request, its propagation, cleanup progress and final outcome. Worker crashes,
replay and duplicate requests must preserve that meaning.

The cancellation model must demonstrate useful advantages over competing
products before the shared issue closes. Passing portable compatibility checks
alone does not establish that outcome.

## Preserve existing contracts

`WorkflowStub::requestCancellation()` is cooperative. `cancel()` immediately
closes a run and revokes outstanding durable work. `terminate()` also closes
the run immediately with a distinct outcome. None of these can roll back an
external side effect.

Existing `ParentClosePolicy::RequestCancel` snapshots use `request_cancel` and
currently issue terminal cancellation. Preserve that recorded contract during
replay. The candidate `ParentClosePolicy::RequestCancellation` uses
`request_cancellation` for cooperative cleanup. The enum marks the historical
terminal policy clearly and retains its old meaning.

Parent-close policy and per-operation cancellation policy answer different
questions. The former controls a child after its parent closes. The latter
controls what an awaiting workflow does when its scope receives cancellation.

## Request identity and budget

Each target run has a local request ID used to validate delivery and ownership.
A propagated request additionally carries the root request ID, root run,
immediate parent request and target run. Do not replace local delivery identity
with the root ID.

Keep reason, requester, source, original requested-at and root cleanup deadline
in canonical history. A descendant receives the remaining budget. Propagation,
retry, continue-as-new and worker replacement must never grant a fresh budget.

The first accepted request on a run owns its local identity, root, delivery
route and deadline. A later direct request returns that original request.
Propagation from the same root also returns the original local request, even
when another recorded parent supplies a different route. Propagation from a
different root returns `cancellation_root_conflict` with the existing and
incoming identities and deadlines. It does not replace either request or
extend the accepted budget. This conflict does not mean the two independent
cascades became one tree. Awaiting-operation policies must report the conflict
and remain bounded by their original budget. Reproducible simultaneous-request
database tests remain required before publication.

Workflow code receives an immutable cancellation context. Its `deadline()` and
`remaining()` helpers use deterministic workflow time. Worker control checks
use runtime authority and wall-clock deadlines. A replay must not take a
different authored branch because the host clock advanced.

The candidate Native implementation records a versioned `cancellation` snapshot
in the accepted command and canonical request history. A root request's local
and root IDs are equal. `CommandResult::cancellationContext()` exposes this
snapshot to the caller, and `WorkflowCancellationRequestedException::cancellation`
exposes it to workflow cleanup. The object contains reason, requester, source,
original requested-at, immutable deadline and lineage. Requester metadata is
restricted to caller type, ID and label.

`remaining()` is bound to the Fiber that received canonical delivery. Detached
metadata, ended replays and other workflow Fibers cannot borrow its clock. The
binding retains neither context metadata nor the Fiber and leaves portable
serialization and value equality unchanged.

`remaining()` reads the consumed blocking-boundary clock. It starts at recorded
delivery and advances through awaited cleanup outcomes. Synchronous side effects,
version markers, memo updates and search-attribute updates preserve it because
the service runner returns their first results before persistence. A later
persistence timestamp cannot change the same authored decision during cold
replay. The existing `now()` clock keeps its current behavior.

Fractional seconds are preserved, expiry clamps to zero, and recorded clock
skew cannot increase the consumed budget. Missing blocking timestamps and calls
outside an active workflow Fiber fail without a host-time fallback. The runtime
supervisor independently enforces the actual deadline. Older histories that
lack the snapshot keep their existing delivery behavior and expose a null context.

### Cleanup outcome

Active cleanup uses renewable ownership windows of at most ten seconds, or the
configured workflow lease when it is shorter. Each window also ends at the
original cancellation deadline. Canonical delivery changes the hosting claim
to this cleanup window. Replacement claims, workflow heartbeats and local
callback supervision preserve the same bound. Ordinary workflow lease settings
remain unchanged.

Prepared local callback supervision renews the task and Activity attempt in
one transaction without requiring an application heartbeat. A dead worker
therefore leaves a reclaimable ownership window within a longer cleanup
budget. Recovery retains the original request, delivery boundary and deadline.
Lease expiry fences publication and still leaves physical callback stop state
unknown until a matching worker reports it. Replacement availability, polling
and queue repair affect recovery time. An exhausted budget ends as
`deadline_expired` rather than granting another cleanup window.

The candidate cooperative `WorkflowCancelled` terminal event includes an
optional `cancellation_cleanup` object. It retains the local `request_id`,
original `cleanup_deadline_at`, `finished_at` and, when the canonical delivery
matches that request and authored sequence, `delivery_history_event_id` and
`delivery_sequence`. Duplicate requests leave this terminal record intact.
Legacy terminal cancellation and historical events can omit the object.

| Outcome | Meaning |
| --- | --- |
| `completed` | Workflow cleanup reached a terminal boundary after the matching canonical cancellation delivery and before the original deadline. |
| `deadline_expired` | The original cleanup deadline was reached. This applies whether or not cancellation had been delivered to workflow code. |
| `not_delivered` | The run closed before the deadline without a recorded cancellation delivery. Ordinary shielded cleanup can still have run. |
| `unavailable` | Delivery history and its run projection disagree. The terminal record does not claim cleanup completion. |

These outcomes describe workflow cleanup. Per-operation policies and callback
stop receipts separately establish whether activities stopped or were
abandoned. A completed workflow cleanup does not establish reversal of external
effects. Termination remains a distinct run outcome. Losing a worker lease
describes that attempt's authority and does not establish that its process
stopped or that the replacement cleanup failed.

### Cascade inspection

The Source `CancellationCascadeView::forRun()` candidate resolves the exact
selected run and its original root. It provides one request budget, related
run nodes and child or continuation edges. It scopes each run and instance
to the selected namespace before loading history. An unavailable relation has
no target ID or target metadata in the response. Selecting a historical run
does not follow its instance's current-run pointer.

Each node reports the canonical terminal event separately from projected run
status. It retains local request identity, the original root budget, authored
delivery range, cleanup outcome, child propagation, activity fences, matching
worker stop reports and cleanup attempt recovery. An independent child request
keeps its own identity and budget, with the parent's canonical propagation
conflict visible. A parent-close origin preserves the parent's ordinary
terminal outcome while explaining its children's shared cancellation budget.

The lifecycle describes requested, delivered, cleaning up, cancelled,
deadline expired or a distinct completed, failed, timed out or terminated run.
Cleaning up requires a canonical activity or timer command after the recorded
cancellation boundary. Lease expiry alone does not establish this phase.
Callback reports are labelled `reported_stopped`. A fence without a matching
receipt has unknown callback stop state. Recovery retains that unknown state
without inventing a process-kill cause.

The response uses `durable-workflow.cancellation-cascade/v1`. Its initial limits
are 20 runs, 100 relations, 128 recent relevant history events per run and 512
recent events overall. Original requests are also resolved separately, with
at most two request rows per lookup, so the original budget can survive an
exceeded recent-history window. Request text is capped at 8192 bytes per field.
Missing, conflicting, inaccessible or truncated evidence sets
`inspection_complete: false` and supplies a named finding with an explanation.
`inspection_complete` describes the evidence inventory, not successful cleanup
or agreement between independently cancelling roots. Server, CLI, Waterline
parity and published qualification remain separate gates for this candidate.

The candidate Native request primitive
`attemptRequestCancellationFromParent()` reads its parent's accepted context
from storage and requires a recorded direct child link to the selected current
run. It assigns a distinct local request ID, appends lineage, and inherits the
root identity, requester, reason, original requested-at and deadline. It does
not immediately terminate the child. A late propagation retains an already
expired deadline so repair can close it without granting another budget.
Unlinked parents, ancestor cycles and legacy parents without the context
capability receive explicit diagnostics. Transaction retries clear their
by-reference results before retrying.

Cooperative parent-close enforcement uses that same primitive. It does not
mark the child call cancelled before canonical child completion. If the parent
already accepted cancellation, every child inherits that original root and
deadline, including a deadline that has expired. A child with an independently
accepted root keeps it and produces a recorded `cancellation_root_conflict`.
The parent history and lineage view expose the request and rejection details.

A parent that closes without accepting cancellation records one policy-owned
`ParentCloseCancellationRequested` origin. This event leaves the parent's
completed, failed, cancelled or terminated outcome intact. Its original request
time is the canonical closing event's recorded time. Its default cleanup budget
is 600 seconds from that event, shared by all affected children. Enforcement
after a delay cannot grant another 600 seconds. Repeated enforcement preserves
the origin and each accepted child request without appending duplicate applied
receipts. A mutable terminal status without canonical closing history cannot
create this budget. The parent run lock serializes origin creation and receipts.

The candidate child-operation policy now propagates from cancellation delivery.
PHP, Python and Rust source drafts expose both child policies and preserve their
identity through cold replay. Connected PHP qualification uses one workflow
worker for parent and child, including a replacement after a released child
wait. Activity policies, simultaneous-root qualification, continuation behavior
and the complete published cascade remain required before this model is
published.

## Operation policies

Activities and children need three explicit choices:

| Policy | Durable behavior |
| --- | --- |
| Try cancel | Request cancellation and release the await immediately. Cleanup can overlap work that has not yet stopped. |
| Wait for cancellation completion | Request cancellation and hold the await until canonical acknowledgement or a defined terminal outcome, bounded by the original cleanup budget. |
| Abandon | Release the await without requesting cancellation of the operation. Detached work remains inspectable. |

Define defaults, child propagation, retry behavior and recorded policy identity
before adding SDK options. Changing a policy on replay must not reinterpret an
operation already recorded under another policy.

The candidate Native child API exposes `CancellationPolicy::TryCancel`,
`WaitCancellationCompleted` and `Abandon` through
`ChildWorkflowOptions::cancellationPolicy`. The default is `Abandon`, preserving
the existing behavior. Each new child schedule records the selected policy.
Replay reads that history, including an `Abandon` fallback for older schedules
that have no policy field.

`TryCancel` durably requests child cooperation before delivering cancellation
to parent code. `WaitCancellationCompleted` additionally parks the parent until
the child has canonical terminal history. Mixed parallel waits and selected
child handles apply the same rule. A terminal child projection without terminal
history does not acknowledge completion. Waiting cannot extend the parent's
original deadline. An independently cancelling child keeps its own accepted
root and deadline, and the propagation conflict remains visible.

Canonical `ChildCancellationRequested` and `ChildCancellationResolved` events
record the policy, parent and child identities, original budgets, conflicts and
terminal history reference. The run timeline exposes these details with the
child outcome. This is a durable workflow outcome, not proof that every external
callback stopped.

The portable command bridge accepts the same child policy and returns
`delivered: false` with `cancellation_waiting_for_child` while acknowledgement is
pending. It parks the parent and completes the current task claim atomically,
returning `claim_released: true`. The SDK leaves that claim without publishing
completion or failure. This frees a single worker to execute the child rather
than occupying it with a blocking wait. Child terminal history wakes the parent
for a new claim, which replays the same authored cancellation boundary.
First-party SDK drafts handle this pending state. Their source checks and the
connected single-worker PHP test do not qualify a published child-policy tuple.

An expired lease proves loss of authority to commit a durable result. It does
not prove that a remote process stopped or that an external system performed
an undo. Waiting must distinguish callback acknowledgement, durable fencing and
external reconciliation.

### Remote callback-stop acknowledgement

The candidate internal `ActivityCancellationAcknowledgement::recordStopped()`
records a remote owner's callback-stop report separately from `ActivityCancelled`.
The latter fences durable publication. It does not establish that a callback
has stopped. An SDK may send the new report only after stopping and joining its
callback. `ActivityCancellationAcknowledged` names that worker report and the
server's receipt time, with `evidence_source: activity_worker`. It does not
describe reversal of external effects.

The report requires the original Server-issued activity attempt, lease owner
and local cancellation request. Remote claims use that attempt identity and
do not require the separate worker attempt ID used by local activities.
Current rows and the canonical
cancellation snapshot must match every fence. Mutable cancelled rows without
that history, a snapshot without its attempt identity, another owner or a
replacement attempt cannot authorize the report. The run lock serializes
duplicates, which return the original acknowledgement event. No report renews
a lease, restores result authority or grants a new cleanup budget.

The receipt preserves the original root identity and deadline. A late report
has `received_after_deadline: true`, so an expired budget cannot appear to have
completed on time. The timeline identifies the original activity attempt and
explains the worker's stop report. Its `cancellation_acknowledgement` metadata
retains the local and root request IDs, original deadline, cancellation event,
receipt time and whether the receipt was late. Projection of this diagnostic
event must remain safe during cleanup, repair and stale publication refusal.
Local callback acknowledgements need the workflow task's distinct authority
and are explicitly refused by this remote primitive. The Server remote route
and PHP, Python and Rust stop/join emission have connected source qualification.
Python uses a separate callback process supervised independently of application
progress. The complete published cascade and activity waiting policies remain
required.

### Portable local callback authority

The candidate `PortableLocalActivityPreparation` kernel records local Scheduled
and Started history before callback admission. It preserves encoded inputs,
the original workflow task and attempt, a Server-issued activity attempt ID,
the SDK attempt ID and the original deadlines. A same-claim retry after response
loss returns that preparation. Changed descriptors, a reclaimed claim,
unshielded cancellation or expiry refuse invocation. This is an internal protocol 1.20
primitive. Published protocol 1.19 keeps its existing local execution path.

Before preparation, `checkpointLocalActivityPrefix()` can atomically commit
earlier nonterminal commands while retaining the workflow claim. Each batch is
bounded to 100 commands. Its latest receipt is stored on that claim, including
the normalized command fingerprint and authored sequence range. A lost response
can be retried before sending a subsequent checkpoint. Changed contents or
ownership cannot relabel a receipt or repeat child creation, side effects or
memo updates. Checkpointing does not renew the lease or admit application code.
An accepted cancellation refuses new prefix work until its delivery is recorded.
After delivery, the retained claim can checkpoint cleanup commands before the
original deadline. A checkpoint receipt alone never admits a callback.
The SDK must replay committed history before submitting later commands.

The separate optional `PreparedLocalActivityGroupTaskBridge` adds an unfrozen
Source `checkpointLocalActivityGroup()` operation for complete `all` groups.
Its bounded batch uses `prepare_local_activity` descriptors without fabricated
results. The transaction commits every sibling and local Scheduled event,
retains the original workflow claim and stores the receipt before returning.
Local members are pending with no callback attempt. Their total budget starts
at admission, and the canonical `local_group_admission` records descriptor and
batch fingerprints, the checkpoint identity and original claim epoch.

Preparation separately validates that durable admission before starting a local
attempt. Replacement before preparation creates its own first attempt without
inventing recovery or a stop receipt. A lost group response cannot create another
child or extend a deadline. Partial groups, changed descriptors, wrong claims
and unqualified cleanup proofs refuse without creating siblings. Nested `all`
paths are retained through Started and outcome history. Selection groups remain
refused until their policy and physical-stop behavior are qualified. Existing
custom prepared bridges do not acquire the optional group role from an alias.
Server admission, SDK group consumers and connected mixed-language qualification
remain required before advertising or publishing this Source extension.

A shielded cleanup descriptor explicitly supplies `cancellation_cleanup` with
`request_id` and `delivery_history_event_id`. Admission validates that request
and canonical delivery on this run and requires a later authored command
sequence. Workers cannot supply or extend a cleanup deadline. The runtime
records the original root identity and deadline in the execution and Started
authority, and bounds local execution deadlines by that budget. Retries and
replacement-worker recovery retain the same delivery and budget. Pre-request
activities remain fenced. A cleanup result at or after the original deadline
is refused, and a supervisor stop instruction still requires a separate joined
callback report.

The candidate local stop receipt uses the saved Started snapshot and the
canonical cancellation fence. The original workflow owner can report stop after
task takeover without changing the replacement claim. A missing or
unqualified post-cancellation preparation cannot authorize a stop report. Local history and
timeline retain the original workflow claim rather than inventing an ordinary
activity queue task.

`recordPortableOutcome()` commits results against that prepared attempt. It
preserves encoded Avro bytes and reuses the existing local failure, retry and
timeout recorders. A repeated report returns its original canonical receipt
after claim takeover, expiry or later cancellation. Changed reports cannot
replace an outcome. A retry creates one durable workflow task and releases the
hosting claim. It preserves the original total activity deadline.

An accepted cancellation can fence the original local attempt without changing
a replacement workflow claim. This refuses result publication. It does not
acknowledge physical callback stop. The original owner must separately report
that its supervisor has stopped and joined the callback.

Retry preparation validates the canonical retry event, its hosting workflow
task, original descriptor and backoff before allocating a distinct attempt.
It shares the embedded attempt recorder without renewing the hosting claim.
The SDK must use a fresh attempt identity. A lost preparation response returns
the same current attempt. The total deadline remains recorded in Started
history. An expired total budget records a terminal timeout without admitting
another callback. A renewed workflow claim cannot readmit an expired local
attempt.

`recover()` records an interrupted prepared attempt under a valid replacement
claim. It requires the original Started authority, an expired attempt lease
and loss of the original workflow claim. It records that attempt as Expired and
uses the existing retry or terminal recorder. Timeout and retry exhaustion
remain authoritative. Recovery never admits a callback or grants a new total
deadline. A scheduled retry releases the replacement claim for polling, while
a terminal outcome retains it for replay. Response loss returns the original
recovery receipt after takeover or later cancellation.

For an admitted local group, recovering another interrupted member may create
a successor workflow task before any callback resumes. An earlier member can
prepare on that final claim only through the recorded sibling retry chain.
Every link must belong to the same original admission batch, retain its original
attempt authority, and follow a completed hosting task. Unrelated claims,
cycles, missing links and changed batches are refused. Each member still keeps
its own recorded backoff and total deadline, and cleanup keeps the original
cancellation delivery and deadline.

The recovery receipt and timeline retain the original and replacement claims
and explicitly report callback stop as unknown. Lease expiry fences publication
and does not establish physical stop. An accepted cancellation fences the
prepared attempt immediately without waiting for expiry, changing the
replacement claim or fabricating an acknowledgement.

The optional `PreparedLocalActivityTaskBridge` exposes checkpoint, preparation,
outcome, recovery, supervisor control, application heartbeat and stop-receipt persistence through the existing workflow
bridge binding. Consumers must check the actual bound instance. An existing
custom workflow or cooperative bridge does not acquire this role from an alias.
The published protocol default still refuses these candidate operations. This
role does not qualify physical SDK supervision.

`controlLocalActivity()` polls the original prepared attempt independently of
application heartbeats. Active status polling alone is read-only. Optional
renewal commits the hosting workflow lease and local attempt lease together
under the original canonical owner and workflow epoch. It never records an
application heartbeat or renews the start-to-close, total, heartbeat, run or
cancellation deadline. Expired authority cannot be revived by polling.

`heartbeatLocalActivity()` records an actual application heartbeat using the
same prepared authority checks and bounded progress format as other activities.
It updates heartbeat time and timeout and records canonical heartbeat history.
Prepared group heartbeats retain the complete authored membership path, including
nested groups. SDK heartbeat details use the existing `progress.details` format
and survive in canonical history.
It does not renew either lease or move start-to-close, total or root deadlines.
Cleanup heartbeat timeouts and supervisor lease renewals stay bounded by the
original cleanup deadline. Neither path revives expired authority. SDKs must
keep the latest acknowledged heartbeat timeout separate from fixed execution
deadlines when validating control responses.

For a pre-request activity, accepted cancellation returns its original context even to the original
supervisor after takeover. It fences publication without changing the
replacement cleanup claim. The supervisor must stop and join its callback,
then report the separate acknowledgement. A fence or stop instruction is not
physical-stop evidence. A prepared cleanup call continues only under its
recorded delivery, original budget and live claim. Server admission for cleanup
and application heartbeats and SDK control loops still need connected qualification.

Server admission and SDK physical local supervisors must connect these
primitives, dispatch created work and qualify response loss, cancellation and
worker replacement together. A portable SDK must not reconstruct already
prepared local rows from a posthoc completion report.

Activity `WaitCancellationCompleted` must wait for this callback acknowledgement
or a prior canonical completion. Lease expiry alone leaves stop state unknown.
The Source Native kernel now uses `ActivityCancellationCompletion` to distinguish
the latest attempt's canonical callback outcome, an atomically fenced operation
that never started, and the original owner's stop receipt. A timeout, cancellation
row or receipt for another owner/attempt/request/root/deadline does not resolve
this wait. An outcome recorded after its cancellation fence cannot replace the
stop receipt.

An internal recorded activity policy can park the awaiting workflow and complete
its hosting claim while the callback stops. The completed claim retains the
original requested delivery boundary, root and deadline. All required receipts
must be present before one fresh workflow claim resumes that boundary. Receipts
arriving after the original deadline remain late evidence and do not create a
fresh cleanup claim or budget. Remote SDK policy admission and detached
`Abandon` have separate connected Source qualification. Prepared-local policy
admission and its consumers remain separate gates. Internal kernel tests do
not qualify consumer APIs or physical SDK supervision.

An `Abandon` activity must remain independently tracked and capable of finishing
after the awaiting scope is cancelled. Implement its retention and terminal-run
behavior deliberately rather than routing it through the normal terminal
activity fence. These operation semantics match the supported policy choices
in [Temporal's activity cancellation contract](https://docs.temporal.io/develop/typescript/workflows/cancellation).
DW's independent cancellation observation and original bounded cascade remain
the advantages to qualify.

The internal Source remote lifetime now reads `Abandon` from the canonical
`ActivityScheduled` snapshot and captures the original absolute
`schedule_to_close_deadline_at` with it. Detachment requires that finite total
budget. Cooperative parent closure preserves the activity task and attempt.
The original owner can continue and commit a canonical outcome after the
parent closes, including after the parent's cleanup deadline. The activity's
own deadline remains unchanged. Outcomes and timeout closure do not create a
workflow task or reopen the cancelled parent. Failure retries and expired-owner
recovery retain the same total budget and reject stale publication.

Retention holds the parent detail and external payloads until canonical terminal
history resolves the latest activity attempt. A mutable completed row is
insufficient. `WorkflowRunRetentionCleanup::retentionHoldReason()` lets hosts
check this before reclaiming objects, and `pruneRun()` also enforces the hold.
An older backend that cannot evaluate this policy must preserve its records and
return an explicit unsupported-backend diagnostic until a compatible backend
is restored. Legacy terminal cancellation and termination retain their authority
revocation contracts.

### Prepared-local policy admission

The Source preparation descriptor accepts explicit `try_cancel` and
`wait_cancellation_completed`. It persists the selected policy before callback
admission and retains it in canonical Scheduled/Started snapshots and the
descriptor fingerprint. A response-loss retry cannot change that policy.
Omission preserves the historical TryCancel behavior.

TryCancel fences publication and releases the durable await without claiming
physical callback settlement. WaitCancellationCompleted releases the hosting
claim while the matching original-owner stop receipt is missing. That receipt
resumes one successor workflow claim at the same authored delivery boundary,
retaining the original root identity and deadline. Expired authority or a
wrong-owner receipt cannot substitute for callback-stop evidence.

`DefaultWorkflowTaskBridge::supportedLocalActivityCancellationPolicies()`
reports the policies supported by the actual installed prepared-local bridge.
A custom implementation of the older optional role does not acquire this
policy admission capability from an alias. Server discovery/admission and
PHP/Python/Rust authoring, replay and physical supervision require separate
qualification before the candidate can be published.

Local `Abandon` is refused before callback admission, including with a finite
timeout. The callback currently depends on its hosting workflow claim. It
cannot acquire an independent lifetime by changing the policy field. Use a
remote Activity for independently tracked work. No local-to-remote conversion
is implicit. A full local Abandon lifetime and independently cancellable
subtree scopes require separate authority. The
[hierarchical scope decision](hierarchical-cancellation-scopes.md) defines the
chosen durable operation-tree boundary, canonical membership, shield inheritance,
shared local claim handling and the implementation/qualification gate. The current
source tuple does not implement or advertise those scopes.

## Lifecycle and diagnostics

Expose requested, delivered and cleaning-up progress without guessing from a
run's terminal status. Record cleanup completion, deadline expiry, termination
and loss of authority explicitly. Define whether these outcomes describe the
workflow, an operation or one worker attempt.

API, CLI and Waterline should present the same request lineage, original budget,
pending operations and safe next action. Capability rejection should identify
the unsupported worker or SDK and the required capability.

## Evidence required

### Required mixed-language cascade before closure

One isolated stack must execute this complete scenario using exact published
Native, Server and PHP/Python/Rust artifacts:

```text
Parent PHP workflow
  ├── Python child workflow
  │     └── Rust remote activity
  └── PHP local activity
```

Request cancellation once with a cleanup deadline 30 seconds after the
original request. Require all of the following in the same run:

1. Parent, child and activities expose one root cancellation identity and the
   original deadline. Local delivery and attempt identities remain distinct.
2. The Python child receives a genuine cooperative request and enters its
   authored cleanup. Parent propagation must not immediately close the child.
3. Both activity callbacks stop without application heartbeats. Capture actual
   callback-stop observations and SDK/runtime acknowledgements separately from
   durable fencing. A stale activity attempt cannot publish a result.
4. SIGKILL a workflow worker during cleanup. A fresh replacement worker replays
   the same canonical delivery boundary and resumes cleanup. Use supported
   published lease and recovery settings, without editing lease rows or
   substituting a virtual clock.
5. Repeat the cancellation request before and after recovery. Each duplicate
   returns the original identity and deadline, without granting a new budget.
6. Cleanup completes and all workflow runs converge to `Cancelled` before the
   original deadline. Deadline expiry cannot substitute for successful cleanup
   and recovery in this scenario.
7. One API/UI view explains the cascade: root request, original deadline,
   lineage, activity stops and fences, worker loss and replacement, cleanup
   progress, and final outcomes.

Retain raw observations, canonical histories, commands, package versions,
image digests and the inspection response. Independently passing language
tests or source-only qualification cannot substitute for this published
end-to-end result. Shared issue 136 remains open until this gate and its
competitive-strength decision are satisfied.

### Broader contract qualification

Qualify the exact published Native, Server and PHP/Python/Rust artifacts. Cover
request-before-claim, in-flight local and remote work without application
heartbeats, every operation policy, nested shielding, parent/child propagation,
duplicate and competing requests, worker loss, cold replay, cleanup expiry and
termination. Verify canonical history and reject late results from lost owners.

Use current first-party competitor contracts and their supported configurations:

- [Temporal operation policies](https://docs.temporal.io/develop/typescript/workflows/cancellation)
  and [nested scopes](https://docs.temporal.io/develop/typescript/workflows/cancellation-scopes).
- [Restate cancellation and recursive call propagation](https://docs.restate.dev/services/invocation/managing-invocations).
- [DBOS workflow cancellation](https://docs.dbos.dev/python/tutorials/workflow-management)
  and [preemptible async steps](https://docs.dbos.dev/python/reference/contexts).

Exercise competing implementations where a behavior or timing comparison
depends on execution. Distinguish a documented contract from a measured result.
Report genuine advantages and remaining tradeoffs. Do not equate a competitor's
default configuration or absent documentation with its strongest supported model.
