# Workflow V2 Cross-Namespace Service Authorization and Policy Contract

This document freezes the v2 contract for the **service boundary**
that sits between a caller and a target operation when those two
parties live in different namespaces. It defines who may call what,
how rejections are surfaced, what is recorded as durable audit fact,
where service-level rate limiting and circuit-break behaviour live,
how outcomes are distinguished at the caller-facing surface, and how
operator surfaces preserve namespace scoping while still exposing
policy decisions for calls visible to the configured namespace. It is
the reference cited by product docs, CLI reasoning, Waterline
diagnostics, server deployment guidance, and test coverage so the
whole fleet speaks one language about cross-namespace policy.

The guarantees below apply to the `durable-workflow/workflow` package
at v2 and to every host that embeds it or talks to it over the
worker protocol. A change to any named guarantee is a protocol-level
change and must be reviewed as such, even if the class that
implements it is `@internal`.

This contract builds on the routing semantics frozen in
`docs/architecture/routing-precedence.md`, the parent–child call
topology described in `docs/architecture/workflow-child-calls-architecture.md`,
the control-plane / execution-plane role split frozen in
`docs/architecture/control-plane-split.md`, and the execution
guarantees frozen in `docs/architecture/execution-guarantees.md`.
Routing precedence intentionally defers cross-namespace routing to
this contract; the cross-namespace boundary is a policy surface, not
a routing surface. Namespaces partition the poll surface frozen in
`docs/architecture/task-matching.md`; this contract names how a call
crosses that partition without weakening it.

## Scope

The contract covers:

- **service boundary topology** — the four durable rows
  (`workflow_service_endpoints`, `workflow_services`,
  `workflow_service_operations`, `workflow_service_calls`) that
  together constitute the cross-namespace service surface and the
  unique-key invariants that make a contract address resolvable.
- **contract addressing** — the `(namespace, endpoint_name,
  service_name, operation_name)` tuple that names a callable
  operation, the resolution rules for that tuple, and the
  caller-versus-endpoint, service, and operation authorization
  decisions that gate it.
- **boundary policy enforcement order** — the sequence in which
  resolution, authorization, rate limiting, concurrency limiting,
  circuit-break check, and handler dispatch are evaluated, and which
  step is responsible for each distinguishable outcome.
- **service-level limits** — how rate limits, concurrency limits, and
  circuit-break state are declared *above* the handler binding so
  they apply uniformly regardless of which handler kind the
  operation eventually dispatches to.
- **outcome taxonomy** — the frozen set of caller-facing outcome
  values that distinguish target-not-found from forbidden, from
  throttled, from degraded, from handler-failed, plus the standard
  accepted, completed, cancelled, and timed-out outcomes.
- **audit facts** — the durable columns on `workflow_service_calls`
  that MUST be populated for every accepted *and* rejected call so
  caller identity, caller namespace, target contract address, and
  outcome are reconstructable without consulting the runtime.
- **payload privacy and trust** — how payload codec, data converter,
  and external payload storage from
  `docs/architecture/execution-guarantees.md` carry across the
  boundary unchanged, so the cross-namespace surface does not invent
  a second security model.
- **operator visibility** — how Waterline and adjacent operator
  surfaces stay scoped to their configured namespace while still
  exposing accepted and rejected calls visible to that namespace.

It does not cover:

- the matching service partitioning frozen in
  `docs/architecture/task-matching.md`. A cross-namespace call
  produces work whose `(connection, queue, compatibility,
  namespace)` is selected exactly as for an in-namespace call; the
  boundary does not re-resolve those primitives.
- compatibility marker selection, frozen in
  `docs/architecture/worker-compatibility.md`. Cross-namespace calls
  do not narrow or widen compatibility.
- the routing precedence rules for picking `(connection, queue)`,
  frozen in `docs/architecture/routing-precedence.md`. Routing is an
  in-namespace decision the boundary inherits, not a cross-namespace
  decision the boundary makes.
- workflow authoring API (`Workflow::*`, `ChildWorkflowOptions`,
  `ActivityOptions`). These are the in-namespace authoring surfaces;
  cross-namespace calls travel through the service boundary instead.
- per-host queue topology (priorities, sharding schemes, managed
  lanes). The host's queue layout is consumed by the boundary but
  not redefined by it.

## Terminology

- **Endpoint** — the `workflow_service_endpoints` row identified by
  `(namespace, endpoint_name)`. The endpoint is the outermost
  authority unit at the boundary: an operator can declare that a
  caller namespace may reach a specific endpoint without granting
  reach to siblings under the same namespace. Endpoint-level
  `boundary_policy` supplies defaults for the caller-versus-endpoint
  authorization axis.
- **Service** — the `workflow_services` row identified by
  `(namespace, workflow_service_endpoint_id, service_name)`. A
  service is the second authority unit; service-level authorization
  defaults and service-level limits apply here through the service
  row's `boundary_policy`.
- **Operation** — the `workflow_service_operations` row identified
  by `(namespace, workflow_service_id, operation_name)`. The
  operation is the innermost authority unit and is where the
  handler binding lives. Operation-level `boundary_policy` is the
  final effective policy snapshot after endpoint and service defaults
  are applied.
- **Contract address** — the resolved tuple `(namespace,
  endpoint_name, service_name, operation_name)` that names a
  callable operation. The contract address is the audit primary key
  for a call; it is recorded on every accepted or rejected
  `workflow_service_calls` row even when one of the components fails
  to resolve.
- **Caller identity** — the durable triple of
  `(caller_namespace, caller_workflow_instance_id,
  caller_workflow_run_id)` snapped onto each
  `workflow_service_calls` row at the moment the call enters the
  boundary. Caller identity is stamped *before* authorization
  evaluates so even forbidden calls are auditable.
- **Boundary policy** — the JSON `boundary_policy` block on
  `workflow_service_endpoints.boundary_policy`,
  `workflow_services.boundary_policy`, and
  `workflow_service_operations.boundary_policy` that names which
  caller namespaces, identities, and call shapes the boundary
  accepts. Operation-level boundary policy may inherit defaults from
  the containing service and endpoint, but the operation's row carries
  the effective copy used at evaluation time.
- **Service-level limits** — the rate limit, concurrency limit, and
  circuit-break configuration declared above the handler binding.
  Service-level limits apply to every call accepted by the boundary,
  regardless of which `handler_binding_kind` the operation resolves
  to.
- **Handler binding** — the operation-level `handler_binding`,
  `handler_binding_kind`, and `handler_target_reference` columns
  that name the in-namespace executor that actually performs the
  work once the boundary admits the call. Handler bindings are an
  execution detail; they do not see policy decisions.
- **Outcome** — the value stored on `workflow_service_calls.status`
  taken from the frozen Outcome taxonomy below. The outcome is the
  caller-facing answer to "what happened to my call?" and is the
  authoritative reconciliation source for the durable workflow
  history that recorded the call.
- **Rejection** — an outcome decided by the boundary before handler
  dispatch (`rejected_not_found`, `rejected_forbidden`,
  `rejected_throttled`, `rejected_concurrency_limited`,
  `rejected_circuit_open`). Rejections are distinguishable from
  `degraded` and `handler_failed`, which are decided by the bound
  handler after dispatch.

## Guaranteeing authority

The contract authorities are:

- **`WorkflowServiceEndpoint`** — the durable Eloquent model for an
  endpoint row. Its `(namespace, endpoint_name)` unique key is the
  outer addressing authority. New endpoint surfaces (admin APIs,
  fixtures, projections) MUST resolve the endpoint through this
  model rather than re-querying `workflow_service_endpoints`
  directly. Its `boundary_policy` column is the caller-versus-endpoint
  default policy source. Configurable via the `service_endpoint_model`
  config key consumed by `ConfiguredV2Models::resolve`.
- **`WorkflowService`** — the durable Eloquent model for a service
  row. Its `(namespace, workflow_service_endpoint_id, service_name)`
  unique key resolves a service under an endpoint and is the
  authority for service-level authorization defaults and service-level
  limits through `workflow_services.boundary_policy`.
- **`WorkflowServiceOperation`** — the durable Eloquent model for
  an operation row. Its `(namespace, workflow_service_id,
  operation_name)` unique key resolves the call target. The operation
  carries the authoritative `handler_binding`, `handler_binding_kind`,
  `handler_target_reference`, and `boundary_policy` columns.
- **`WorkflowServiceCall`** — the durable Eloquent model for a call
  row. Every cross-namespace call is recorded here at boundary
  entry, regardless of outcome. Callers and audit consumers MUST
  read call state from `WorkflowServiceCall` rather than from any
  in-memory boundary cache.
- **`workflow_service_calls.status`** — the single durable column
  carrying the Outcome value. The contract freezes the string set
  this column may hold; new outcomes are a protocol change.
- **`workflow_service_calls.resolved_binding_kind`** — the durable
  column recording which handler binding kind the boundary chose
  when accepting the call. This column is required even on
  rejections; for boundary rejections it carries the kind the
  operation row declared at the time of rejection (or `unresolved`
  when the operation itself failed to resolve).
- **`PayloadEnvelopeResolver`** — the existing payload codec
  authority frozen by `docs/architecture/execution-guarantees.md`.
  The boundary delegates `(codec, blob)` decoding to this class and
  to `CodecRegistry`; it does not introduce a second resolver.

A subsystem that resolves a contract address from its own
in-process cache without consulting these models, that adds an
outcome string outside the frozen taxonomy, or that recomputes
payload encoding outside the codec registry, is out of contract.

## Contract addressing

A cross-namespace call names its target by the contract address
tuple `(namespace, endpoint_name, service_name, operation_name)`.

### Resolution

Resolution proceeds left-to-right and is deterministic:

1. The boundary reads `workflow_service_endpoints` filtered on
   `(namespace, endpoint_name)`. The unique key `wf_service_endpoints_namespace_name_unique`
   guarantees at most one row.
2. If the endpoint resolves, the boundary reads `workflow_services`
   filtered on `(namespace, workflow_service_endpoint_id,
   service_name)`. The unique key
   `wf_services_namespace_endpoint_name_unique` guarantees at most
   one row.
3. If the service resolves, the boundary reads
   `workflow_service_operations` filtered on `(namespace,
   workflow_service_id, operation_name)`. The unique key
   `wf_service_ops_namespace_service_name_unique` guarantees at
   most one row.

If any step finds no row, the boundary records the call with
`status = 'rejected_not_found'` and stops. The operation is not
distinguished from the service or the endpoint at the caller-facing
outcome — the rejection is uniform — but the row records which
component failed to resolve in `metadata.resolution_failed_at`
(`endpoint`, `service`, or `operation`) so operators can diagnose
without re-running the call.

### Caller namespace versus target namespace

The caller's `caller_namespace` and the target's `target_namespace`
are stored as separate columns on `workflow_service_calls`. They
MAY be equal — an in-namespace call still travels the service
boundary when the caller addresses it as a service call rather than
as an in-process child or activity. The caller-versus-endpoint
authorization rule applies regardless of whether the namespaces
match; same-namespace calls do not bypass the boundary.

### Idempotency key

The optional `idempotency_key` column makes a call idempotent at
the boundary. When present, the boundary MAY return the recorded
outcome of an earlier call with the same `(target_namespace,
endpoint_name, service_name, operation_name, idempotency_key)`
tuple instead of accepting the new call. Idempotency replay is a
cache, not a re-execution; the audit row of the original call
remains the durable record. Idempotency policy is declared in the
operation row's `idempotency_policy` JSON.

## Boundary policy enforcement order

For every call that enters the boundary, the boundary evaluates the
following steps in order. Each step has a fixed Outcome it produces
when the call does not pass:

1. **Caller stamp.** Snap caller identity and contract address onto
   a new `workflow_service_calls` row in `status = 'pending'`. This
   step never rejects; it exists so steps 2–6 always have a durable
   row to update.
2. **Address resolution.** Resolve `(namespace, endpoint_name,
   service_name, operation_name)` using the rules in
   "Contract addressing" above. On miss, set `status =
   'rejected_not_found'` and stop.
3. **Authorization.** Evaluate the operation's `boundary_policy`
   against caller identity. The policy MUST evaluate three axes:
   *caller-versus-endpoint* (is this caller namespace allowed to
   reach this endpoint at all?), *service* (is this caller allowed
   on this service under that endpoint?), and *operation* (is this
   caller allowed on this specific operation?). On any axis denying
   the call, set `status = 'rejected_forbidden'` and stop. The
   axis that denied is recorded in `metadata.forbidden_axis`
   (`endpoint`, `service`, or `operation`).
4. **Rate limit.** Apply the operation's rate limit (with
   service-level fallback). On rejection, set `status =
   'rejected_throttled'` and stop. Rate-limit windows are declared
   in `boundary_policy.rate_limit`.
5. **Concurrency limit.** Apply the operation's concurrency limit
   (with service-level fallback). On rejection, set `status =
   'rejected_concurrency_limited'` and stop. Concurrency tokens are
   tracked against the in-flight count of `accepted` plus `running`
   calls for the operation.
6. **Circuit break.** Consult the circuit-break state for the
   operation. If the circuit is open, set `status =
   'rejected_circuit_open'` and stop. Circuit state is declared in
   `boundary_policy.circuit_break` and is itself derived from the
   recent rolling count of `handler_failed` outcomes — it is not a
   second source of authority for those failures, only a debounce.
7. **Handler dispatch.** Set `status = 'accepted'`, populate
   `resolved_binding_kind` from the operation row, and dispatch to
   the bound handler. The handler then drives the call through
   `running` to a terminal outcome (`completed`, `handler_failed`,
   `cancelled`, or `degraded`).

### Why this order is fixed

The order is the contract because the order is what makes outcomes
*distinguishable*. A caller that sees `rejected_forbidden` knows
the address resolved; a caller that sees `rejected_throttled`
knows authorization passed; a caller that sees
`rejected_circuit_open` knows the operation exists, the caller is
authorized, and quota was available — but recent handler failures
have tripped the breaker. Reordering or collapsing these steps
collapses the diagnostic surface frozen here.

### Distinguishability invariant

Resolution, authorization, throttling, concurrency, circuit-break,
and handler failure are recorded as distinct outcome values on
`workflow_service_calls.status`. A caller-facing surface that
collapses any pair of these into a shared value (for example,
returning a generic `failed` for both `rejected_forbidden` and
`handler_failed`) is out of contract.

## Service-level limits

Rate limit, concurrency limit, and circuit-break configuration are
declared *above* the handler binding so they apply uniformly across
every `handler_binding_kind` the operation may bind to.

The configuration surface lives under the `boundary_policy` JSON
column on `workflow_service_endpoints`, `workflow_services`, and
`workflow_service_operations`. Endpoint policy supplies
caller-versus-endpoint defaults; service policy supplies service-level
authorization and limit defaults; operation policy supplies
operation-level overrides. The operation row is the authoritative
effective copy used at evaluation time.

### Rate limit

`boundary_policy.rate_limit` declares a count-per-window quota. The
boundary enforces rate limit at step 4 of the enforcement order
above, before any handler is consulted. A handler that performs
its own throttling does not satisfy this contract — service-level
rate limit is a *boundary* responsibility because it must apply to
calls the handler never sees (rejected calls, idempotency replays,
calls dispatched to a different binding).

### Concurrency limit

`boundary_policy.concurrency_limit` declares an in-flight count
ceiling. The in-flight set is `(accepted, running)` calls on the
operation. Concurrency limit is enforced at step 5 of the
enforcement order. The implementation is allowed to use any
counting strategy — token bucket, semaphore, projection — as long
as it observes the same set of rows.

### Circuit break

`boundary_policy.circuit_break` declares a circuit-break policy
parameterised by failure rate, failure window, half-open probe
count, and reset interval. Circuit state is computed from the
rolling count of `handler_failed` outcomes recorded in
`workflow_service_calls`. The circuit-break check at step 6 of the
enforcement order produces `rejected_circuit_open` when the
circuit is open. It does not produce `degraded`; degraded is
reserved for calls the handler accepted and partially completed
with a fallback path.

### Service-level limits apply across handler bindings

A service-level limit declared on an operation applies to every
handler binding kind. Switching the operation's
`handler_binding_kind` from one kind to another (for example,
from a workflow handler to an activity handler) does not reset or
relax the limit. The boundary does not consult the bound handler
when computing the limit.

## Outcome taxonomy

The frozen set of values stored in `workflow_service_calls.status`
is:

- `pending` — the row has been created at step 1 of the boundary
  enforcement order but no terminal step has been reached yet.
  Pending rows are not caller-visible as a final outcome.
- `accepted` — the call passed all six boundary checks and has
  been dispatched to the bound handler. Accepted is a transient
  state for live calls; the row will move to `running` and then to
  a terminal outcome.
- `running` — the bound handler has begun executing the call.
  Running is also transient.
- `completed` — the handler returned successfully. The
  `output_payload_reference` carries the result envelope.
- `cancelled` — the call was cancelled (by the caller, the
  handler, or a parent close policy). Cancellation semantics
  follow `docs/architecture/cancellation-scope.md`.
- `degraded` — the handler accepted the call and returned a
  fallback result rather than its full result. Degraded is a
  *handler-driven* outcome, not a boundary-driven one; it is
  surfaced as a distinct value so operators can reconcile against
  service-level expectations even when the bound handler completed
  successfully.
- `handler_failed` — the bound handler returned a typed failure.
  The failure is captured in `failure_payload_reference` and
  `failure_message` per the existing `FailureCategory` model.
- `rejected_not_found` — address resolution failed at step 2.
- `rejected_forbidden` — authorization failed at step 3.
- `rejected_throttled` — rate limit rejected the call at step 4.
- `rejected_concurrency_limited` — concurrency limit rejected the
  call at step 5.
- `rejected_circuit_open` — circuit-break check rejected the call
  at step 6.

A row's terminal outcome is the value this column holds when one
of `completed_at`, `failed_at`, or `cancelled_at` is non-null, or
when the row is created in a terminal `rejected_*` state without
ever moving to `accepted`.

### Mapping handler outcomes to FailureCategory

`handler_failed` outcomes map to the existing FailureCategory model
frozen in `docs/architecture/cancellation-scope.md` and in
`Workflow\V2\Enums\FailureCategory`. The boundary does not invent a
second failure taxonomy; a `handler_failed` call's
`failure_payload_reference` resolves to a `FailureCategory` value
already enumerated by that contract (`application`, `cancelled`,
`terminated`, `timeout`, `activity`, `child_workflow`,
`task_failure`, `internal`, `structural_limit`).

### Why a separate `handler_failed`

`handler_failed` is distinct from `degraded` because they answer
different operator questions. `handler_failed` means "the bound
handler raised". `degraded` means "the bound handler reported a
weaker contract was met than the headline contract advertises".
Both are caller-visible, but only the former drives the
circuit-break window described above.

## Audit facts

For every call — accepted *or* rejected — the boundary populates
the following columns on `workflow_service_calls` before the row
becomes terminal:

- **Caller identity columns**: `caller_namespace`,
  `caller_workflow_instance_id`, `caller_workflow_run_id`. These are
  stamped in step 1 of the enforcement order, before authorization
  runs, so forbidden calls are auditable. The
  `caller_workflow_instance_id` column may be null for calls
  originating outside any workflow run; the other two columns are
  always populated when the caller is reachable through the worker
  protocol.
- **Contract address columns**: `target_namespace`, `endpoint_name`,
  `service_name`, `operation_name`. These hold the literal name
  parts the caller addressed, not the resolved row ids, so a
  rejection that fails resolution still carries a meaningful audit
  address. The resolved row ids
  (`workflow_service_endpoint_id`, `workflow_service_id`,
  `workflow_service_operation_id`) are populated in addition when
  resolution succeeded; an unresolved-row id is recorded as the
  literal string `unresolved` rather than left null, so projections
  can distinguish "we never tried to resolve" from "we tried and
  missed".
- **Outcome columns**: `status`, plus the appropriate timestamp
  among `accepted_at`, `completed_at`, `failed_at`, `cancelled_at`.
  The `accepted_at` column doubles as "the boundary admitted the
  call"; rejected rows leave it null.
- **Linked-run columns**: `linked_workflow_instance_id`,
  `linked_workflow_run_id`, `linked_workflow_update_id`. These point
  to the in-namespace durable entity the boundary spawned to
  service the call (a child workflow run, an activity execution
  recorded against a run, or an update). Rejected calls leave
  these null.
- **Payload columns**: `payload_codec`, `input_payload_reference`,
  `output_payload_reference`, `failure_payload_reference`,
  `failure_message`. These reuse the codec and external-payload
  storage model frozen elsewhere; see "Payload privacy and trust".
- **Policy snapshot columns**: `workflow_service_calls.boundary_policy`,
  `idempotency_policy`, `deadline_policy`, `cancellation_policy`,
  `retry_policy`. These columns snap the policy values that were
  effective when the call was admitted; later edits to the
  operation row do not retroactively rewrite the call's snapshot.

A boundary implementation that records a call only on success — or
that records a rejection without caller identity, without the
attempted contract address, or without an outcome from the frozen
taxonomy — is out of contract.

## Payload privacy and trust

Payload privacy and the trust boundary on payload bytes reuse the
existing codec and data-converter model frozen in
`docs/architecture/execution-guarantees.md`. Specifically:

- The `payload_codec` column on `workflow_service_calls` carries
  the codec name resolved by `CodecRegistry` from the caller's
  envelope.
- Payload decoding goes through `PayloadEnvelopeResolver`. The
  service boundary does not implement a parallel decoder.
- Large payloads externalised via the
  `Workflow\V2\Contracts\ExternalPayloadStorageDriver` follow the
  same `*_payload_reference` column convention used by
  `workflow_runs` for run input, output, and failure references.
- Cryptographic codecs (registered through the same registry)
  protect the payload at rest and in transit. The cross-namespace
  boundary does not own a second key model; key custody is the
  codec's responsibility, exactly as in the in-namespace path.

The trust boundary on inputs is therefore identical to the
in-namespace trust boundary: a worker that can decode a payload can
read it, and a worker that cannot decode a payload cannot read it
even if the boundary admitted the call. The boundary's job is to
decide who may *send* a payload through; codec registration is what
decides who may *open* it on the receiving side.

## Operator visibility

Operator surfaces (Waterline, the CLI, and Cloud) are namespace
scoped: a Waterline tenant configured for namespace `A` does not
see calls whose `namespace` (the durable namespace recorded on the
row) is `B`. Cross-namespace policy outcomes are exposed within
that scope:

- `OperatorQueueVisibility::forNamespace($namespace)` continues to
  scope queue depth and lease counts to the configured namespace.
  Cross-namespace calls do not perturb this scope.
- The run-detail surface frozen in
  `docs/architecture/routing-precedence.md` (`RunDetailView::forRun`)
  exposes any `workflow_service_calls` rows whose
  `caller_workflow_run_id` or `linked_workflow_run_id` equals the
  run id under inspection. This is how an operator looking at a run
  in their namespace sees the boundary outcomes of calls that ran or
  spawned work for that run, even when the *target_namespace*
  column points elsewhere.
- Listing surfaces filter on `workflow_service_calls.namespace`
  (the durable namespace column), then disclose rows whose
  `caller_namespace` or `target_namespace` matches the configured
  namespace. The unique-key invariants on
  `workflow_service_endpoints`, `workflow_services`, and
  `workflow_service_operations` mean an endpoint name in one
  namespace never aliases an endpoint name in another; operators
  do not need to disambiguate by id.
- Operators MAY see boundary rejections (`rejected_forbidden`,
  `rejected_throttled`, `rejected_concurrency_limited`,
  `rejected_circuit_open`, `rejected_not_found`) for calls in their
  namespace exactly as they see successful or handler-failed
  outcomes. Surfacing rejections is required, not optional: a
  boundary that hides rejections from the operator surface is out of
  contract because the rejection is the audit fact.

A surface that recomputes outcome from in-memory state, that filters
out rejected rows, or that exposes calls whose namespace does not
match the configured namespace, is out of contract.

## Interaction with adjacent contracts

- **Routing precedence** (`docs/architecture/routing-precedence.md`):
  the linked durable rows produced by an accepted call inherit
  routing through the rules frozen there. The boundary does not
  override routing; it produces work, and the routing resolver
  picks `(connection, queue)` for that work in the in-namespace
  way.
- **Worker compatibility** (`docs/architecture/worker-compatibility.md`):
  cross-namespace calls travel with the same compatibility marker
  the linked run inherits. The boundary does not narrow or widen
  compatibility.
- **Child outcome source-of-truth**
  (`docs/architecture/child-outcome-source-of-truth.md`): when a
  cross-namespace call is implemented by a child workflow handler
  binding, the parent reads the child's outcome through the
  precedence frozen there. The `workflow_service_calls` row is the
  *boundary's* record; the child's run is the handler's record.
  Both must agree, and they do because the boundary updates the
  call row from the same authoritative child-run resolution.
- **Cancellation scope**
  (`docs/architecture/cancellation-scope.md`): cancelling the
  caller run cancels the linked run through normal cancellation
  scope rules, then the boundary marks the call `cancelled`. A
  caller-side cancellation does not produce `handler_failed` even
  if the handler observes the cancellation as an exception.
- **Execution guarantees**
  (`docs/architecture/execution-guarantees.md`): cross-namespace
  calls are exactly-once at the durable boundary (one terminal
  outcome per call row) and at-most-once at the handler binding
  when the handler binding kind is exactly-once. The boundary does
  not weaken handler-side execution guarantees.

## Config surface and defaults

The runtime config surface for cross-namespace service policy is
intentionally small:

- `workflows.v2.namespace` — the local namespace value carried on
  `workflow_runs`, `workflow_tasks`, and `workflow_schedules`. The
  boundary reads this value to populate
  `workflow_service_calls.namespace` when accepting an outbound
  call from an in-namespace caller.
- `workflows.v2.service_endpoint_model`,
  `workflows.v2.service_model`,
  `workflows.v2.service_operation_model`, and
  `workflows.v2.service_call_model` — the
  `ConfiguredV2Models::resolve` keys that name the durable Eloquent
  classes the boundary instantiates. Hosts MAY swap in subclasses
  but MUST preserve the column shape and the unique-key invariants
  named above.

The contract does not introduce new environment variables. All
boundary behaviour is controlled through the durable rows and the
config keys above.

## What this contract does not yet guarantee

These are intentionally out of scope for the v2 cross-namespace
service authorization and policy contract; each is covered
elsewhere or deferred to a follow-on phase:

- **Cross-cluster federation.** A call whose target namespace is
  hosted by a different physical cluster is out of scope. The
  contract assumes the boundary can read all four service tables
  in the local database. Federation is a future protocol extension.
- **Distributed circuit-break state.** The circuit-break check is
  defined in terms of the rolling count of `handler_failed`
  outcomes recorded in the local `workflow_service_calls` table.
  Replicating circuit state across hosts is out of scope.
- **Caller-attested identity claims.** Caller identity is the
  `(caller_namespace, caller_workflow_instance_id,
  caller_workflow_run_id)` triple the worker protocol carries.
  Stronger attestation (signed caller bundles, mutual TLS pinning)
  is out of scope and would extend rather than replace this
  contract.
- **Per-operation budget tokens.** Token-bucket budgets that span
  multiple operations within a service are deferred. The
  service-level rate limit named here applies per operation with
  service-level fallback only.
- **Cross-namespace routing decisions.** Routing precedence
  defers cross-namespace routing to this document, but this
  document also defers it: the boundary produces work that is
  routed by the in-namespace resolver. An operator who needs to
  pin a cross-namespace call to a specific connection or queue
  uses the operation row's `handler_binding` to declare the
  routing target on the linked run, exactly as for an
  in-namespace call.

## Test strategy alignment

The behaviour frozen above is covered by the following coordinated
tests. A change to any of the rules above must update both the
documented contract and the matching test in the same change:

- The pinning test
  `tests/Unit/V2/CrossNamespaceServicePolicyDocumentationTest.php`,
  which asserts the contract document contains the frozen
  headings, terms, authority class names, durable column names,
  outcome taxonomy values, enforcement-order steps, and citations
  named in this document.
- Migration regression coverage on the four
  `workflow_service_*` tables ensuring the unique-key invariants
  (`wf_service_endpoints_namespace_name_unique`,
  `wf_services_namespace_endpoint_name_unique`,
  `wf_service_ops_namespace_service_name_unique`) remain in place.
- `OperatorQueueVisibility` coverage that confirms the namespace
  scoping rule continues to apply when service-call surfaces are
  added to the operator views.
- Coverage of `PayloadEnvelopeResolver` and `CodecRegistry` for
  cross-namespace payload paths ensuring the boundary delegates
  rather than duplicates the codec model.

## Changing this contract

A change to any of the addressing rules, enforcement order steps,
outcome taxonomy values, audit columns, or operator-visibility
rules above is a protocol-level change. The required process is:

1. Update this contract document first, including the terminology,
   the enforcement-order table, the outcome taxonomy, and the test
   strategy alignment section as appropriate.
2. Update the pinning test
   `tests/Unit/V2/CrossNamespaceServicePolicyDocumentationTest.php`
   in the same change so the regression guard tracks the new rule.
3. Update the concrete behaviour tests (migrations, codec, operator
   views) listed above so they exercise the new rule.
4. Update product docs on the docs site, CLI reasoning, and
   Waterline surfaces that reference the rule so the fleet speaks
   one language.

This contract intentionally defers cross-cluster federation,
distributed circuit-break replication, caller-attested identity
claims, and per-operation budget tokens to future roadmap items
rather than redefining them here.

See `docs/architecture/routing-precedence.md`,
`docs/architecture/workflow-child-calls-architecture.md`,
`docs/architecture/control-plane-split.md`,
`docs/architecture/cancellation-scope.md`,
`docs/architecture/child-outcome-source-of-truth.md`, and
`docs/architecture/execution-guarantees.md` for the adjacent frozen
contracts this document builds on.
