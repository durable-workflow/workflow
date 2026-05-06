# Workflow V2 Security and Governance Direction Contract

This document freezes the v2 security and governance direction for
command admission, operator access, audit evidence, network posture, and
trust boundaries. It is the reference cited by product docs, CLI
reasoning, Waterline diagnostics, server deployment guidance, cloud
topology, and release checks when they need to explain why a command was
allowed or denied.

The contract principle is simple: every command and operator mutation is
described as explicit facts, not only as opaque middleware behavior. A
consumer must be able to reconstruct the actor, capability, target
resource, auth outcome, durable audit record, and trust boundary from
contracted fields and named surfaces.

The guarantees below apply to the `durable-workflow/workflow` package at
v2, to embedded Laravel hosts, to the standalone server that embeds the
package, to Waterline when installed into a host app, and to hosted
control-plane products that place namespaces onto runtime targets.

## Scope

The contract covers:

- **command identity** — how command paths resolve actor or service
  identity into a compact summary that can be recorded and displayed.
- **capability vocabulary** — the explicit authorization capability
  names for workflow commands, schedule management, repair, archive, and
  lifecycle controls.
- **durable audit facts** — the fields that must survive after the
  request finishes: actor identity, auth outcome, request fingerprint,
  target scope, command outcome, and rejection reason when applicable.
- **operator auth boundary** — how embedded Waterline delegates
  authentication and authorization to the host Laravel app through
  configured middleware, guards, gates, policies, and CSRF protection.
- **identity extension points** — where SSO, SAML, SCIM, directory sync,
  service accounts, and customer identity providers attach without
  becoming a package-level requirement.
- **network posture** — the minimum posture language for webhook ingress,
  worker-to-backend traffic, operator surfaces, mTLS, private networking,
  proxy headers, and secret rotation.
- **release posture** — the data-handling, encryption, compliance,
  audit-log, and support statements public release docs must make
  honestly for each distribution shape.
- **hosted control-plane boundary** — how Cloud identity sits above
  organizations, projects, environments, and namespaces while runtime
  targets remain authoritative for execution facts.
- **codec-server boundary** — why customer-managed payload decode is a
  separate trust boundary from Durable Workflow command authorization.
- **cross-namespace service authorization** — how caller, endpoint,
  service, and operation policy axes are represented explicitly.

It does not cover:

- the cryptographic design of a future mTLS or signed-request provider.
  Those are extension points until a versioned auth-composition contract
  publishes runtime behavior.
- a bundled identity provider, SSO provider, SAML implementation, SCIM
  directory, secrets manager, WAF, service mesh, or certificate
  authority.
- compliance certification claims. Product docs may describe controls
  the software exposes, but they MUST NOT imply SOC 2, HIPAA, PCI, ISO,
  FedRAMP, or similar certification unless a separate public compliance
  program exists.
- customer application authorization. Workflow code may still perform
  business authorization inside activities or workflow logic; this
  contract governs Durable Workflow command admission and audit facts.

## Terminology

- **Actor identity** — a human, automation, service account, workflow,
  webhook sender, Cloud principal, or PHP caller summarized as durable
  command context. Actor summaries use `caller`, `principal`, and
  request metadata where available.
- **Service identity** — a non-human actor such as a scheduler,
  maintenance command, worker, Cloud service account, bridge adapter, or
  runtime target. Service identities are actors for authorization and
  audit purposes.
- **Capability** — a stable dotted permission string such as
  `workflow.signal` or `schedule.pause`. Capabilities are the contract
  vocabulary that server, Waterline, Cloud, CLI, and host gates evaluate.
- **Target resource** — the object the capability acts on: workflow
  instance, workflow run, schedule, namespace, task queue, worker,
  service endpoint, service, operation, or history export.
- **Auth outcome** — the result of admission for a command path:
  `authorized`, `denied`, `not_configured`, or `not_applicable`, plus the
  auth method that produced the outcome.
- **Request fingerprint** — a non-secret hash of method, path, selected
  correlation headers, and normalized payload. It lets operators
  correlate repeated command attempts without storing raw request bodies
  in every projection.
- **Audit record** — durable evidence written to `workflow_commands`,
  `workflow_history_events`, `workflow_schedule_history_events`,
  `workflow_service_calls`, or a hosted control-plane audit log.
- **Trust boundary** — a place where Durable Workflow changes which
  system is trusted for identity, authorization, payload decode, network
  authentication, or storage confidentiality.
- **Operator surface** — Waterline, standalone-server operator APIs, CLI
  commands, Cloud console/API, or custom host admin panels that read or
  mutate durable workflow state.

## Command Contract Facts

Every mutating command path MUST resolve to this fact set before it
commits a durable mutation:

| Fact | Required source | Durable surface |
| --- | --- | --- |
| Actor or service identity summary | `CommandContext.context.caller` and, when available, `CommandContext.context.principal` | `workflow_commands.context`, schedule `command_context`, service-call principal columns, or hosted audit log |
| Capability | Capability table in this document, derived from command type and route/action | Command row, schedule audit payload, ingress audit log, or hosted audit log |
| Target resource | Command target scope plus instance/run/schedule/namespace/service address | `workflow_commands.target_scope`, `requested_workflow_run_id`, `resolved_workflow_run_id`, schedule id, service-call address, route resource |
| Auth outcome | Auth middleware, host gate/policy, control-plane provider, or explicit `not_applicable` for in-process PHP/workflow paths | `context.auth.status`, `context.auth.method`, ingress audit log, or hosted audit log |
| Request fingerprint | `CommandContext.context.request.fingerprint` for HTTP-originated paths | Command context, schedule `command_context`, history export command metadata |
| Command outcome | Accepted, rejected, applied, completed, failed, skipped, archived, cancelled, terminated, or no-op result | `workflow_commands.status`, `workflow_commands.outcome`, `workflow_commands.rejection_reason`, schedule event payload, service-call outcome |

The package already carries most of the durable command facts through
`Workflow\V2\CommandContext`, `Workflow\V2\Models\WorkflowCommand`,
`Workflow\V2\CommandResult`, `Workflow\V2\Support\HistoryTimeline`, and
`Workflow\V2\Support\HistoryExport`. New command paths MUST extend those
surfaces rather than inventing an unrelated audit shape.

`CommandContext::phpApi()` and `CommandContext::workflow()` use
`auth.status = not_applicable` because they are already running inside
trusted application or workflow code. `CommandContext::webhook()` records
`authorized` or `not_configured` based on the configured webhook auth
method. `CommandContext::waterline()` records the Waterline source after
the host Laravel app has admitted the request. Standalone-server and Cloud
adapters may pass a richer `CommandContext` through the control-plane
contract as long as they keep the same top-level fact names.

Pre-authentication rejections may occur before a command row exists. Those
rejections MUST still be observable at the ingress boundary through
HTTP status, route name, capability, target resource when parseable, auth
outcome, and request fingerprint or request id. Once a request reaches a
Durable Workflow command boundary, accepted and rejected outcomes are
durable command facts.

## Capability Vocabulary

Capabilities are stable, product-facing permission names. Route names,
HTTP methods, artisan commands, and UI buttons may vary by surface, but
authorization decisions MUST normalize to these capability strings.

| Command path | Capability | Target resource | Durable outcome source |
| --- | --- | --- | --- |
| start workflow | `workflow.start` | workflow instance, namespace | `workflow_commands`, `StartAccepted`, `StartRejected`, `WorkflowStarted` |
| signal workflow | `workflow.signal` | workflow instance or selected run | `workflow_commands`, `workflow_signals`, signal history events |
| update workflow | `workflow.update` | workflow instance or selected run | `workflow_commands`, `workflow_updates`, update history events |
| repair workflow/task state | `workflow.repair` | workflow instance, run, task, namespace repair pass | `workflow_commands`, `RepairRequested`, repair diagnostics |
| cancel workflow | `workflow.cancel` | workflow run | `workflow_commands`, `CancelRequested`, `WorkflowCancelled` |
| terminate workflow | `workflow.terminate` | workflow run | `workflow_commands`, `TerminateRequested`, `WorkflowTerminated` |
| archive workflow/history | `workflow.archive` | closed workflow run or instance | `workflow_commands`, `ArchiveRequested`, `WorkflowArchived`, history export metadata |
| create schedule | `schedule.create` | schedule id, namespace | `workflow_schedule_history_events.ScheduleCreated` |
| pause schedule | `schedule.pause` | schedule id, namespace | `workflow_schedule_history_events.SchedulePaused` |
| resume schedule | `schedule.resume` | schedule id, namespace | `workflow_schedule_history_events.ScheduleResumed` |
| update schedule | `schedule.update` | schedule id, namespace | `workflow_schedule_history_events.ScheduleUpdated` |
| trigger schedule | `schedule.trigger` | schedule id, namespace, started run | `workflow_schedule_history_events.ScheduleTriggered` and run `ScheduleTriggered` |
| backfill schedule | `schedule.backfill` | schedule id, namespace, occurrence window | `ScheduleTriggered` or `ScheduleTriggerSkipped` audit events |
| delete schedule | `schedule.delete` | schedule id, namespace | `workflow_schedule_history_events.ScheduleDeleted` |
| manage namespace-scoped services | `service.invoke` / `service.manage` | endpoint, service, operation | `workflow_service_calls` and service catalog rows |

The capability name is the authorization unit. A role label such as
`operator` or `admin` is only a policy shortcut. For example, an
operator token may grant `workflow.signal` but not
`workflow.terminate`, and a Cloud service account may grant
`schedule.pause` only inside one environment.

## Actor Identity Resolution

Every command path resolves identity in a deterministic order:

1. **Explicit principal.** If the ingress layer or host app provides a
   principal, record its non-secret summary: subject/id, type, label,
   tenant or organization when applicable, roles/scopes, and auth
   method. Secrets, bearer tokens, session cookies, private keys, and
   raw assertions MUST NOT be stored in command context.
2. **Caller source.** If no principal exists, record the source that
   made the call: `php`, `workflow`, `webhook`, `waterline`,
   `control_plane`, `scheduler`, `worker`, `cloud`, or `service`.
3. **Service identity.** Maintenance commands, scheduler ticks,
   repair passes, Cloud sync jobs, and bridge adapters record a service
   identity summary rather than pretending to be a human operator.
4. **Unknown actor.** If a host cannot identify the caller, the command
   may still be rejected with `auth.status = denied` or recorded with
   `principal.type = unknown` only when the route is intentionally open.
   An accepted mutating operator command with no actor or service
   identity summary is out of contract.

Identity is a summary, not a full directory object. SSO, SAML, SCIM, and
directory sync systems remain extension points that feed this summary
through host middleware, custom auth providers, Cloud identity, or bridge
adapters.

## Authorization Boundaries

Authorization is evaluated at the boundary that receives the command:

- **Embedded PHP API** trusts the host application process. The actor is
  the PHP caller or workflow source. Business authorization remains the
  host application's responsibility.
- **Webhook ingress** authenticates through the configured
  `WebhookAuthenticator` implementation. Accepted signal and update
  commands record the webhook source, auth method, request fingerprint,
  target instance/run, and command outcome.
- **Standalone server** authenticates through its server auth provider
  and authorizes route access by capability and target namespace. Worker
  routes, operator routes, system routes, and namespace administration
  are separate policy surfaces even if a deployment maps them to one
  credential during migration.
- **Waterline** runs inside the embedded Laravel host and delegates
  authentication and authorization to that host app. The host's `web`
  middleware, auth guards, CSRF middleware, gates, policies, and route
  middleware decide whether an operator may reach a Waterline route.
  Product docs may call this the host Laravel application web middleware
  boundary when they need a shorter label for the same delegation point.
  Waterline must not be the only place where operator identity is known.
- **Cloud** authenticates organization/project/environment principals
  above namespaces, then maps authorized operations to runtime-target
  credentials and namespace-scoped requests. Cloud identity is not a
  replacement for the runtime target's worker protocol auth.
- **Cross-namespace service calls** authorize the caller namespace and
  caller identity against endpoint, service, and operation policy before
  handler dispatch.

An implementation may deny at any earlier boundary, but it MUST NOT
allow a mutating request to proceed while leaving the capability and
actor summary implicit.

## Durable Audit Data

Durable audit data is split by mutation family:

- **Workflow commands** use `workflow_commands` for command id,
  command type, source, context, target scope, requested run,
  resolved run, workflow type/class, status, outcome, rejection reason,
  payload codec, payload reference/blob, command sequence, message
  sequence, and timestamps. The `context` JSON carries caller,
  principal, auth, request fingerprint, and source-specific metadata.
- **Workflow history** records the semantic event stream. Command-driven
  events reference `workflow_command_id` and carry frozen payload keys in
  `HistoryEventPayloadContract`.
- **Schedule management** writes
  `workflow_schedule_history_events` with `command_context` whenever an
  external actor or service context is supplied. `ScheduleTriggered`
  also appears on the started run so that a run can trace back to the
  schedule that created it.
- **Signals and updates** keep lifecycle rows in `workflow_signals` and
  `workflow_updates` linked to the command row so Waterline, history
  export, and CLI output can show accepted, rejected, applied,
  completed, or failed state without replaying workflow code.
- **Repair and archive** record command rows and typed history events
  before or during the state transition so incident review can see who
  requested the mutation, what target scope was selected, and what the
  engine actually did.
- **Cross-namespace services** record `workflow_service_calls` before
  authorization completes, including caller identity, caller namespace,
  target namespace, endpoint, service, operation, boundary policy,
  binding kind, outcome, and failure detail.
- **Hosted control planes** keep a control-plane audit log for Cloud-only
  actions such as organization membership, API key management, runtime
  target assignment, namespace provisioning, and namespace reassignment.
  Runtime workflow facts still live on the runtime target.

Audit data may include customer workflow metadata, target names, payload
previews, error messages, and request fingerprints. Public docs and
release notes must describe this honestly as operational data rather
than implying that audit logs are free of application data.

## Operator Auth Boundary

The first Waterline/operator boundary for embedded deployments is the
host Laravel application:

- Waterline routes run under the host route stack. The host decides which
  guards, middleware, gates, policies, CSRF rules, and rate limits apply.
- The package-provided Waterline UI and API should assume the request has
  already passed host authentication, then record a Waterline
  `CommandContext` when it issues a workflow or schedule mutation.
- Host apps that use SSO, SAML, SCIM, LDAP, OIDC, internal RBAC, or
  custom service-token automation expose those identities to Waterline
  through normal Laravel request/user/gate mechanisms.
- Waterline namespace scoping is an authorization fence for durable
  workflow state. A host that configures `WATERLINE_NAMESPACE` scopes
  every list, detail, schedule, and operator-action route. A command
  targeting another namespace returns
  not-found semantics or denial instead of leaking state.

Standalone server and Cloud operator surfaces are separate boundaries.
They should expose the same capability vocabulary and audit facts, but
they do not inherit Waterline's Laravel session boundary.

## Network Posture

Network posture is part of the contract because auth facts are only
meaningful when the route carrying them is exposed deliberately.

### Webhook Ingress

Webhook ingress MUST document the public endpoint, accepted auth method,
replay/idempotency strategy, payload size limits, timeout budget, and
where request fingerprints appear. Production webhook endpoints should
use TLS, narrow source allow lists where feasible, signed requests or
rotatable shared secrets, and explicit proxy-header trust configuration.
`X-Forwarded-*` and similar proxy headers are trusted only when the host
has configured trusted proxies; otherwise the direct peer address is the
network fact.

### Worker-To-Backend

Worker-to-backend traffic includes worker registration, long-poll,
heartbeat, complete, fail, and external payload access. Production
deployments should use TLS with verification enabled, role-scoped worker
credentials, namespace headers, secret rotation, and private networking
or mTLS when workers cross a network that is not fully controlled by the
operator. Worker tokens must not grant operator capabilities unless a
deployment is in an explicitly documented migration mode.

### Operator Surfaces

Operator surfaces include Waterline, standalone-server operator APIs,
CLI automation, Cloud console/API, and custom admin panels. They should
run behind authenticated sessions or role-scoped service credentials,
CSRF protection for browser sessions, rate limits for mutating commands,
and network placement appropriate to the environment. Internet-facing
operator surfaces should document TLS termination, trusted proxy
headers, private networking or VPN assumptions, mTLS if used, and how
operator credentials rotate.

### Secret Rotation

Credential rotation must preserve audit clarity. During rotation,
new and old credentials may both be accepted, but command context should
still identify the auth method and principal summary. Logs, diagnostics,
cluster-info manifests, history exports, command context, and Waterline
responses MUST redact raw secret material.

## Release And Support Posture

Public release docs for each distribution shape MUST state the security
posture in plain terms:

- **Data-handling posture.** Workflow arguments, results, history,
  payload references, search attributes, memos, command context, audit
  rows, exception messages, and operator notes can contain customer
  application data. Search attributes and labels are operator-visible
  metadata and must not be used for secrets.
- **Encryption posture.** Durable Workflow expects TLS for network
  transport in production. The at-rest encryption boundary is provided by the
  operator's database, object storage, filesystem, queue, cache, secret
  manager, or hosted control-plane provider. The workflow package does
  not automatically encrypt every application payload field.
- **Compliance posture.** The open-source package and self-hosted
  server provide controls and audit surfaces, not a compliance
  certification by themselves. Compliance claims belong to the operator
  or to a separately documented hosted offering.
- **Audit-log posture.** Audit records are durable operational evidence
  with request fingerprints and actor summaries. They are not a complete
  SIEM, DLP system, legal hold system, or immutable external ledger
  unless a deployment adds those components.
- **Support posture.** Self-serve paths document what is supported
  directly. Advanced identity, private networking, mTLS rollout, custom
  policy engines, provider-specific compliance, and bespoke topology
  reviews are support-led unless public docs say otherwise.

Release notes MUST NOT claim stronger security, encryption, compliance,
audit retention, support, or hosted identity guarantees than the shipped
software and docs actually provide.

## Cloud Hosted Identity Boundary

Cloud identity sits above namespaces:

```text
Cloud organization
  project
    environment
      namespace  ---> runtime target
```

Cloud owns hosted users, service accounts, organization membership,
project/environment roles, API keys, namespace provisioning, namespace
assignment, runtime-target inventory, and Cloud audit logs. A Cloud
principal is the hosted actor summary for this boundary; each Cloud
principal may be authorized to administer one namespace while having no
rights to another namespace in the same organization.

The runtime target remains authoritative for workflow execution facts:
worker registration, worker polling, workflow starts, signals, updates,
cancels, terminates, schedules, history, visibility, task queues, and
payload decode. Cloud may cache or summarize runtime facts, but it does
not become the workflow history authority unless a future contract
explicitly moves that boundary.

When Cloud forwards or initiates a runtime command, it must preserve an
actor or service identity summary, the capability, target namespace,
auth outcome, request fingerprint or Cloud audit id, and runtime command
outcome.

## Codec-Server Trust Boundary

Payload decode is separate from command authorization. A codec server or
custom decoder can see plaintext application payloads after decode, even
when Durable Workflow stores only encoded blobs or references. Treat that
decoder as a customer-managed trust boundary:

- Customers choose where the decoder runs, which network can reach it,
  which keys it can access, and what audit logs it emits.
- Durable Workflow may store codec names, payload references, hashes,
  sizes, schema fingerprints, and bounded previews, but it must not claim
  those facts are equivalent to end-to-end encryption.
- Operator surfaces should decode only through configured codec
  boundaries and should support redaction for history export and
  diagnostics.
- Hosted products must not silently proxy customer payloads through an
  undocumented decode service. If hosted decode exists, it needs a
  separate public trust-boundary and data-handling statement.

## Cross-Namespace Service Authorization

Cross-namespace service calls use the service boundary frozen in
`docs/architecture/cross-namespace-service-policy.md`. Authorization
must cover these axes explicitly:

- **caller versus endpoint** — whether the caller namespace and caller
  identity may reach the endpoint.
- **service policy** — whether the caller may reach the service under
  that endpoint and which service-level rate, concurrency, or circuit
  limits apply.
- **operation policy** — whether the caller may invoke the specific
  operation and which handler binding is eligible after policy passes.

The boundary records caller identity before authorization so
`rejected_forbidden`, `rejected_not_found`, throttling, concurrency, and
circuit-open outcomes remain auditable. Handler code does not replace
boundary authorization; it runs only after endpoint, service, and
operation policy admits the call.

## Documentation Alignment

The following public docs must stay aligned with this contract:

- Waterline operator docs describe the host Laravel middleware, guard,
  gate, policy, and CSRF boundary.
- Server auth docs describe role-scoped credentials, custom auth
  providers, capability-to-route mapping, auth outcome, and redaction.
- Deployment docs describe webhook ingress, worker-to-backend traffic,
  operator surfaces, trusted proxy headers, TLS/mTLS/private networking,
  and secret rotation.
- Release and support docs describe data handling, encryption,
  compliance, audit-log, and support posture without overclaiming.
- Cloud docs describe hosted identity above namespaces and runtime
  targets as the data-plane authority.
- Codec and payload docs describe customer-managed decode as its own
  trust boundary.
- Cross-namespace service docs describe caller, endpoint, service, and
  operation authorization as distinct policy axes.

## Test Strategy Alignment

Contract tests should pin:

- this document's headings, terms, capability vocabulary, audit facts,
  and trust-boundary language.
- command context persistence on workflow commands and schedule history
  events.
- history export and Waterline detail exposure of actor, auth outcome,
  request fingerprint, target scope, and command outcome.
- service-boundary policy outcomes for caller-versus-endpoint, service,
  and operation denial.
- release-doc checks that prevent stronger encryption, compliance,
  support, or hosted identity claims from drifting ahead of shipped
  behavior.

## Changing This Contract

Adding a capability, changing an auth outcome meaning, removing an audit
fact, changing where identity is delegated, or moving a trust boundary is
a protocol-level change. Update this document, adjacent product docs,
machine-readable manifests when applicable, and the tests that pin them
in the same change.
