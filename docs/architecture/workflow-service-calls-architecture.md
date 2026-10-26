# Workflow V2 Cross-Namespace Service Call Lifecycle and Outcome Contract

## Scope

This document is the frozen contract for the lifecycle and outcome
semantics of a cross-namespace service call in Durable Workflow v2. A
cross-namespace service call is the durable operation produced when a
caller in one namespace invokes a service operation that is published
by a handler in another namespace through the
`workflow_service_endpoints` / `workflow_services` /
`workflow_service_operations` registry.

The contract pinned here covers the durable identifier, the explicit
non-terminal and terminal states, the caller-facing boundary outcome,
the sync vs async operation modes, the explicit linked target
references, the deadline / cancellation / retry / idempotency surface,
the reference-based payload storage rule, the failure taxonomy, and the
observability surface that explains a call without raw transport logs.

The contract builds on:
- `docs/architecture/workflow-child-calls-architecture.md` for the
  parent / child lifecycle model that this contract mirrors at the
  cross-namespace boundary.
- `docs/workflow-messages-architecture.md` for the durable-stream
  outcome model that the linked-target reference rule mirrors.
- `docs/architecture/execution-guarantees.md` for the Phase 1 durable
  execution invariants every linked target reference must satisfy.
- `docs/architecture/control-plane-split.md` for the control-plane
  rules every cross-namespace admission decision must obey.
- `docs/architecture/cross-namespace-service-policy.md` for the
  boundary policy order and caller-facing outcome values that sit above
  handler binding.

A cross-namespace service call is not "just an HTTP request" and not
"just a child workflow." It is a durable operation with its own
lifecycle, references, and outcome semantics.

## Terminology

- **Cross-namespace service call**: a single invocation of a service
  operation published in a target namespace, made by a caller that may
  itself be a workflow run in any (possibly the same) namespace. Every
  cross-namespace service call has a durable service-call id and a row
  in `workflow_service_calls`.
- **Caller namespace**: the namespace of the caller. Recorded in
  `caller_namespace` so observability surfaces can attribute the call
  to the originating tenant or fleet.
- **Target namespace**: the namespace that owns the resolved endpoint,
  service, and operation. Recorded in `target_namespace` so admission
  and policy decisions are attributable.
- **Durable service-call id**: the ULID primary key of the
  `workflow_service_calls` row. Generated at admission time, and
  stable across retries by the caller, stable across replays of the
  caller's history, stable across continue-as-new of the caller,
  and stable across process restarts of the handler.
- **Linked target reference**: the explicit pointer from a service
  call to the durable execution that the handler resolved to (a
  workflow run, a workflow update, an activity execution, or an
  invocable carrier request).
- **Operation mode**: the value of `operation_mode` on the call,
  matching the `ServiceCallOperationMode` enum. Determines whether
  the caller observes a terminal result inline or only an in-flight
  durable reference.
- **Boundary outcome**: the value of `outcome` on the call, matching
  the `ServiceCallOutcome` enum. It answers whether the boundary
  accepted the call, rejected it by a specific policy step, or the
  handler completed, degraded, failed, timed out, or cancelled it.
- **Resolution**: the act of mapping the requested endpoint / service
  / operation triple to a concrete handler binding and recording the
  linked target reference on the call row.

## The durable service-call id

Every cross-namespace service invocation creates a row in
`workflow_service_calls` whose ULID primary key is the durable
service-call id. The row is the system of record for the call. The
durable id is:

- generated at the moment the call is admitted, before any handler
  starts, before any linked target reference exists;
- stable across the entire lifecycle: caller retries reuse the same
  row when `idempotency_key` matches, handler restarts do not
  re-issue ids, and continue-as-new of the caller does not change the
  id;
- the only identifier observability surfaces, CLI flows, Waterline
  flows, webhook deliveries, and SDK clients use to refer to the call
  outside the originating caller's frame.

A caller that loses its in-memory handle to a call recovers by
querying for the durable service-call id. There is no in-memory-only
state that defines a service call.

## Service-call lifecycle

The lifecycle is defined by the `ServiceCallStatus` enum. Every value
is either non-terminal (open) or terminal. The legal transitions are
written below; no other transition is valid, and consumers MUST
reject calls observed in any other state shape.

| Status | Value | Open / Terminal | Meaning |
|--------|-------|-----------------|---------|
| Pending | `'pending'` | Open | Row created, resolution not yet committed. |
| Accepted | `'accepted'` | Open | Resolution committed, handler binding written, handler not yet started. |
| Started | `'started'` | Open | Handler has begun executing the linked target reference. |
| Completed | `'completed'` | Terminal | Handler produced a successful result. |
| Failed | `'failed'` | Terminal | Handler produced a terminal failure (see the failure taxonomy). |
| Cancelled | `'cancelled'` | Terminal | Call cancelled before reaching another terminal state. |

Legal transitions:

```
    [Pending] ──resolved──> [Accepted] ──started──> [Started]
        │                       │                       │
        │                       │                       ├── markCompleted ─> [Completed]
        │                       │                       ├── markFailed ────> [Failed]
        │                       │                       └── markCancelled ─> [Cancelled]
        │                       ├── markFailed ────> [Failed]
        │                       └── markCancelled ─> [Cancelled]
        ├── markFailed ────> [Failed]
        └── markCancelled ─> [Cancelled]
```

Notes on the transitions:

- **Pending → Failed** is the resolution-failure path: the requested
  triple did not resolve to a registered operation in the target
  namespace. The `outcome` is `rejected_not_found`.
- Pending rows and Failed rows with
  `ServiceCallFailureReason::ResolutionFailure` MAY leave
  `workflow_service_endpoint_id`, `workflow_service_id`,
  `workflow_service_operation_id`, `resolved_binding_kind`, and
  `resolved_target_reference` null because registry or handler
  resolution never committed.
- **Pending → Cancelled** is legal: a caller may withdraw a call that
  has not yet been admitted by the target namespace. The cancellation
  is still terminal and still records `cancelled_at`.
- **Accepted → Failed** with reason `policy_rejection` records a
  policy rejection that happened after admission but before the
  handler started. The `outcome` distinguishes forbidden,
  throttled, concurrency-limited, and circuit-open rejections.
- **Started → Cancelled** records a cancellation that took effect
  while the handler was running. The handler may or may not have
  produced any side effects on the linked target reference; the
  service-call contract does not promise that it did not.
- A **terminal status never changes**. Any second outcome event MUST
  be recorded in `metadata` and MUST NOT mutate `status`,
  `outcome`, `failure_payload_reference`, or any of the closure
  timestamps.

## Boundary outcome

The caller-facing result is defined by `ServiceCallOutcome` and stored
on `workflow_service_calls.outcome`. `ServiceCallStatus` stays a
lifecycle enum; `outcome` is the contract value callers, Waterline, the
CLI, and webhook delivery use to distinguish target resolution,
boundary policy, service-level limits, degradation, and handler
failure without inspecting raw logs or handler-specific metadata.

| Outcome | Value | Status shape | Meaning |
|---------|-------|--------------|---------|
| Accepted | `'accepted'` | Open, usually Accepted or Started | Boundary policy admitted the call and dispatched the handler. |
| Completed | `'completed'` | Completed | Handler returned the full successful result. |
| Cancelled | `'cancelled'` | Cancelled | Call ended by cancellation. |
| TimedOut | `'timed_out'` | Failed | Call deadline elapsed before a terminal handler result arrived. |
| RejectedNotFound | `'rejected_not_found'` | Failed | Target namespace, endpoint, service, or operation did not resolve. |
| RejectedForbidden | `'rejected_forbidden'` | Failed | Endpoint, service, or operation authorization denied the caller. |
| RejectedThrottled | `'rejected_throttled'` | Failed | Service-level rate limiting rejected the call before handler dispatch. |
| RejectedConcurrencyLimited | `'rejected_concurrency_limited'` | Failed | Service-level concurrency limiting rejected the call before handler dispatch. |
| RejectedCircuitOpen | `'rejected_circuit_open'` | Failed | Service-level circuit-break state rejected the call before handler dispatch. |
| Degraded | `'degraded'` | Completed | Handler returned a fallback result rather than the full advertised result. |
| HandlerFailed | `'handler_failed'` | Failed | Handler accepted the call and produced a terminal failure. |

Boundary rejections are all Failed lifecycle states, but they are not a
single caller-facing outcome. A surface that collapses
`rejected_not_found`, `rejected_forbidden`, `rejected_throttled`,
`rejected_concurrency_limited`, `rejected_circuit_open`, `timed_out`,
and `handler_failed` into one generic failure value is out of
contract.

## Sync vs async operation modes

Operation mode is recorded on the call row in `operation_mode` and
matches the `ServiceCallOperationMode` enum. It decides whether the
caller observes a terminal result inline or only an in-flight durable
reference.

- `ServiceCallOperationMode::Sync` (`'sync'`): the caller blocks
  until the call reaches a terminal state and receives the terminal
  result (or terminal failure) directly. The durable service-call id
  and linked target reference are still recorded so the call is
  observable after the fact, but the caller's program does not
  receive only a reference.
- `ServiceCallOperationMode::Async` (`'async'`): the caller receives
  the durable service-call id (and any committed linked target
  reference) as soon as the call is admitted. The terminal outcome is
  observed later through the call id, the linked target reference, or
  a workflow message addressed to the caller.
- `ServiceCallOperationMode::SyncWithDurableReference`
  (`'sync_with_durable_reference'`): the caller is willing to wait
  inline up to the deadline, but the durable reference is committed
  early enough that a deadline expiry leaves the caller with the call
  id even after it stops blocking. After the deadline elapses the
  caller observes the call asynchronously through the call id.

The contract is symmetric: a Sync caller always has the same
durable record as an Async caller. Sync mode is a delivery preference
on top of the durable record, never an alternative to it.

## Durable scheduling, claims, and retries

A service operation authored by a workflow is accepted inside the workflow-task
completion transaction, but its handler effect is not executed there. That
transaction may only persist the accepted `workflow_service_calls` row, the
immutable accepted handler-binding snapshot, a
`workflow_service_call_dispatches` row, and a `ServiceOperationScheduled`
history event. It MUST NOT invoke a workflow/activity/carrier handler, enqueue
work before commit, wait for an update result, or sleep for retry backoff.
This rule applies to protocol 1.21 and later completions. A host passes the
authenticated protocol through the optional
`ProtocolAwareWorkflowTaskBridge::completeForProtocol()` role; the stable
two-argument `WorkflowTaskBridge::complete()` contract remains unchanged.
Missing, malformed, different-major, and future-minor versions fail closed.
Supported older protocols retain the original inline behavior and do not write
the scheduled event.
The built-in `WorkflowExecutor` explicitly selects the package's current
protocol for the same decision.
Direct service-control-plane calls never infer scheduler authority from caller
workflow identity or from an unrelated idempotency transaction.

`workflow_service_call_dispatches` is the durable scheduler record. Its state is
`scheduled`, `claimed`, or `completed`; `attempt`, `max_attempts`, and
`available_at` carry the retry schedule. `claim_generation` plus `claim_token`
form the compare-and-swap fence. `dispatch_attempted_at`,
`repair_available_at`, and `claim_expires_at` make a lost enqueue or crashed
worker discoverable by the normal v2 repair pass.

The completion transaction registers queue publication for after commit. Queue
delivery is at least once, so an enqueue failure, duplicate job, or worker crash
does not change the durable schedule. `TaskWatchdog` republishes due schedules,
lost dispatches, and expired claims. Operators may tune
`workflows.v2.service_call_dispatch.claim_lease_seconds` and
`repair_after_seconds`; disabling the repair pass while scheduled calls exist
violates the liveness contract. The dispatch table intentionally has no fixed
foreign key because the service-call model and table are configurable. The same
repair pass removes only dispatches whose configured call is missing and whose
age exceeds `orphan_grace_seconds` (one hour by default and as an enforced
minimum), in deterministic primary-key batches capped at 100 rows. It rechecks
absence and age in the delete query so a concurrently restored or custom-model
call is not removed.

A runnable attempt claims in one database transaction and in a fixed lock order:
service-call row, dispatch row, current caller-run row, endpoint, service, then
operation. The claim reconstructs the principal recorded when the call was
accepted, checks that the current caller is still active and has no cooperative
cancellation request, re-runs
`authorizeReplay()` against the current catalog policy, and stores hashes of the
authorized catalog and policy revisions. Authority is current at claim time;
the actual handler kind, binding and target reference remain the immutable
accepted snapshot. A later catalog edit may revoke a call but cannot redirect
an already accepted call to a different handler. When an activity or carrier
binding does not provide its target id, the first authorized claim creates that
id and persists it in the dispatch row in the same generation/token-fenced
transaction. Lease replacement reuses the persisted id; stale generations
cannot replace it.

An explicit authorization refusal terminally rejects the call before any
effect. A transient policy or storage failure is not an authorization decision:
the claim transaction rolls back and leaves the durable schedule repairable.

The handler effect runs only after that claim transaction commits. Every attempt
receives the stable identity
`workflow-service-call:<service-call-id>:attempt:<attempt>`. Handler adapters
MUST propagate or otherwise enforce that identity because a lease may expire
after an effect began and at-least-once recovery may invoke the attempt again.
The built-in workflow start, update, and signal adapters receive a reserved,
in-memory capability issued only after the current attempt claim passes its
generation/token fence. Its opaque, scheduler-generated command token is stable
across claim generations for one attempt and changes for the next retry attempt.
The capability cannot be serialized or meaningfully debugged, and only its
non-secret audit identity is retained in command context. Raw public options,
command context, and low-level request ids cannot reserve the scheduler token.
A repeated or crash-reacquired attempt therefore returns the original
command/effect receipt rather than creating another effect. Activity and carrier
bindings currently resolve and persist a stable target reference without
launching an external effect in this control plane; a future execution lane must
consume the same attempt identity.
Only the worker holding the current generation and token may persist handler
progress or complete the attempt. Retry backoff is stored in `available_at`; no
worker thread sleeps while holding a call, caller, catalog, or workflow-task
lock. Immediately before invoking an effect, the worker rechecks current claim
ownership, the call deadline, and the caller run's terminal/cancellation state;
a stale worker exits without touching the replacement claim, while a terminal
call, expired deadline, or closed/cancelling caller completes the dispatch
without invoking the handler.

`ServiceOperationScheduled.workflow_resume_on_admission` records the authored
delivery decision separately from the scheduler's internal accepted-only
dispatch. `true` lets an explicit accepted/async caller resume on the scheduled
event. `false` keeps a synchronous caller suspended until a terminal
`ServiceCallCompleted`, `ServiceCallFailed`, or `ServiceCallCancelled` event is
delivered and remains the authority for cooperative cancellation ownership. The
internal `wait_for=accepted` surface MUST NOT by itself resume a synchronous
workflow or erase its authored completion wait.

All service-operation wait-time inputs are bounded to 60 seconds. Direct calls
made outside a workflow completion transaction may still use the existing
inline control-plane path, but they receive the same bound. Inside a completion
transaction the handler-facing wait is always `accepted`, and any longer wait
is represented by durable history and a later workflow task.

## Linked target references

Every accepted call carries a `resolved_binding_kind` (matching the
`ServiceCallBindingKind` enum) and a `resolved_target_reference` that
together name the durable execution the handler resolved to.

| Binding kind | Value | `resolved_target_reference` | Linked columns |
|--------------|-------|-----------------------------|----------------|
| WorkflowRun | `'workflow_run'` | `workflow_run_id` | `linked_workflow_run_id` and `linked_workflow_instance_id` |
| WorkflowUpdate | `'workflow_update'` | `workflow_update_id` | `linked_workflow_update_id`, `linked_workflow_run_id`, and `linked_workflow_instance_id` for the parent instance |
| WorkflowSignal | `'workflow_signal'` | accepted `workflow_command_id` | `linked_workflow_run_id` and `linked_workflow_instance_id` for the signaled instance when available |
| WorkflowQuery | `'workflow_query'` | queried `workflow_run_id` or `workflow_instance_id` | `linked_workflow_run_id` and `linked_workflow_instance_id` for the queried instance when available |
| ActivityExecution | `'activity_execution'` | `activity_execution_id` | (recorded in `metadata.activity_execution_id`; `linked_workflow_run_id` and `linked_workflow_instance_id` set for the owning workflow when the activity is hosted by a workflow run) |
| InvocableCarrierRequest | `'invocable_carrier_request'` | carrier request id | (recorded in `metadata.carrier_request_id`; `linked_workflow_instance_id` set when the carrier request is bound to a workflow instance) |

These are explicit columns, not opaque blobs. Observability surfaces
MUST be able to follow the linked target reference from a service
call to the durable execution without parsing free-form metadata.
Resolution is committed atomically with the transition into Accepted;
a row in Accepted or any later state has a non-null
`resolved_binding_kind` and a non-null `resolved_target_reference` (or
a non-null linked column for kinds that record the reference there).

A service call MAY resolve to one and only one linked target
reference. A second handler binding for the same call (for example
when the caller retries with a different idempotency key) is a new
service-call row with its own durable id.

A WorkflowRun binding follows the logical execution through canonical
`WorkflowContinuedAsNew` history. Its current linked run and resolved target
reference advance only to the declared next run, in the same namespace,
instance and workflow type, with the next run number. Selecting an instance's
current run or an unrelated redrive is insufficient. The call id, acceptance
time and original call deadline remain unchanged. The first run and latest
continuation event remain inspectable in call metadata. Continuation keeps the
call open; the final run's completion, cancellation or typed failure closes it.
A linked workflow timeout is a typed handler failure; expiry of the call's own
deadline remains the `timed_out` outcome and takes precedence once reached.

One reconciliation read follows at most 32 continuation hops. The existing
repair pass resumes longer chains, within its normal candidate and queue/run
scope limits. No polling client is required to finish that reconciliation.

## Deadline, cancellation, retry, and idempotency

Every operation declares the policies it accepts at the contract
layer. The accepted call snaps the policies effective at admission
time onto the row so they are stable for the lifetime of the call.
The columns are JSON for forward compatibility but their schemas are
contract-bound:

- `deadline_policy` (JSON): names the wall-clock deadline applied to
  this call. A null `deadline_policy` MUST be interpreted as "no
  deadline at the contract layer; the call inherits the deadline of
  the linked target reference." A non-null policy specifies an
  absolute deadline timestamp or a duration relative to `accepted_at`.
  The [service call deadline contract](workflow-service-call-deadlines.md)
  defines validation, the immutable `call_deadline_at`, expiry and late-result fencing.
- `cancellation_policy` (JSON): names how the call participates in
  cancellation. A null `cancellation_policy` MUST be interpreted as
  "the call is cancelled when the caller's run is cancelled; the
  handler receives a cooperative cancellation request." A non-null
  policy specifies whether cancellation propagates to the linked
  target reference, whether cancellation is best-effort or
  authoritative, and whether the handler is allowed to ignore it.
- `retry_policy` (JSON): names the retry behaviour on transient
  failures observed before the handler reaches a terminal state. A
  null `retry_policy` MUST be interpreted as "no automatic retry; a
  transient failure is reported to the caller and the caller decides
  whether to issue a fresh service call." A non-null policy specifies
  retry counts, backoff, and which `ServiceCallFailureReason` values
  trigger a retry.
- `idempotency_policy` (JSON) plus `idempotency_key` (string):
  declare the dedupe behaviour. When `idempotency_key` is non-null
  and the policy is `at_most_once`, a second admission attempt with
  the same key against the same operation MUST return the existing
  durable service-call id rather than creating a new row. The
  `idempotency_key` is opaque to the platform and is supplied by the
  caller.

Every one of these policies is visible at the contract layer: a CLI
or Waterline surface inspecting the call row can render the snapped
deadline, cancellation, retry, and idempotency policy without
consulting the live operation registry.

For the built-in `propagate_to_linked_workflow` cancellation option, propagation
is best-effort and its public receipt is retained in `metadata.linked_cancel`.
A propagation exception records `workflow_cancel_exception` and its exception
type while still applying the local cancellation. A linked terminal event or
the original call deadline that wins during propagation is preserved, together
with its result/failure and the propagation receipt. A later duplicate cannot
replace that terminal outcome or send another propagation request.

## Reference-based payload storage

Result and failure payloads on a service call follow the same
reference-based storage rule as workflow messages and child-call
outcomes. Inline-only payload transport is not part of this contract.

- `input_payload_reference` is set at admission for any non-empty
  input.
- `output_payload_reference` is set when the call transitions to
  Completed.
- `failure_payload_reference` is set when the call transitions to
  Failed (and SHOULD be set when transitioning to Cancelled if a
  failure shape is available).
- `payload_codec` records the codec used to encode the referenced
  payloads.

The reference itself is opaque to the contract. It MAY point at
inline-encoded JSON in a payloads table, an external object-storage
URL produced via `ExternalPayloadStorageDriver`, or an equivalent
durable store. What is contract-bound is that consumers MUST NOT
require the payload to be available inline on the call row, and
MUST be able to dereference the value through the configured
codec / external storage chain.

A `failure_message` column carries a short human-readable
explanation. It is not authoritative; the authoritative payload is
the failure reference.

## Failure taxonomy

When `status` is Failed, the call carries a `failure_reason` whose
value matches the `ServiceCallFailureReason` enum and is recorded in
`metadata.failure_reason`. `failure_reason` is coarser than
`ServiceCallOutcome`: it groups all boundary policy rejections under
`PolicyRejection` while `outcome` keeps forbidden, throttled,
concurrency-limited, and circuit-open rejections distinct for callers
and operators. The taxonomy distinguishes:

- `ServiceCallFailureReason::ResolutionFailure`
  (`'resolution_failure'`): the requested endpoint / service /
  operation triple did not resolve in the target namespace. No
  handler ever started.
- `ServiceCallFailureReason::PolicyRejection` (`'policy_rejection'`):
  the target namespace policy layer rejected the call before the
  handler started (authorization, quota, idempotency conflict,
  structural-limit guard, namespace closed). The call may be
  Pending → Failed or Accepted → Failed.
- `ServiceCallFailureReason::Timeout` (`'timeout'`): the
  `deadline_policy` elapsed before the handler produced a terminal
  outcome.
- `ServiceCallFailureReason::Cancellation` (`'cancellation'`): the
  call was cancelled by the caller, by an inherited cancellation
  scope, or by the operation `cancellation_policy`. When the call is
  recorded as Cancelled, the failure reason is also Cancellation.
- `ServiceCallFailureReason::HandlerFailure` (`'handler_failure'`):
  the handler ran and the linked target reference reached a terminal
  failure (workflow run failed, workflow update rejected or failed,
  activity execution failed terminally, invocable carrier reported a
  terminal error).

Mapping into `Workflow\V2\Enums\FailureCategory`:

- `ResolutionFailure` and `PolicyRejection` map to
  `FailureCategory::Application`.
- `Timeout` maps to `FailureCategory::Timeout`.
- `Cancellation` maps to `FailureCategory::Cancelled`.
- `HandlerFailure` maps to the failure category of the linked
  target's terminal failure (typically
  `FailureCategory::Application`, `FailureCategory::Activity`, or
  `FailureCategory::ChildWorkflow`).

The taxonomy is exhaustive: every Failed call MUST carry exactly one
of the five reasons, and a Cancelled call MUST carry
`ServiceCallFailureReason::Cancellation` even though its terminal
status is Cancelled rather than Failed.

## Workflow operation authority

Workflow service operations require a host binding for
`Workflow\V2\Contracts\WorkflowServiceOperationAuthorizer`. The default binding
refuses them with `service_operation_authority_invalid`. This applies to embedded
execution and external worker completions, including retained-claim prefixes.
Upgrade the host binding before accepting service-operation work.

An authorizer receives the locked caller run and normalized command intent.
It must validate the requested target scope against trusted host/run context,
then return a `ServiceCallPrincipal` derived from that context. It must not
reconstruct authority from the command's principal fields or metadata. It is an
authorization check, so it must not reserve admission budgets or perform
external effects; a transaction retry or preflight may invoke it again.

An embedded host that explicitly trusts its own workflow code to invoke
services within each run's namespace can opt into the provided workload policy:

```php
$app->bind(
    \Workflow\V2\Contracts\WorkflowServiceOperationAuthorizer::class,
    \Workflow\V2\Support\RunServiceOperationAuthorizer::class,
);
```

That policy permits only the run's namespace. Its principal is
`workflow:<run-id>`, method `workflow`, with no roles, tenant or claims. It does
not satisfy a catalog policy that requires privileged roles. A server with
authenticated workers instead binds an authorizer that checks its current
authentication/namespace policy and returns the authenticated worker principal.
Authorized cross-namespace routing remains possible through that trusted host
binding; a worker's requested `namespace` alone grants no permission.

Policies used for existing workflow-call replay must provide
`authorizeReplay(ServiceBoundaryRequest): ServiceBoundaryDecision`; the optional
`ServiceBoundaryReplayPolicy` interface declares this contract. The default
policy checks current authorization and circuit state without reserving rate
or concurrency capacity. Existing compatible host methods work without adopting
the interface. A custom policy without this pure check fails closed with
`service_operation_replay_authority_unavailable`; implement the hook as part of
the host upgrade. Cached checkpoint receipts require the same current check,
including after catalog policy changes or replacement.

Caller namespace and workflow run/instance identity always come from the locked
run. A mismatched non-null `caller_namespace` or non-null `principal_*` override
is refused before applying sibling commands. Unknown fields do not grant
authority. Explicit null run namespaces stay null even when the process has a
configured default namespace.

An explicit `service_call_id` or an existing idempotency-key match must bind to
the same caller namespace, run and instance, the authorized target namespace,
and the exact endpoint/service/operation names. Existing rows are checked under
locks before replay or dispatch. A missing or foreign explicit call, or a
foreign key match, refuses the worker turn with `service_call_not_owned` and
leaves the lease and durable rows unchanged. New default idempotency keys are
checked by the same rule. Server adapters retain their current-policy replay
checks before handing the call to Core.

The run, service call and catalog models must use the completion's database
connection. A separate connection is refused before sibling writes with
`service_operation_storage_connection_mismatch`; separate transactions cannot
provide the atomic refusal contract.

Keyed calls use a unique SHA-256 digest of the exact target namespace (including
null), catalog names and opaque key. The full scope is still compared after
lookup; a digest or database collation match never grants authority. Creation
uses a savepoint, then locks and reauthorizes the canonical row if another
transaction wins. Explicit call IDs obey the same keyed-call invariant.

Apply the nullable `idempotency_scope_hash` expansion before starting upgraded
writers. It does not backfill existing calls: an existing candidate must pass
current caller, catalog and policy checks before it can claim the key. Conflicting
legacy rows are refused rather than reassigned. Stop or drain older service-call
writers before relying on concurrent deduplication, since they do not populate
the digest. Retaining the column and index supports code rollback without
discarding canonical claims. The migration uses a short metadata-lock timeout
and online index creation on PostgreSQL and MySQL; retry a refused migration
after removing the lock contention. No production migration has been qualified
by the source tests alone.

## Observability surface

The observability surface for a cross-namespace service call MUST be
explainable from the durable record alone. Specifically, given the
`workflow_service_calls` row, an operator surface MUST be able to
render:

- the **caller namespace** (`caller_namespace`), the caller workflow
  instance and run (`caller_workflow_instance_id`,
  `caller_workflow_run_id`) when the caller is a workflow;
- the **caller principal** (`caller_principal_subject`,
  `caller_principal_method`, `caller_principal_roles`,
  `caller_principal_tenant`, `caller_principal_claims`) when the
  embedding host supplies authenticated actor data;
- the **target namespace** (`target_namespace`), the resolved
  endpoint, service, and operation names (`endpoint_name`,
  `service_name`, `operation_name`) plus their canonical IDs
  (`workflow_service_endpoint_id`, `workflow_service_id`,
  `workflow_service_operation_id`);
- the **linked target reference** named by `resolved_binding_kind`
  and `resolved_target_reference`, with a deep-link to the linked
  workflow run, workflow update, activity execution, or invocable
  carrier request;
- the **operation mode** (`operation_mode`);
- the **status** (`status`), the caller-facing **outcome** (`outcome`),
  and, when terminal, the failure reason on Failed (and the
  cancellation source on Cancelled);
- the **snapped policies** (`deadline_policy`,
  `cancellation_policy`, `retry_policy`, `idempotency_policy`);
- the **payload references** (`input_payload_reference`,
  `output_payload_reference`, `failure_payload_reference`,
  `payload_codec`);
- the **timing** (`accepted_at`, `started_at`, `completed_at`,
  `failed_at`, `cancelled_at`).
- when a handler attempt is pending, the **dispatch state** (`state`,
  `attempt`, `max_attempts`, `available_at`), current claim generation and
  lease expiry, last authorized catalog/policy revisions, and next repair time
  from `workflow_service_call_dispatches`.

No part of the observability surface MAY require raw transport
logs. The transport that carried the call between namespaces is an
implementation detail; the durable record is the authoritative story.

## Consumers bound by this contract

The following components are bound by this contract:

- `WorkflowServiceCall` model — the Eloquent surface over
  `workflow_service_calls`.
- `WorkflowServiceCallDispatch` model — the durable schedule, attempt lease,
  authorization revision and repair surface over
  `workflow_service_call_dispatches`.
- `WorkflowServiceOperation` — defines the contract-layer policies
  every accepted call snaps.
- `WorkflowServiceEndpoint` — owns admission of calls in a target
  namespace.
- `ServiceExecutionContract` — publishes the machine-readable runtime
  surface consumed by the standalone server.
- `WorkflowExecutor` — issues service calls from a caller workflow
  and records terminal outcomes on the caller side.
- `ChildCallService` — interoperates when the resolved binding is a
  workflow run, so child-call records and service-call records share
  the linked workflow run id.

CLI flows, Waterline diagnostics, SDK documentation, and webhook
delivery all consume the durable record described above. None of
them is permitted to rely on transport-only state.

## Non-goals

- **Inline-only payload transport**: the contract does not permit a
  call result that is observable only as an inline byte string on the
  call row. A `payload_codec` and reference are required.
- **Implicit lifecycles**: the contract does not permit a "fire and
  forget" call that has no durable row. Every cross-namespace service
  invocation creates a row, even when the caller does not care about
  the outcome.
- **Per-transport retry semantics**: retry behaviour is contract
  state, not a property of the underlying HTTP / queue / message-bus
  transport. The transport may retry inside its own attempt budget,
  but the contract-layer retry is the only retry an operator surface
  reports on.
- **Cross-call ordering guarantees**: this contract does not promise
  ordering between distinct service calls. Ordering, when needed, is
  encoded in the linked target reference (a workflow run with its own
  history, an update sequence, etc.).

## Test strategy alignment

`tests/Unit/V2/ServiceExecutionContractTest.php` verifies the
machine-readable service execution manifest against the runtime enums,
control-plane interface, and durable call/dispatch record fields.
`ServiceCallSchedulerTest` covers commit/rollback, lost enqueue, expired
claims, stale claim fencing, current-policy revocation, transient-policy
rollback, immutable bindings, admission release, deadlines, terminal callers,
the 60-second ingress bound and persisted backoff without lock-held sleep.
Migration and model tests verify the persisted service-call shape.

## Changing this contract

Adding a new ServiceCallStatus, operation mode, binding kind, outcome,
or failure reason requires:

1. A new value on the corresponding enum, with `isTerminal()` (where
   applicable) updated to the right partition.
2. A new row in the corresponding table in this document.
3. A machine-readable contract and behavior-test update for the new
   value.
4. A migration update if the value changes the persisted shape (for
   instance a new column or a new index on
   `resolved_binding_kind`).

Removing a value is a breaking change. It MUST be staged: first
deprecate the value in this document and the enum (mark the enum case
deprecated), then in a later release remove the case after every
durable row in production has stopped carrying it.
