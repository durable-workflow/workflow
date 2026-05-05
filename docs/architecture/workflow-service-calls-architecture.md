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
non-terminal and terminal states, the sync vs async operation modes,
the explicit linked target references, the deadline / cancellation /
retry / idempotency surface, the reference-based payload storage rule,
the failure taxonomy, and the observability surface that explains a
call without raw transport logs.

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

The pinning test for this contract lives at
`tests/Unit/V2/WorkflowServiceCallsArchitectureDocumentationTest.php`.

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
  namespace.
- **Pending → Cancelled** is legal: a caller may withdraw a call that
  has not yet been admitted by the target namespace. The cancellation
  is still terminal and still records `cancelled_at`.
- **Accepted → Failed** with reason `policy_rejection` records a
  policy rejection that happened after admission but before the
  handler started.
- **Started → Cancelled** records a cancellation that took effect
  while the handler was running. The handler may or may not have
  produced any side effects on the linked target reference; the
  service-call contract does not promise that it did not.
- A **terminal status never changes**. Any second outcome event MUST
  be recorded in `metadata` and MUST NOT mutate `status`,
  `failure_payload_reference`, or any of the closure timestamps.

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

## Linked target references

Every accepted call carries a `resolved_binding_kind` (matching the
`ServiceCallBindingKind` enum) and a `resolved_target_reference` that
together name the durable execution the handler resolved to.

| Binding kind | Value | `resolved_target_reference` | Linked columns |
|--------------|-------|-----------------------------|----------------|
| WorkflowRun | `'workflow_run'` | `workflow_run_id` | `linked_workflow_run_id` and `linked_workflow_instance_id` |
| WorkflowUpdate | `'workflow_update'` | `workflow_update_id` | `linked_workflow_update_id`, `linked_workflow_run_id`, and `linked_workflow_instance_id` for the parent instance |
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
`metadata.failure_reason`. The taxonomy distinguishes:

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

## Observability surface

The observability surface for a cross-namespace service call MUST be
explainable from the durable record alone. Specifically, given the
`workflow_service_calls` row, an operator surface MUST be able to
render:

- the **caller namespace** (`caller_namespace`), the caller workflow
  instance and run (`caller_workflow_instance_id`,
  `caller_workflow_run_id`) when the caller is a workflow;
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
- the **status** (`status`) and, when terminal, the failure reason
  on Failed (and the cancellation source on Cancelled);
- the **snapped policies** (`deadline_policy`,
  `cancellation_policy`, `retry_policy`, `idempotency_policy`);
- the **payload references** (`input_payload_reference`,
  `output_payload_reference`, `failure_payload_reference`,
  `payload_codec`);
- the **timing** (`accepted_at`, `started_at`, `completed_at`,
  `failed_at`, `cancelled_at`).

No part of the observability surface MAY require raw transport
logs. The transport that carried the call between namespaces is an
implementation detail; the durable record is the authoritative story.

## Consumers bound by this contract

The following components are bound by this contract:

- `WorkflowServiceCall` model — the Eloquent surface over
  `workflow_service_calls`.
- `WorkflowServiceOperation` — defines the contract-layer policies
  every accepted call snaps.
- `WorkflowServiceEndpoint` — owns admission of calls in a target
  namespace.
- `WorkflowServiceCallsArchitectureDocumentationTest` — the pinning
  test for this contract.
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

The pinning test
`tests/Unit/V2/WorkflowServiceCallsArchitectureDocumentationTest.php`
asserts:

- that this document declares every named heading;
- that this document defines every named term;
- that the documented `ServiceCallStatus` values match the runtime
  `Workflow\V2\Enums\ServiceCallStatus` enum;
- that the documented `ServiceCallOperationMode` values match the
  runtime `Workflow\V2\Enums\ServiceCallOperationMode` enum;
- that the documented `ServiceCallBindingKind` values match the
  runtime `Workflow\V2\Enums\ServiceCallBindingKind` enum;
- that the documented `ServiceCallFailureReason` values match the
  runtime `Workflow\V2\Enums\ServiceCallFailureReason` enum;
- that the documented schema columns match the
  `workflow_service_calls` migration;
- that the document cites itself as its pinning test path.

Changes to any named guarantee in this document MUST update the
pinning test in the same change so drift is reviewed deliberately.

## Changing this contract

Adding a new ServiceCallStatus, operation mode, binding kind, or
failure reason requires:

1. A new value on the corresponding enum, with `isTerminal()` (where
   applicable) updated to the right partition.
2. A new row in the corresponding table in this document.
3. A new assertion in
   `tests/Unit/V2/WorkflowServiceCallsArchitectureDocumentationTest.php`
   pinning the new value.
4. A migration update if the value changes the persisted shape (for
   instance a new column or a new index on
   `resolved_binding_kind`).

Removing a value is a breaking change. It MUST be staged: first
deprecate the value in this document and the enum (mark the enum case
deprecated), then in a later release remove the case after every
durable row in production has stopped carrying it.
