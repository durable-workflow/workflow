# Workflow V2 Cross-Namespace Service Addressing Contract

This document freezes the v2 contract for cross-namespace service
addressing. A caller addresses a durable capability through the
triple `endpoint/service/operation`; the runtime resolves that
contract address to a concrete handler binding owned by the target
namespace. The caller does not name raw queue topology, worker
placement, or child-workflow lineage as the public boundary.

The contract builds on `docs/architecture/routing-precedence.md` and
`docs/architecture/workflow-child-calls-architecture.md`. Those
documents define task routing and lineage-bearing child orchestration;
this document defines the peer-to-peer service address that can be
resolved onto existing workflow, activity, or invocable execution
lanes.

## Scope

The contract covers:

- the stable address form `endpoint/service/operation`;
- endpoint indirection from a logical contract address to a concrete
  handler binding;
- namespace ownership of endpoint, service, and operation names;
- the handler binding kinds that a service operation may resolve to;
- caller-facing dispatch over the contract address rather than raw
  `(namespace, connection, queue)` fields;
- the durable registry and call-record tables that make service
  dispatch inspectable by operators and product surfaces;
- the relationship between service dispatch, task routing, and
  child-workflow lineage.

It does not cover:

- a public network protocol for managed control-plane ingress;
- service discovery outside the workflow database;
- tenant authorization policy beyond the boundary hooks named here;
- changing the routing precedence for workflow tasks or activity
  tasks.

## Terminology

- **Service contract address** - the caller-facing
  `endpoint/service/operation` triple. The triple is durable product
  vocabulary and is stable across queue topology, worker placement,
  child-run identity, and handler implementation changes.
- **Endpoint** - the top-level namespace-owned contract surface.
  Endpoints are stored in `workflow_service_endpoints` and named by
  `endpoint_name`.
- **Service** - a named capability group beneath an endpoint. Services
  are stored in `workflow_services` and named by `service_name`.
- **Operation** - a callable capability beneath a service. Operations
  are stored in `workflow_service_operations` and named by
  `operation_name`.
- **Target namespace** - the namespace that owns the endpoint,
  service, operation, handler binding, and policy for the service
  address.
- **Caller namespace** - the namespace that records the caller context
  when a workflow, command-plane client, or invocable carrier accepts a
  service call.
- **Handler binding** - the operation-owned resolution record,
  represented by `handler_binding_kind`,
  `handler_target_reference`, and `handler_binding`, that points from
  the logical operation to one concrete execution lane.
- **Service call record** - the durable `workflow_service_calls` row
  created when the runtime accepts a dispatch over a service contract
  address. It snapshots the address, the resolved binding, caller and
  target namespaces, payload references, policies, linked workflow
  references, and timestamps.
- **Public service surface** - the durable capability name exposed to
  callers. The public surface is the contract address, not
  `(namespace, connection, queue)`, not a worker id, and not a
  parent/child workflow relationship.

## Contract Address

The canonical address form is:

```text
endpoint/service/operation
```

Each segment is a stable logical name owned by the target namespace:

- `endpoint_name` identifies the peer boundary exposed by the target
  namespace.
- `service_name` identifies a capability group under the endpoint.
- `operation_name` identifies the concrete capability being invoked.

The target namespace owns the registry rows and compatibility policy
for all three segments. A namespace may expose multiple endpoints, and
each endpoint may expose multiple services. A service name is unique
within an endpoint for a namespace, and an operation name is unique
within a service for a namespace.

The address is durable. Callers may persist, log, retry, and audit the
triple as product vocabulary. They must not persist a queue name,
connection name, worker id, child workflow id, or handler class as the
service contract unless that value is also explicitly part of the
operation's product-level payload.

## Endpoint Indirection

Endpoint indirection is the rule that callers bind to the logical
contract address while the target namespace controls the concrete
handler binding behind that address.

The registry tables are the authority:

- `workflow_service_endpoints` owns endpoint names and endpoint-level
  metadata.
- `workflow_services` owns service names under an endpoint.
- `workflow_service_operations` owns operation names, operation mode,
  handler binding kind, target reference, binding payload, and
  operation policies.
- `workflow_service_calls` records each accepted call and snapshots the
  address and resolved binding used for that call.

A target namespace may move an operation from one binding kind to
another as an intentional contract change or implementation migration,
subject to the operation's documented compatibility policy. Existing
`workflow_service_calls` rows keep the binding that was resolved when
the call was accepted.

## Handler Binding Kinds

`workflow_service_operations.handler_binding_kind` and
`workflow_service_calls.resolved_binding_kind` are machine-readable
codes, but they describe different phases of resolution.
`handler_binding_kind` names the operation adapter configured in the
service catalog. The frozen handler binding adapter codes are:

| Handler binding adapter code | Resolution target |
| --- | --- |
| `start_workflow` | Start a new workflow run through the workflow-start contract. |
| `signal_workflow` | Send a signal to a target workflow instance or run. |
| `update_workflow` | Send an update to a target workflow instance or run. |
| `query_workflow` | Execute a read-only workflow query against a target instance or run. |
| `activity_execution` | Execute an activity through the activity execution lane. |
| `invocable_http` | Execute an invocable HTTP carrier operation. |

`workflow_service_calls.resolved_binding_kind` records the runtime
target kind after the adapter resolves the call. It must use the
`Workflow\V2\Enums\ServiceCallBindingKind` enum values:

| Runtime resolved binding kind | Handler adapter codes that resolve to it |
| --- | --- |
| `workflow_run` | `start_workflow`, `workflow_class`, or `workflow_run` |
| `workflow_update` | `update_workflow` or `workflow_update` |
| `workflow_signal` | `signal_workflow` or `workflow_signal` |
| `workflow_query` | `query_workflow` or `workflow_query` |
| `activity_execution` | `activity_execution` |
| `invocable_carrier_request` | `invocable_http` or `invocable_carrier_request` |

The handler binding payload is intentionally binding-specific. The
common fields are:

- `handler_binding_kind` - one of the frozen handler binding adapter
  codes.
- `handler_target_reference` - an optional operator-readable target
  reference, such as a workflow type, activity type, query name, update
  name, signal name, or carrier route.
- `handler_binding` - a JSON object containing binding-specific
  details needed by the runtime adapter.

Unknown handler adapter codes and unknown runtime resolved binding
kinds are out of contract for v2. A runtime that reads an unknown
binding kind must fail closed before accepting the call.

## Caller-Facing Dispatch

Callers dispatch by service contract address and payload. They do not
provide raw `(namespace, connection, queue)` fields as the public call
shape. The minimum call intent is:

- `endpoint_name`;
- `service_name`;
- `operation_name`;
- input payload reference or inline payload as defined by the payload
  storage contract;
- optional idempotency, deadline, cancellation, retry, and boundary
  policies that the operation allows.

When a call is accepted, `workflow_service_calls` snapshots:

- `namespace`, `caller_namespace`, and `target_namespace`;
- `endpoint_name`, `service_name`, and `operation_name`;
- `resolved_binding_kind` and `resolved_target_reference`;
- `workflow_service_endpoint_id`, `workflow_service_id`, and
  `workflow_service_operation_id`;
- payload references and policy JSON values;
- `caller_workflow_instance_id` and `caller_workflow_run_id` when the
  caller is a workflow;
- `linked_workflow_instance_id`, `linked_workflow_run_id`, and
  `linked_workflow_update_id` when the chosen binding resolves to a
  workflow lane.

The call record is the operator-visible audit trail. It must be
sufficient to answer "which durable capability did the caller ask
for?" and "which binding did the runtime use?" without reconstructing
queue topology.

## Resolution Semantics

Resolution is a registry lookup followed by binding-specific dispatch:

1. Resolve the target namespace and `endpoint_name` to one
   `workflow_service_endpoints` row.
2. Resolve `service_name` under that endpoint to one
   `workflow_services` row.
3. Resolve `operation_name` under that service to one
   `workflow_service_operations` row.
4. Validate the caller's boundary, idempotency, deadline,
   cancellation, and retry policy against the operation policy.
5. Snapshot the service call row before handing work to the binding
   adapter.
6. Dispatch through the adapter named by `handler_binding_kind`.

Binding adapters reuse existing execution lanes:

- `start_workflow` applies workflow start semantics, including routing
  resolution and compatibility rules, after the service call has
  resolved to a workflow target.
- `signal_workflow`, `update_workflow`, and `query_workflow` use the
  existing workflow message, update, and query contracts after the
  target workflow identity has been resolved by the binding.
- `activity_execution` uses the activity scheduling and execution
  contract after the binding chooses an activity target.
- `invocable_http` uses the invocable carrier contract owned by the
  host or control-plane adapter.

The service contract does not define a second routing resolver. It
decides which capability is being called; the selected binding then
uses the existing lane-specific resolver and policy checks.

## Relationship With Task Routing

Cross-namespace service addressing is additive over the existing
routing contracts. Namespace still partitions the poll surface, but
service dispatch is not itself a task-routing rewrite.

The routing contract continues to own:

- workflow and activity `(connection, queue)` resolution;
- snapped routing columns on workflow, task, activity, and schedule
  rows;
- compatibility enforcement at task claim time;
- queue visibility and matching-role partition behavior.

The service-addressing contract owns:

- stable endpoint, service, and operation names;
- endpoint indirection to a handler binding;
- call acceptance and audit records;
- the mapping from logical capability address to one execution lane.

A service operation may eventually resolve to a workflow task or
activity task that carries `(connection, queue)` internally. That is
an implementation detail of the selected binding. The caller speaks
in durable capability names, not worker topology.

Cross-namespace service addressing is therefore a contract boundary,
not a queue-selection trick.

## Relationship With Child Workflows

Child workflows remain lineage-bearing orchestration. They are not the
public cross-namespace service surface.

A workflow may call a service operation, and a service binding may
start a workflow. Either path may create workflow links or call
records that are useful for observability. Those records do not turn
the service address into a parent/child workflow relationship. The
public peer-to-peer boundary remains `endpoint/service/operation`.

Conversely, a workflow may still use child workflow calls for
hierarchical orchestration, parent-close policy, cancellation
propagation, continue-as-new transfer, and child outcome tracking.
Those lineage semantics are internal workflow orchestration semantics;
they are not the address a different namespace uses to call a
capability.

## Durable Records And Visibility

The service registry and call records are part of the v2 observable
surface:

- `WorkflowServiceEndpoint` exposes services, operations, and service
  calls for an endpoint.
- `WorkflowService` exposes operations and service calls under one
  service.
- `WorkflowServiceOperation` exposes service calls resolved through
  one operation.
- `WorkflowServiceCall` relates a call to its endpoint, service,
  operation, caller workflow instance or run, linked workflow instance
  or run, and linked workflow update.
- `WorkflowInstance` and `WorkflowRun` expose outgoing and linked
  service calls so product surfaces can show both sides of the
  peer-to-peer invocation.
- `WorkflowUpdate` exposes service calls linked to update delivery.

Waterline, CLI, server, and cloud surfaces that render service calls
must prefer the snapped call row over a live registry lookup when
describing an accepted call. The registry describes the current
contract; the call row describes what happened.

## Failure And Boundary Rules

The runtime must fail closed before accepting a call when:

- the target endpoint, service, or operation does not exist in the
  target namespace;
- the operation's handler binding kind is unknown;
- the caller violates the operation's boundary policy;
- the caller supplies idempotency, deadline, cancellation, or retry
  policy that the operation does not allow;
- the selected binding cannot resolve its target reference.

After a call is accepted, failures are recorded on
`workflow_service_calls` through the status, failure payload, failure
message, and timestamp fields. Binding-specific failures may also be
visible on linked workflow, update, activity, or invocable records,
but the service call row remains the cross-namespace audit record.

## Test Strategy Alignment

The behaviour frozen above is covered by coordinated tests:

- `tests/Unit/V2/CrossNamespaceServiceAddressingDocumentationTest.php`
  pins this contract document, the adjacent routing and child-call
  statements, every registry table, every model, and every frozen
  handler binding kind named here. It derives runtime resolved binding
  kinds from `Workflow\V2\Enums\ServiceCallBindingKind` so the
  cross-namespace service contract cannot drift from the runtime enum.
- migration tests cover creation and rollback of
  `workflow_service_endpoints`, `workflow_services`,
  `workflow_service_operations`, and `workflow_service_calls`.
- configured-model relationship tests cover the model relationships
  used by operator-visible service-call surfaces.

## What This Contract Does Not Yet Guarantee

- A network wire format for cross-process service dispatch. This
  document freezes the durable address and registry contract; HTTP,
  gRPC, or managed control-plane ingress shapes are separate protocol
  surfaces.
- Dynamic service discovery outside the workflow database. External
  registries may mirror this contract, but they do not replace the
  durable registry tables.
- Cross-database or cross-cluster routing. A service address may name
  a target namespace, but the v2 contract assumes one workflow
  database unless a future contract explicitly expands that boundary.
- A new queue selection API. Queue routing remains governed by
  `docs/architecture/routing-precedence.md` and
  `docs/architecture/task-matching.md`.

## Changing This Contract

A change to the address form, namespace ownership rules, binding kind
codes, registry tables, call-row snapshot fields, or the relationship
to task routing and child workflows is a protocol-level change. The
required process is:

1. Update this contract document first.
2. Update
   `tests/Unit/V2/CrossNamespaceServiceAddressingDocumentationTest.php`
   in the same change so the regression guard tracks the new rule.
3. Update migrations, models, concrete dispatch tests, and product
   surfaces that consume the service registry or service call rows.
4. Update adjacent docs that refer to routing, child workflow lineage,
   Waterline visibility, CLI output, or server/cloud control-plane
   ingress so the fleet speaks one language.
