# Hierarchical cancellation scopes

Design decision for [shared cancellation work](https://github.com/durable-workflow/.github/issues/136)
and [the Native implementation](https://github.com/durable-workflow/workflow/pull/603).
User-facing scope execution is not implemented or advertised by the current
source tuple. Native has an internal canonical
registration kernel: `CancellationScopeHistory` records `CancellationScopeOpened`
at a typed durable command position under the configured storage connection
and matching live claim. It preserves the recorded parent and shield mode on
fresh reads and replacement-claim replay, and includes those facts in the
history timeline. The optional internal `CancellationScopeTaskBridge` now
exposes this kernel through `openCancellationScope()`. Candidate Server endpoints
authenticate scope opening, prefix checkpointing, preparation and delivery.
No SDK scope capability is enabled by that foundation. The database tests exercise
the real bridge with authoritative claim fixtures, not an end-to-end SDK scope
execution.
Protocol 1.20 remains unfrozen until the authority and replay contracts below
have an implemented, qualified consumer.

## Outcome and chosen boundary

An application can cooperatively cancel one durable operation subtree while
unrelated and shielded operations continue. The application can inspect each
request, its original budget, callback stop evidence and cleanup outcome after
a worker restart. Handling a scope cancellation can let the enclosing workflow
complete successfully. Cancelling the workflow still cancels the run.

Use an explicit tree of durable operation scopes. Scope membership belongs to
the scheduled operation and survives the lexical block that created it.
Nested activity/child/timer groups and their returned handles retain that
membership. A lexical authoring helper can assign the current scope, but a
Fiber-local flag alone is not the durable scope.

This provides independently cancellable operation trees without introducing
implicit child workflow runs or a second workflow scheduler. It does not add
concurrent arbitrary workflow-code callbacks. Temporal's concurrent lexical
scope ergonomics remain a competing strength. Do not claim API equivalence
from a scope wrapper around today's sequential workflow Fiber.

Preserve existing `DurableOperationHandle::cancel()` and terminal routes.
The new cooperative entry point is `requestCancellation()` on the scope.
It must not delegate to terminal handle cancellation. Operations outside a
scope continue to use the existing run boundary and historical defaults.

## Canonical membership

Opening a scope is a durable authoring command. Its canonical history records
an opaque scope ID, the owning exact run, its parent scope, authored creation
boundary and whether it shields parent propagation. The implicit root denotes
the run. An SDK obtains the recorded identity on first execution and cold
replay. It cannot manufacture a new identity from host time or process state.

Scope creation must reject a foreign run, an unknown parent, duplicate authored
identity or a cycle. Replaying a creation with a changed parent or shield mode
is a nondeterminism failure before work admission.

The optional opening bridge requires candidate protocol 1.20, the exact issued
workflow task, its live lease owner and attempt, and the next authored command
sequence. It locks the owning run before the task, then invokes the canonical
kernel. A successful receipt contains the recorded scope and history identities,
parent, shield mode and sequence. Opening retains the workflow claim, creates no
operation and grants no new lease or cancellation budget. The SDK must receive
that durable acknowledgement before entering the scope body.

A response-loss retry or replacement claim returns the same scope and history
event, marked as a duplicate. It cannot change the recorded parent or shield.
Expired, stale, wrong-namespace, non-workflow or foreign-run claims are refused
before mutation. Malformed or unsupported requests have explicit reasons.
Existing bridge adapters need not implement this optional role. Server must
report that absence before accepting scope authoring. Default protocol 1.19,
scope delivery, scoped clocks and physical supervision remain unchanged.

Each scheduled leaf records its immediate scope ID. Descendant membership is
resolved through canonical parent links. A prepared local descriptor includes
that identity in its admission fingerprint, Scheduled and Started snapshots.
Child, remote activity, timer and selection/group history retain it too.
Response loss, retry, selection and replacement cannot reparent work.

The internal prepared-local descriptor now accepts optional
`cancellation_scope_id`. Admission checks the exact run's canonical scope and
requires its creation before the operation's authored sequence. The descriptor
fingerprint, execution options and Scheduled/Started activity snapshots preserve
that identity. Original-claim inspection refuses contradictory membership.
Atomic local group admission checks every member before creating any sibling.
Historical omission leaves unscoped descriptors and snapshots unchanged.
Remote activity, timer and child scheduling now carry the same optional address.
Candidate protocol 1.20 is required when transport commands name a scope. Both
ordinary completion and retained-claim checkpointing verify every named scope
before applying any sibling command. Remote activity snapshots, timer scheduling
history and task locators, and child scheduling/started history retain the
address. Child operator metadata is a projection, not its authority. Historical
unscoped scheduling shapes remain unchanged.

Signal and condition wait commands also retain their optional address on the
canonical opening event. Timed waits retain it on timeout scheduling and task
locators, including immediate timeout history. Untimed waits retain membership
without creating a timer. Foreign or unknown scope addresses refuse the entire
command batch before effects. Waits still close a turn and cannot enter a
retained-claim prefix. Once delivery is prepared, the original wait can replay
but new work in that scope cannot be admitted.

The candidate backend records canonical `SignalWaitCancelled` and
`ConditionWaitCancelled` decisions for timed and untimed original waits.
Preparation freezes each wait's identity, descriptor and associated timer.
The wait decision and timeout fence commit together under the timer-task, run,
hosting-claim and timer-row lock order. Untimed waits use run and claim locks.
Every timer entry point delegates a wait-owned timer to this atomic actor.
Already received/applied signals, satisfied conditions and natural timeouts keep
their original outcome. Cancellation retains buffered signal bytes, closes only
the original wait occurrence, and denies stale publication into that occurrence.
Replacement and lost-acknowledgement retries use the first receipt and deadline.
Source qualification remains required before exposing SDK scope execution.

Preparation v5 also freezes each child call's sequence, call identity, instance,
typed-history run target and recorded cancellation policy. It selects the latest
child start committed before preparation. Later child starts, changed current
run pointers and missing operator projections cannot retarget a cold retry.
Natural completion leaves the original prepared membership intact. The snapshot
is exposed through candidate worker responses and the history timeline, without
copying application payload bytes. The internal child actor now commits a genuine
cooperative child request and its parent receipt atomically. TryCancel waits for
that request receipt. WaitCancellationCompleted also requires a separately
recorded canonical child terminal outcome. Abandon records the policy without
changing the child. Cold delivery checks the original preparation, child command,
typed request history and terminal receipt before accepting a delivery marker.
The hosting claim, unrelated siblings and shielded addresses remain intact.
Portable delivery invokes that actor for each original direct or inherited child
member and returns its durable request and resolution receipts, including partial progress
when a policy barrier remains pending. A duplicate or replacement claim reconciles
those same receipts under the original preparation and deadline.

The same preparation freezes the original unshielded descendant tree. Inherited
requests and that inventory commit atomically under the original run and hosting
claim. Each descendant retains its accepted request, canonical parent, original
root lineage and deadline, operation membership and any competing-root conflict.
An existing independent request is preserved, and its descendants inherit that
accepted identity. The authority ceiling remains bounded by the original parent
preparation and every accepted ancestor budget. Shields exclude their complete
branch from propagation without extending its authority.

Cold replay validates that original history prefix, so later openings, requests,
mutable limits or worker replacement cannot resample the inventory or deadline.
New scopes, including new shields, cannot open inside the prepared subtree.
Existing openings replay, and unrelated root work and sibling branches continue.
A lost claim or expired budget rolls back the preparation and its nested requests.
The internal descendant actors consume an immutable reference to the original
parent preparation. Each reference retains its actual history ID and sequence,
the descendant's accepted request and membership, and the captured authority
ceiling. It creates no new history event or authored command position. Activity,
timer, wait and child receipts retain their original ownership checks. Timed
waits and their timer fence still commit atomically. WaitCancellationCompleted
requires the original activity owner's stop receipt before the parent delivery
marker can commit. Portable dispatch reconciles direct and inherited receipts
under that one preparation, preserving siblings and shielded branches.

Reading an original reference or delivery marker after expiry grants no new
effect authority. Live actors still require the current hosting claim and both
the current and captured budget. SDK scope execution, the response contract and
published mixed-language qualification remain separate unfinished gates.

The optional internal `CancellationScopeAdmission` bridge role exposes these
canonical membership checks to Server. Server preserves the optional field,
refuses a backend without that role, checks before payload resolution and
rechecks under the run lock. Stream directives commit only after successful
Native admission in the same transaction. A refusal does not publish stream
items, while timeout or cancellation decisions made by Native remain durable.

These are backend admission contracts. Scope-aware authoring/replay, request
delivery and physical sibling supervision remain separate implementation and
qualification gates. No scope capability is enabled.

Direct prepared-local admission checks the same cancellation guard as batched
commands before creating an execution or attempt. An implicit-root command,
including a metadata write, also cannot consume a position reserved for an
unfinished scoped delivery. This covers ordinary completion, both retained-claim
prefixes and atomic local groups. Previously admitted original operations retain
their membership and can replay, including an unaffected root local member in a
mixed group. Once delivery consumes its position, the unaffected parent can
admit its next operation. A delivery marker alone still grants no new scoped
durable cleanup authority.

Operation policy and shielding are independent. TryCancel, WaitCancellationCompleted
and Abandon retain their meanings. A shield blocks inherited cooperative
delivery. It does not silently change an activity to Abandon. Prepared local
Abandon remains refused until it has a qualified independent lifetime.

## Request identity and finite authority

The target address is `(exact run, scope)`. Each address has a distinct local
request identity and delivery record. Propagation carries one original root
identity, original request time and immutable cleanup deadline. Opening another
scope, restarting a worker or retrying a request grants no new budget.

The current `cancellation-context/v1` lineage permits only one entry per run.
It cannot represent several scope hops inside that run. Preserve its parser and
historical snapshots. Define a separately versioned scoped context with explicit
scope addresses before adding consumers. Do not squeeze scope hops into repeated
run entries or replace a local delivery ID with the root ID. The rich authored
context retains requester, source, reason and deterministic remaining-time helpers.

The internal metadata encoding is
`durable-workflow.scoped-cancellation-context/v1`. `root_context` contains the
original rich v1 root snapshot with its original single root request. The
separate `lineage` contains `request_id`, `workflow_instance_id`,
`workflow_run_id`, `scope_id` and `cleanup_deadline_at` for each accepted address.
The first address must match that root and its deadline. A descendant deadline
can shorten but cannot increase. `rootDeadline()` keeps the original global
budget while `deadline()` returns the selected scope's accepted budget.

`ScopedCancellationContext` permits different scopes within one run, rejects
repeated request IDs or run/scope addresses and contradictory run/instance
mapping, and explicitly adapts old run lineage to implicit root scopes. It
normalizes scope address field order independently of database JSON key order.
The existing v1 parser and snapshots are unchanged. This metadata parser grants
no authority: backend consumers must verify the recorded scope, propagation
edge and accepted root under lock. Later ancestor authority ceilings and
competing roots are separate records, not mutations of the accepted context.
When a scoped request enters a child run, the internal
`durable-workflow.cancellation-context/v2` encoding retains the full accepted
`scope_origin`. Its ordinary run lineage contains one entry per run, while the
origin preserves every scope hop and the immediate causal parent request ID.
The child keeps the original requester, reason, source and requested-at time,
and uses the accepted scope budget narrowed by the original preparation and
current parent authority ceilings. The immutable child
`scope_authority_deadline_at` binds that limit without extending the global root
budget or rewriting the accepted scope origin.
Further run-to-scope-to-run propagation retains that origin. Canonical child
requests read it from the original prepared member, never caller-supplied
metadata or a mutable current-run pointer. Competing origins cannot replace an
accepted child context. Existing v1 snapshots remain readable. This candidate
encoding is source-qualified for parsing in the PHP, Python and Rust SDK
candidates. Authored scope execution and the published mixed-language cascade
remain required before scope support is advertised.
Native's internal `CancellationScopeRequests` kernel now accepts requests at
recorded non-root addresses under task-before-run locks on the configured
storage connection. The accepted
`CancellationScopeRequested` history event owns its context and identity.
Duplicates return that event, including after run closure. Inheritance reads
the immediate canonical parent's accepted context, or the run's validated
request for an implicit-root edge. A conflicting inherited root records
`CancellationScopeRequestConflicted` with both contexts and leaves the accepted
event unchanged. Request and conflict facts appear in the history timeline.
Repeated propagation of the same conflicting root returns its original conflict
event and incoming address identity rather than growing history on every retry.

The separate authority inspection takes the earliest accepted ancestor, run
cancellation, execution or run deadline, and marks terminal or expired authority
inactive. Shielding does not remove those ceilings. Requests do not set run
cancellation fields, release claims, wake or stop callbacks, or deliver an
exception. Root scope requests still use the existing whole-run boundary.
Canonical reads reject contradictory addresses or inherited contexts.

Acceptance also makes shared workflow ownership recoverable before preparation.
It projects the original scope authority ceiling on the run and shortens live
workflow leases to at most ten seconds, honoring a smaller configured lease.
It never extends an already shorter lease, revives an expired claim or changes
its owner, attempt or activity leases. The run-summary lease projection changes
with the shortened claim. A pending task acquires that interval when claimed.
Duplicates and conflicting roots do not renew ownership or replace the accepted
budget. While any projected original scope budget is live, claims and heartbeats
keep the bounded ownership interval. When all those budgets expire, unrelated
work can retain the ordinary configured lease. This projection does not grant
callback authority or deliver cancellation. SDK scope consumers still need
qualified supervision that renews these intervals in time.

The internal `CancellationScopeDelivery` kernel records one
`CancellationScopeDelivered` boundary per accepted exact-run scope address.
It rechecks the live claim owner/attempt, accepted identity, current authority,
authored call shape and canonical operation membership under the configured run
lock. Response loss and replacement claims return the original event, context
and recorded clock. A changed boundary is refused. An inherited request is
deferred at a parent shield, while a direct request can reach the shielded scope.
Every member of a recorded parallel or selection boundary must belong to the
address. A mixed-scope barrier requires selective member delivery and is refused
by this boundary kernel.

Delivery consumes the interrupted durable command range even when it was not
scheduled. It does not set whole-run cancellation fields or replace the workflow
claim. Preparation and delivery renew its bounded hosting interval while retaining
owner and attempt. Its `authority_deadline_at` preserves the ceiling observed at delivery.
Later ancestors can shorten live authority without rewriting this receipt, so
the receipt never authorizes a new effect. Cold inspection remains possible
after expiry or run closure. This is a recording kernel, not a consumer that
injects cancellation or physically stops callbacks. Scope-aware SDK replay,
selective supervision and scoped `remaining()` remain unimplemented. No scope
capability is advertised.

The first accepted request at an address owns its identity and deadline.
Duplicates return that context. A different root is a recorded conflict with
both identities and deadlines, not an implicit merge. The accepted context is
unchanged. Qualification must race these requests on supported databases.

When a later ancestor preparation includes an independently cancelled descendant,
its strictly earlier preparation continues to own the original delivery boundary.
While that delivery is unfinished, portable delivery returns
`cancellation_scope_descendant_delivery_pending` before dispatching any actor.
Direct activity, timer, wait and child actors also reject an ancestor preparation
that would replace the unfinished boundary. The original preparation can finish
within its remaining authority.

Once delivery completes, reconcile its original preparation and canonical marker.
The marker can follow the new preparation, but must precede the ancestor marker
that consumes it. It must prove the same accepted context and exact activity,
timer, wait and child inventory. A descendant may use the original marker of its
own earlier ancestor. Read receipts from that marker's history prefix without
rerunning actors, changing projections or rebinding the captured budget.
Completed cleanup remains readable after its original deadline or when a later
parent imposes a tighter ceiling. Future history cannot justify an earlier marker
or authorize another effect.

An inherited deadline is never later than its originating deadline. A stricter
authored scope limit can shorten authority and is recorded once. The inspection
view distinguishes the original request deadline from any ancestor authority
ceiling. An independently cancelled scope cannot stay alive after the enclosing
run loses authority. Neither shielding nor a conflicting root can extend a
run's execution limit, termination or accepted run cancellation deadline.

Inherited cancellation is pending at a shielded scope. Existing operations in
that scope can continue within the remaining authority. Delivery occurs when
the shield boundary is left. A direct request targeting that scope is delivered
there even if it shields its parent. New normal work in an already requested
unshielded scope is refused before callback admission. Cleanup work is explicitly
bound to the accepted request and remaining budget.

### Prepared local cleanup authority

The candidate prepared-local descriptor accepts an explicit `cancellation_cleanup`
proof containing only `scope_id`, `request_id` and `delivery_history_event_id`.
The requested scope and delivery must belong to this run's canonical history.
The new operation must follow that delivery's authored range and belong to its
original prepared scope or an original unshielded descendant with the same root
context. A shielded or unaffected address cannot borrow the proof. An earlier
independent preparation still owns its own cleanup authority.

The runtime records the original request/root identity, delivery and preparation
IDs, operation membership, accepted cleanup deadline and captured authority
ceiling in the execution and original start. The worker supplies no deadline.
Both callback deadlines and retry deadlines are bounded by that ceiling. A later
increase to a mutable run limit cannot extend it. Current run/execution limits,
accepted ancestor deadlines, terminal state and later whole-run cancellation
still end authority. A scoped proof grants no immunity from whole-run cancellation.

A later ancestor preparation excludes these explicitly shielded cleanup calls
from its ordinary cancellation inventory. It verifies their original scheduled
snapshot against the earlier canonical delivery before excluding them. This also
covers atomic group members that have been admitted but have not started.
Corrupt cleanup history fails preparation rather than hiding ordinary work or
rebinding cleanup to the later ancestor's identity.

Authenticated scoped preparation immediately sets a hosting ownership interval
of at most ten seconds, or the configured workflow lease when it is shorter. While any committed
scope preparation's captured authority budget remains live, workflow heartbeats,
surviving callback controls, embedded local renewals and replacement claims use
that same interval. Preparation and delivery record the latest captured scope
expiry in the run within their transaction, so ordinary claims and renewals need
no history query.
Pending child completion and activity stop receipts do not delay this recovery
interval. A valid retry renews only the authenticated live claim and retains
the first preparation. An expired or replaced owner cannot revive its claim.
Preparation and delivery lock the hosting task before its run, matching worker
claim, heartbeat and watchdog repair.
This recovery projection grants no callback authority or new cleanup budget.
The subtree's absolute deadline does not end the shared
hosting claim. Its own callback lease stays bounded by current and captured
authority. Once every captured scope budget expires, unaffected work can renew
the normal configured workflow lease. Whole-run delivery still bounds shared
ownership by its original cancellation deadline. Neither renewal nor replacement
changes a scope's delivery record or cleanup budget. Real scoped worker SIGKILL
recovery remains required before enabling SDK scope execution.
Request acceptance before any authenticated preparation still uses the ordinary
hosting lease. Qualification of that earlier recovery boundary remains required.

Admission validates every atomic local-group member before creating any sibling.
Renewal and publication validate the recorded cleanup fact. Missing or rewritten
execution/start snapshots cannot turn cleanup into normal work. A late reported
success cannot publish its result. Replacement preserves the same original
delivery and budget, and an old attempt cannot publish. Native lease recovery
still reports an unknown physical callback-stop state until the original owner
provides real stop proof.

These are Native prepared-local primitives. Other durable cleanup operations,
SDK consumption/supervision, cleanup outcomes in scope inspection and real scoped
SIGKILL recovery remain qualification work. Scope execution stays unadvertised
and the SDK Worker does not enable it from this internal descriptor alone.

## Delivery and operation resolution

Only awaits that belong to the requested unshielded subtree receive the scoped
cancellation exception. Record its request, authored boundary, operation range
and context canonically before returning it. An unrelated await does not receive
it. Cold replay must deliver at that same boundary even when later results exist.

The receiving address can be the requested scope or any canonically recorded
descendant reached without crossing a parent shield. Each scheduled leaf must
retain a valid scope opened before that leaf. Conflicting flat and nested
membership, unknown scopes and later scope openings cannot justify delivery.
Cold reads establish membership from the original marker's history prefix.

TryCancel releases the scoped await after durable fencing/request propagation.
WaitCancellationCompleted requires the same matching original-owner callback
stop receipt or canonical child terminal outcome used by run cancellation.
Abandon leaves independently supported work running and inspectable. None of
these imply an external side effect was undone.

Mixed groups retain each leaf's scope and policy. A cancelled member must not
fence its sibling merely because both share a group. The group can expose its
cancelled outcome while surviving handles remain available. A scope join resolves
the requested member policies and its own cleanup, not every operation in the
enclosing run. A caught scoped exception does not set run cancellation fields
or force a successful enclosing workflow to end Cancelled.

A parallel or selection address is eligible when at least one leaf belongs to
the requested unshielded subtree. Other leaves retain their original scope,
task and result authority. A group consisting entirely of unrelated or shielded
leaves cannot receive that request. Delivery checks stop proof against the
frozen scope inventories and their original contexts, rather than requiring
every leaf in the await range to be stopped. These Native kernel checks do not
yet advertise authored scope execution in the published SDKs.

## Prepared local callback ownership

Today's callbacks share a hosting workflow claim. Run-level waiting can release
that whole claim. Reusing that release for a partial scope would revoke unrelated
local siblings and is not a valid implementation.

For partial cancellation, keep the hosting claim while supported siblings are
active. Independently poll and stop only attempts in the requested scope.
Continue control/lease renewal for surviving callbacks while a scoped wait is
pending. The cancelled attempt's result is fenced even though the hosting claim
remains valid. Scope authority and attempt authority are checked separately.

Once all hosted callbacks are settled, the workflow can park its claim while
waiting for remote/child outcomes. Persist the original scoped delivery boundary
and wait inventory first. A fresh claim resumes that boundary. An SDK that cannot
maintain sibling supervision must refuse this capability before any callback
starts. Do not silently stop siblings or convert local work to remote work.

SIGKILL can interrupt every callback hosted by the lost worker. Report those
attempts' stop states as unknown until evidence exists. Recovery may retry
uncommitted sibling work under ordinary at-least-once semantics. It must preserve
already committed results, scope membership, accepted request identities and
deadlines. A replacement cannot acknowledge a stopped original attempt merely
because it has acquired the workflow lease.

## Inspectable states and admission

An unfinished preparation reserves its authored delivery position, including
an interrupted await that has no scheduled operation yet. A new scope opening,
operation prefix or another scope's preparation cannot consume that position.
Recorded commands still replay, and already scheduled unrelated or shielded
operations continue under their own authority. The delivered marker consumes
the original position before workflow authoring advances to another new command.
Reusing a preparation revalidates its live command shape before dispatching
actors. Historical delivery replay keeps the original boundary and clock.

Extend the existing cascade inventory with scope nodes and parent edges. For each
node show accepted/conflicting requests, deferred shield propagation, delivered
boundary, pending stop receipts, cleanup progress and final outcome. Keep callback
fencing separate from reported physical stop. A scope can finish cancelled while
its workflow and unrelated scopes remain running or complete successfully.

Server discovery describes actual installed bridge support. Worker admission
requires the SDK's real scope replay and supervisor implementation for each
operation kind it claims. Missing support identifies the Worker/SDK and operation
before scheduling or callback invocation. Rust's missing prepared-local executor
is separate foundation work, not a capability flag this design can enable.

## Required qualification before advertising scopes

1. One parent scope contains an unshielded timer/child subtree and a shielded
   activity. An independent sibling activity runs alongside it. Request only
   the parent scope, observe genuine child cooperation and matching callback
   stop evidence, then let the shield and sibling complete. Catch the scope
   cancellation and finish the enclosing workflow successfully.
2. Repeat with two prepared local siblings in the same hosting claim. Cancel
   one with WaitCancellationCompleted. The other retains authority and publishes
   its result. Stale completion of the cancelled attempt is refused.
3. SIGKILL during scoped cleanup. A fresh replacement replays the same scope
   identity, delivery boundary and consumed budget, resumes cleanup and preserves
   committed sibling results. Stop observations without receipts remain unknown.
4. Race direct, ancestor and duplicate requests on supported databases. The
   winning accepted identity and deadline remain immutable. Conflicts are visible.
   A shield or independently accepted request cannot outlive run authority.
5. Verify cold replay, query/update replay and changed membership/policy refusal,
   including mixed nested groups and history preceding scope support.
6. Run the exact published PHP/Python/Rust supported-operation tuple and inspect
   the same scope cascade through API, CLI and Waterline. Run the original full
   workflow cancellation/SIGKILL cascade again. Unsupported local executors are
   diagnosed explicitly rather than counted as qualified.

The existing published competitor experiments supply comparison cases, not
timing baselines for a capacity claim. Retain the customer tradeoffs: a shared
claim needs an available supervising worker during partial local waits, external
effects still need reconciliation, and concurrent arbitrary workflow-code
branches are outside this operation-scope contract.
