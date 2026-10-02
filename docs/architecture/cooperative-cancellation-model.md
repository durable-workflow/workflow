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

`remaining()` reads deterministic workflow time and refuses calls outside a
workflow Fiber. Older histories that lack this snapshot keep their existing
delivery behavior and expose a null context.

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
Local callback acknowledgements need the workflow task's
distinct authority and are explicitly refused by this remote primitive. The
Server route and PHP/Rust emission are implemented in source drafts. Connected
qualification remains required. Python callback supervision, local
acknowledgement and activity waiting policies still need implementation.

Activity `WaitCancellationCompleted` must wait for this callback acknowledgement
or a prior canonical completion. Lease expiry alone leaves stop state unknown.
An `Abandon` activity must remain independently tracked and capable of finishing
after the awaiting scope is cancelled. Implement its retention and terminal-run
behavior deliberately rather than routing it through the normal terminal
activity fence. These operation semantics match the supported policy choices
in [Temporal's activity cancellation contract](https://docs.temporal.io/develop/typescript/workflows/cancellation).
DW's independent cancellation observation and original bounded cascade remain
the advantages to qualify.

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
