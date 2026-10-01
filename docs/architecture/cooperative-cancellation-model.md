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
replay. Introduce an explicit cooperative parent-close policy rather than
silently reinterpreting old child histories. Mark the historical terminal
policy clearly in the authoring API and migration guidance.

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

An already accepted request from a different root needs an explicit conflict
rule. Resolve this before implementation. Do not silently replace its identity
or extend its deadline. Independent cancellation and ancestor propagation must
have reproducible concurrent-request tests.

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
delivery behavior and expose a null context. Descendant propagation, concurrent
roots and portable SDK exposure remain to be implemented and qualified before
the model is published.

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

An expired lease proves loss of authority to commit a durable result. It does
not prove that a remote process stopped or that an external system performed
an undo. Waiting must distinguish callback acknowledgement, durable fencing and
external reconciliation.

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
