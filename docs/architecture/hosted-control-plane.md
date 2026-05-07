# Workflow V2 Hosted Control-Plane Contract

This document freezes the v2 contract for the package to standalone
server to managed-cloud ladder. It names which semantics belong to the
workflow runtime, which semantics a hosted control plane may add above
that runtime, and which boundaries must remain versioned so managed
cloud is packaging and productization rather than a semantic fork.

The guarantees below apply to the `durable-workflow/workflow` package
at v2, to the standalone `durable-workflow/server` that embeds it, and
to any managed or hosted control plane that presents Durable Workflow
runtime targets to operators. A change to any named guarantee is a
protocol-level change and must be reviewed as such, even if the class
that implements it is `@internal`.

This contract builds on the semantics frozen in
`docs/architecture/execution-guarantees.md` (Phase 1), the routing
guarantees frozen in `docs/architecture/worker-compatibility.md`
(Phase 2), the matching and dispatch guarantees frozen in
`docs/architecture/task-matching.md` (Phase 3), the role-split
contract frozen in `docs/architecture/control-plane-split.md`
(Phase 4), the cache-independence contract frozen in
`docs/architecture/scheduler-correctness.md` (Phase 5), the
rollout-safety envelope frozen in
`docs/architecture/rollout-safety.md` (Phase 6), and the worker
deployment surface frozen in
`docs/architecture/worker-deployment.md` (Phase 7). Those contracts
keep their authority; this document adds the hosted-control-plane
layer above them.

The machine-readable mirror of this document is
`Workflow\V2\Support\HostedControlPlaneContract`. Hosted control
planes and standalone servers that expose this layer publish the same
manifest under `hosted_control_plane_contract` in their discovery
surface. Schema:
`durable-workflow.v2.hosted-control-plane.contract`, version `1`.

## Scope

The contract covers:

- **deployment ladder** - the explicit progression from embedded
  package, to standalone server, to managed cloud.
- **tenant hierarchy** - the organization, project, environment, and
  namespace hierarchy that exists above runtime namespaces.
- **hosted IAM and machine identity** - how users, service accounts,
  worker credentials, and provider support identities map to runtime
  commands.
- **quota, metering, and fairness** - which budget concepts are
  allowed above a runtime and which machine-readable refusal reasons
  operators see.
- **region, residency, and disaster-recovery boundaries** - how a
  namespace is placed on a runtime target and what moving it means.
- **worker connectivity modes** - which traffic goes to the hosted
  control plane and which traffic must continue to use the runtime
  worker protocol.
- **provider support and admin actions** - which privileged actions
  are allowed and how they are audited.
- **control-plane API lifecycle** - the schema, version, header, and
  change rules for hosted-control-plane APIs and manifests.
- **audit and export boundaries** - which facts live in hosted audit
  logs, which live in runtime history, and how exports join them.

It does not cover:

- a promise that a managed cloud product is available in every
  deployment.
- provider-specific infrastructure choices such as VPC peering, VPN,
  PrivateLink, Cloudflare Tunnel, Kubernetes operators, or managed
  database SKUs.
- changing the worker protocol, history-event wire formats, matching
  contract, or execution guarantees. Cloud mode consumes those
  contracts; it does not redefine them.
- active/active multi-region execution, automatic cross-region
  failover, RPO=0 replication, or region-pinned task queues as a
  self-serve engine guarantee.

## Terminology

- **Embedded package** - an application that installs
  `durable-workflow/workflow` directly and hosts the runtime inside
  the Laravel app. The application owns auth, tenancy, workers, and
  operator access.
- **Standalone server** - the language-neutral HTTP runtime that embeds
  the package, exposes the control-plane and worker-protocol APIs, and
  owns runtime namespaces, schedules, workers, history, and visibility.
- **Managed cloud** - a hosted control plane that owns tenant
  hierarchy, hosted identity, runtime-target inventory, quota,
  metering, audit, and operator workflows above one or more runtime
  targets.
- **Runtime target** - one standalone server or server cluster that
  owns workflow execution for assigned namespaces. The runtime target
  has a base URL, a region, a discovery surface, and health state.
- **Tenant hierarchy** - the hosted organization, project, and
  environment hierarchy above namespaces. The namespace remains the
  runtime execution boundary.
- **Namespace endpoint** - the runtime control-plane endpoint used for
  commands against a namespace. It is selected by runtime target base
  URL plus explicit namespace identity.
- **Hosted control-plane endpoint** - the hosted API endpoint used for
  organization, project, environment, runtime-target, audit, quota,
  and support workflows.
- **Machine identity** - a non-human principal such as a service
  account, worker credential, runtime-target credential, support
  automation credential, or provider-managed internal actor.
- **Quota budget** - a named limit applied above the runtime, such as
  command rate, worker poll rate, active leases, export bytes, storage
  bytes, or retention days.
- **Runtime placement** - the binding of one namespace to one runtime
  target, including region and residency profile.
- **Connectivity mode** - the supported network path used by workers,
  clients, and hosted automation to reach the runtime target.
- **Provider admin action** - a privileged operation performed by the
  hosted provider, such as provisioning a namespace, rotating a
  machine identity, changing a quota, or opening a support access
  session.
- **Audit export boundary** - the rule that hosted audit logs describe
  hosted identity and admin actions, while runtime history export
  describes workflow facts.

## Deployment Ladder

Durable Workflow v2 has one runtime contract and three packaging
levels:

| Level | Owner | Contract boundary | Stable facts |
| --- | --- | --- | --- |
| `embedded_package` | Application host | PHP package, Laravel queue, application auth | Workflow ids, run ids, history events, commands, retries, leases, repair, and worker compatibility. |
| `standalone_server` | Runtime operator | HTTP control-plane API, HTTP worker protocol, namespace auth, `/api/cluster/info` | Same runtime facts, plus explicit namespace endpoints, protocol headers, role topology, and machine-readable discovery. |
| `managed_cloud` | Hosted control-plane provider plus runtime operator | Hosted tenant hierarchy and runtime-target inventory above one or more standalone runtime targets | Same runtime facts, plus hosted identity, quota, metering, audit, support workflows, placement, and target health inventory. |

Guarantees:

- The ladder is additive. Moving from package to standalone server to
  managed cloud MUST NOT change history-event meanings, worker task
  envelopes, lease rules, repair semantics, or workflow command
  outcomes.
- The standalone server is the runtime target for managed cloud. A
  hosted control plane may provision, inventory, meter, and route to a
  runtime target, but workflow starts, signals, updates, cancels,
  schedules, worker polling, task completion, and history export remain
  runtime-owned facts.
- Existing in-flight runs stay on the runtime target that accepted
  them unless an explicit migration plan moves the namespace outside
  the normal live-run contract.

## Tenant Hierarchy Above Namespaces

The hosted tenant hierarchy is:

```text
organization
  project
    environment
      namespace  --->  runtime target
```

Guarantees:

- `organization`, `project`, and `environment` are hosted-control-plane
  dimensions. They are not persisted history-event fields and they do
  not change workflow ids or run ids.
- `namespace` remains the runtime execution boundary. Runtime
  authorization, worker registration, task queues, schedules, history,
  visibility, and repair are scoped to a namespace.
- A namespace belongs to exactly one runtime target at a time. Moving a
  namespace to another target is a deliberate placement change with
  storage, worker-drain, compatibility, and audit consequences.
- Cloud callers MUST NOT infer namespace from a URL path, UI label, or
  tenant hierarchy alone. Runtime commands carry an explicit namespace
  identity through the namespace endpoint.
- Runtime-target inventory is hosted data. Workflow state is runtime
  data. Copying inventory rows does not move workflow state.

## Hosted IAM And Machine Identity

Hosted identity sits above runtime auth. It may issue or map to runtime
credentials, but it does not bypass runtime command attribution.

Required identity classes:

- `hosted_user` - a human member of an organization, project, or
  environment.
- `service_account` - a customer-owned machine principal for
  automation.
- `worker_credential` - a runtime-scoped credential allowed to register,
  poll, heartbeat, complete, and fail tasks.
- `runtime_target_credential` - a hosted principal used by cloud
  automation to call a runtime target.
- `provider_support_actor` - a provider-owned support identity with
  scoped, time-bounded access.

Guarantees:

- Every hosted command that reaches a runtime target includes enough
  attribution for runtime audit: actor type, actor id or redacted
  subject, capability, target namespace/resource, hosted audit id or
  request fingerprint, and command outcome.
- Runtime credentials are role-scoped. A worker credential cannot
  become an operator credential by being routed through cloud.
- Provider support access is disabled by default, time-bounded when
  enabled, scoped to named resources, and audited as a provider admin
  action.
- Hosted identity may deny a command before it reaches the runtime. A
  runtime may still deny a command after hosted identity allowed it.
  The stricter denial wins and must surface a machine-readable reason.

## Quota, Metering, And Fairness

A hosted control plane may apply quota and metering above the runtime
without changing the runtime's durable semantics.

Frozen quota budget names:

| Budget | Meaning |
| --- | --- |
| `namespace_command_rate` | Accepted mutating control-plane commands per namespace. |
| `namespace_worker_poll_rate` | Worker poll and heartbeat pressure per namespace. |
| `task_queue_active_leases` | Active task leases per namespace and task queue. |
| `history_export_bytes` | Bytes exported through history or support bundles. |
| `storage_bytes` | Runtime-owned payload/history storage attributed to the namespace. |
| `retention_days` | Maximum configured retention horizon for runtime-owned records. |

Frozen quota refusal reasons:

| Reason | Meaning |
| --- | --- |
| `quota_exceeded` | The named budget has no remaining allowance. |
| `metering_unavailable` | The control plane cannot prove budget state and is configured to fail closed. |
| `fair_share_throttled` | The request is delayed or refused to protect another namespace, tenant, or dependency. |
| `tenant_suspended` | Hosted tenant state blocks new mutating work. |
| `residency_blocked` | The requested target would violate the namespace residency profile. |

Guarantees:

- Quota refusals are admission results. They do not rewrite workflow
  history and they do not convert a durable task into a different task
  type.
- A quota refusal for a new command MUST return a machine-readable
  reason, the budget name, the scope, and whether retry is allowed.
- Metering may lag runtime facts, but it MUST NOT become the source of
  truth for history, leases, schedules, or task completion.
- Fairness controls may shape admission and dispatch pressure. They
  MUST NOT violate the Phase 1 execution guarantees or the Phase 3
  matching lease rules.

## Region, Residency, And Disaster Recovery

Runtime placement binds a namespace to one runtime target:

- `runtime_target_id`
- `runtime_target_base_url`
- `region`
- `residency_profile`
- `dr_tier`
- `active_placement`

Guarantees:

- Region and residency are explicit operator-visible facts. A
  namespace placement must show which runtime target and region own
  the namespace before operators route workers or command traffic.
- Residency policy is enforced before routing a hosted command to a
  runtime target. A placement or command that violates residency
  returns `residency_blocked`.
- Disaster recovery is a placement and runbook contract, not an
  invisible semantic change. Moving a namespace across runtime targets
  requires explicit authority transfer, worker endpoint changes, and
  audit evidence.
- Automatic active/active runtime execution is not implied by managed
  cloud. A hosted control plane may inventory multiple regions, but a
  namespace still has one active runtime target for durable writes at a
  time unless a future contract says otherwise.
- Runtime history export and backup/restore remain runtime-owned. Cloud
  may orchestrate or inventory exports, but it does not become the
  source of truth for workflow state.

## Worker Connectivity Modes

Workers speak the runtime worker protocol. A hosted control plane does
not introduce a second worker API.

Frozen connectivity modes:

| Mode | Self-serve status | Worker traffic path | Notes |
| --- | --- | --- | --- |
| `direct_runtime` | `stable` | Worker to runtime target base URL | Default mode. Registration, polling, heartbeats, completion, and failure use the standard worker protocol. |
| `customer_private_network` | `support_led` | Worker to private runtime target address | Requires provider or operator network design. The protocol is unchanged. |
| `provider_managed_workers` | `support_led` | Provider-hosted worker to runtime target | Managed placement changes operations, not task semantics. |
| `cloud_relay` | `support_led` | Worker traffic relayed to runtime target | Relay behavior is not a new correctness layer and must preserve worker-protocol envelopes. |

Guarantees:

- Worker registration, long polling, heartbeat, completion, and failure
  target the runtime worker endpoint for the namespace's runtime
  target.
- Hosted control-plane endpoints may manage worker inventory,
  credentials, and health summaries. They MUST NOT become the authority
  for task claims or task completion.
- Connectivity mode is discoverable per runtime target. A worker must
  know whether it is using `direct_runtime`, `customer_private_network`,
  `provider_managed_workers`, or `cloud_relay`; the mode is never a
  hidden fallback.
- A support-led connectivity mode may add network hops, but it cannot
  change payload codecs, compatibility markers, task lease rules, or
  retry semantics.

## Endpoint Routing Rules

There are three endpoint classes:

| Endpoint class | Purpose | Namespace required |
| --- | --- | --- |
| `hosted_control_plane` | Organization, project, environment, runtime-target, IAM, quota, audit, and support workflows. | Only for namespace-management actions. |
| `runtime_namespace_endpoint` | Workflow starts, signals, updates, queries, cancels, schedules, repair, visibility, and history export. | Always. |
| `runtime_worker_endpoint` | Worker register, poll, heartbeat, complete, and fail. | Always. |

Guarantees:

- Runtime namespace and worker endpoints require explicit namespace
  identity and the relevant protocol header. The base URL alone is not
  a tenant boundary.
- Hosted endpoints may resolve a namespace to a runtime target before
  forwarding a command, but the forwarded runtime call still uses the
  runtime namespace endpoint and carries command attribution.
- A request sent to the wrong endpoint class fails closed with a
  machine-readable endpoint error. Implementations MUST NOT silently
  translate worker traffic into hosted-control-plane traffic.
- Endpoint routing rules are part of the hosted-control-plane contract.
  Changing an endpoint class name, changing which class owns worker
  traffic, or removing the explicit namespace requirement is a
  protocol-level change.

## Provider Support And Admin Actions

Frozen provider admin actions:

| Action | Purpose |
| --- | --- |
| `provision_namespace` | Create or attach a namespace under a tenant hierarchy. |
| `attach_runtime_target` | Register a runtime target and its discovery surface. |
| `move_namespace_target` | Change a namespace placement after migration checks pass. |
| `suspend_principal` | Block a hosted user, service account, or worker credential. |
| `resume_principal` | Re-enable a suspended principal. |
| `rotate_machine_identity` | Rotate runtime-target, service-account, or worker credentials. |
| `change_quota_budget` | Change a hosted quota budget. |
| `export_audit_log` | Export hosted audit records. |
| `support_access_session` | Open, use, and close a scoped provider support session. |

Guarantees:

- Every provider admin action creates a hosted audit event with actor,
  scope, action, before/after summary or redacted diff, request
  fingerprint, outcome, and timestamp.
- Provider admin actions cannot edit history events, task outcomes, or
  runtime leases outside the runtime's documented repair and admin
  surfaces.
- Support access is scoped and time-limited. A support access session
  that forwards runtime commands must leave both a hosted audit event
  and runtime command attribution.
- Credential rotation is additive during the overlap window. A rotation
  plan must preserve compatible workers until new credentials have
  heartbeated and old credentials are revoked.

## Control-Plane API Lifecycle

Hosted-control-plane APIs and manifests are versioned independently of
runtime build identity.

Guarantees:

- The hosted-control-plane contract manifest has schema
  `durable-workflow.v2.hosted-control-plane.contract` and version `1`.
- Hosted-control-plane HTTP calls use an explicit protocol version
  header: `X-Durable-Workflow-Hosted-Control-Plane-Version`.
- Additive fields, routes, budgets, admin actions, and connectivity
  modes may ship in minor releases when older consumers can ignore
  them.
- Removing or renaming a stable endpoint class, tenant hierarchy level,
  quota refusal reason, admin action, or connectivity mode is a major
  change.
- Unknown required fields fail closed. Unknown diagnostic fields are
  ignored by older consumers under the field-visibility rule from
  `Workflow\V2\Support\SurfaceStabilityContract`.
- Runtime protocol manifests remain the client compatibility authority
  for workflow commands and worker traffic. Hosted-control-plane
  version negotiation does not replace `control_plane`,
  `worker_protocol`, or `surface_stability_contract` negotiation on the
  runtime target.

## Audit And Export Boundaries

Hosted audit logs and runtime exports answer different questions:

| Boundary | Authority | Contains |
| --- | --- | --- |
| `hosted_audit_log` | Hosted control plane | Tenant hierarchy changes, hosted IAM decisions, quota decisions, provider admin actions, support sessions, runtime-target inventory changes. |
| `runtime_history_export` | Runtime target | Workflow history events, command outcomes, task and activity facts, schedules, memos, search attributes, payload metadata, repair evidence. |
| `support_bundle` | Both, with redaction | Joined diagnostic bundle containing selected hosted audit ids plus runtime export references. |

Guarantees:

- A hosted audit log never replaces runtime history. Runtime history
  remains the replay and audit authority for workflow facts.
- A runtime history export never grants hosted IAM authority. Export
  consumers still need hosted or runtime authorization to receive the
  bundle.
- Support bundles are redacted by default. Including payloads, secrets,
  or tenant-identifying hosted data requires explicit export policy.
- Runtime command attribution links hosted audit ids to runtime command
  outcomes where a hosted caller forwarded the command.

## Test Strategy Alignment

- `tests/Unit/V2/HostedControlPlaneContractTest.php` pins the
  machine-readable manifest emitted by
  `Workflow\V2\Support\HostedControlPlaneContract`.
- `tests/Unit/V2/HostedControlPlaneDocumentationTest.php` pins this
  document's headings, vocabulary, ladder, endpoint classes, quota
  reasons, connectivity modes, provider admin actions, and audit/export
  boundaries.
- Server and cloud repos that expose this manifest should add their own
  discovery tests to prove `hosted_control_plane_contract` mirrors the
  package class exactly.

## What This Contract Does Not Yet Guarantee

The following are explicitly deferred and MUST NOT be assumed:

- A generally available managed cloud product in every region.
- Self-serve private networking, provider-managed workers, or cloud
  relay worker connectivity.
- Automatic namespace migration between runtime targets.
- Active/active multi-region workflow execution.
- Automatic cross-region runtime failover.
- Provider-managed database, cache, queue, or storage SLAs beyond the
  runtime target's own published contract.
- A hosted-control-plane import path for live runtime history.

## Changing This Contract

A change to any named guarantee in this document is a protocol-level
change for the purposes of `docs/api-stability.md` and downstream SDKs.
Reviewers should treat unmotivated changes to the language above as
breaking changes and require explicit cross-SDK and server/cloud
coordination before merge.

In the same change:

- update `Workflow\V2\Support\HostedControlPlaneContract`;
- update `tests/Unit/V2/HostedControlPlaneContractTest.php`;
- update `tests/Unit/V2/HostedControlPlaneDocumentationTest.php`;
- update the docs site cloud-control-plane page and any static mirror
  that re-exports this manifest;
- update server/cloud discovery tests when a runtime or hosted surface
  publishes `hosted_control_plane_contract`.
