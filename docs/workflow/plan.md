# Workflow V2 Feature Mapping and Compatibility Contract

This document is the formal v1 -> v2 feature mapping for Durable
Workflow. It answers where each current product feature lives in the v2
durable kernel, which history events make it replayable, which command
outcomes expose user-visible decisions, which projection or visibility
surface reads it, and which support boundary applies in 2.0.

The clarity rule is simple: every feature must have an inspectable
durable home. If a user asks where truth lives, the answer must name a
table, a typed history event, a command outcome, or a projection surface
instead of hidden worker memory.

## Scope

This contract covers:

- the v1 PHP workflow package surface that existing Laravel users know;
- the v2 PHP authoring API in `Workflow\V2`;
- the standalone server and worker protocol where they consume the
  workflow package's durable kernel;
- Waterline, CLI, and SDK projection surfaces that explain a run;
- first-release v2.0 defaults and the explicit non-goals that are not
  parity gaps.

The source-of-truth stability rules for public APIs and frozen history
event payloads remain in [`docs/api-stability.md`](../api-stability.md).
This document maps features to those surfaces; it does not replace the
event payload table.

## Status

V2 covers the current v1 product surface. This contract is complete when
it stays present, names each mapped feature row below, and keeps the gap
analysis explicit.

## Managed Cloud Readiness

Package, standalone server, and managed cloud are one product ladder.
The package owns the durable kernel and the namespace-scoped runtime
contract. The standalone server exposes that kernel over HTTP with the
same worker protocol, control-plane commands, matching, history, and
operator projections. Managed cloud adds hosted tenancy, IAM, regional
placement, private connectivity, quota, metering, and provider
operations without replacing the durable homes or changing the worker
semantics named in this document.

The readiness contracts are:

- **Tenancy hierarchy.** Hosted tenancy is
  `organization -> project -> environment -> namespace`. The package
  persists and routes by namespace. The standalone server authenticates
  namespace-scoped API and worker requests. Managed cloud maps
  organizations, projects, and environments onto namespaces and carries
  the higher-level scope as principal, policy, billing, and audit
  metadata rather than as hidden workflow state.
- **Control plane and data plane.** The hosted control plane owns
  organization, project, environment, namespace, IAM, endpoint, region,
  quota, billing, and lifecycle mutations. The namespace data plane owns
  worker protocol, task matching, command execution, history,
  visibility, and operator projections for a namespace. The control
  plane may create durable commands and read projections through the
  documented API, but it MUST NOT run workflow or activity code.
- **Hosted IAM and machine identity.** Organization users, service
  accounts, API keys, SSO, and SCIM are hosted IAM concepts. They map
  to stable principal subjects, scopes, roles, and audit facts before a
  request reaches the workflow control plane. Machine identities can be
  scoped at organization, project, environment, or namespace level and
  MUST NOT bypass namespace authorization.
- **Reachability boundaries.** Control-plane APIs are reachable through
  hosted regional or global control endpoints. Namespace data-plane
  APIs are reachable only through the namespace endpoint, the selected
  regional endpoint, or explicitly configured private connectivity.
  Worker polling, completion, heartbeat, and task delivery use
  data-plane reachability; hosted control-plane rate limits do not
  decide worker dispatch eligibility.
- **Namespace connectivity rules.** A namespace connectivity rule is an
  allow-list policy for the namespace's accepted ingress paths,
  private endpoints, worker-connectivity modes, certificate filters,
  and approved egress targets. Rejections fail closed before revealing
  whether a hidden namespace, service, queue, or endpoint exists.
- **Namespace endpoint versus regional endpoint behavior.** A namespace
  endpoint selects exactly one namespace from the endpoint hostname and
  SNI value, then applies the namespace's mTLS and certificate-filter
  policy before request authorization. A regional endpoint may route to
  more than one namespace in the region only after the request
  authenticates and names an allowed namespace. mTLS client
  certificates are accepted only when their subject, issuer, SAN, or
  fingerprint matches the namespace's configured certificate filters.
- **Quota, fairness, and metering.** Quotas may attach at
  organization, project, environment, and namespace levels; the
  effective limit is the most specific applicable limit after inherited
  ceilings are applied. Fairness is enforced at the namespace and task
  queue boundaries named by the matching and admission contracts.
  Metering records starts, tasks, activity attempts, worker minutes,
  history bytes, payload storage, API calls, and retained data against
  the same hierarchy so billing never depends on scanning user
  payloads.
- **APS/OPS throttling versus API rate limits.** APS/OPS throttles are
  worker-plane dispatch and operations budgets: active leases,
  dispatches per minute, repair passes, query-task admission, and
  operator action concurrency. Control-plane API rate limits protect
  hosted API ingress. A control-plane `429` MUST NOT consume worker
  dispatch capacity, and an APS/OPS throttle MUST surface as namespace
  backpressure rather than as a generic API quota failure.
- **Region, residency, replication, backup, restore, and DR.** An
  environment declares its allowed regions and residency boundary. A
  namespace selects a primary region, optional replica regions, backup
  policy, restore policy, and disaster-recovery objective inside that
  boundary. Workflow history, payload references, backups, and
  metering data MUST NOT replicate outside the declared residency
  boundary unless the operator changes the environment policy first.
- **Private connectivity and HA/DR networking.** Private connectivity
  is namespace or environment scoped and does not alter namespace
  identity, SNI routing, mTLS requirements, or authorization. HA and DR
  networking declare which endpoints can fail over, which private
  routes must exist in each region, and which DNS or control-plane
  records change during failover. A failover cannot silently widen
  reachability.
- **Worker connectivity modes.** Supported modes are hosted workers,
  customer-managed workers over public TLS, private-link workers, and
  outbound tunnel or agent-based workers. Every mode registers the
  same worker identity, namespace, task queue, compatibility,
  heartbeat, and build information. No worker-connectivity mode grants
  direct database access or an undocumented task-claim path.
- **Provider-side support and admin plane.** The provider admin plane
  may inspect health, topology, quota, metering, audit, and support
  bundle metadata needed to operate the service. Payloads, history
  bodies, and external payload objects remain customer data; provider
  access requires an explicit support grant or documented emergency
  procedure and is audited with the acting provider principal.
- **Hosted control-plane API lifecycle.** Hosted control-plane APIs are
  versioned independently from package internals but must preserve the
  command outcomes, durable homes, and projection fields named by this
  contract. Backwards-compatible additions may appear in the current
  API version; removals, renames, narrower enum sets, or changed
  meanings require a new API version and a published deprecation
  window.
- **Namespace lifecycle controls.** Namespace create, suspend, resume,
  archive, restore, and delete are control-plane lifecycle operations.
  Deletion protection blocks destructive operations until explicitly
  disabled. Tags are non-authoritative metadata for search, billing,
  and policy selection. Certificate filters are namespace lifecycle
  state and are evaluated before mTLS-authenticated worker or API
  requests enter the data plane. Destructive deletion requires the
  namespace to be drained, retention/export policy to be satisfied,
  private connectivity to be detached or reassigned, and active
  support grants to be closed.

## Feature Compatibility Matrix

| Feature | v1 surface | v2 durable home | v2 history events | command outcomes | visibility / projection surface | support boundary |
| --- | --- | --- | --- | --- | --- | --- |
| Workflow start and identity | `WorkflowStub::make()->start()`, `workflows` row id, class name | `workflow_instances`, `workflow_runs`, `workflow_commands`, typed workflow type registry | `StartAccepted`, `StartRejected`, `WorkflowStarted` | `start_accepted`, `rejected_duplicate`, `rejected_invalid_arguments`, `rejected_not_started` as command outcomes | `RunListItemView`, `RunDetailView`, `RunCommandContract`, Waterline selected-run detail | Parity plus durable instance/run split. Public commands target instance ids by default; run ids remain explicit diagnostics and worker execution ids. |
| Workflow status and output | `workflows.status`, stored output, exception rows | `workflow_runs.status`, `workflow_failures`, `workflow_history_events`, `workflow_run_summaries` | `WorkflowCompleted`, `WorkflowFailed`, `WorkflowCancelled`, `WorkflowTerminated`, `WorkflowTimedOut`, `FailureHandled` | Terminal operator commands record their accepted/rejected command row before the terminal event | `RunDetailView`, `HistoryTimeline`, `FailureSnapshots`, `RunSummaryProjector`, Waterline run list/detail | Parity plus typed terminal causes. Mutable summary rows are projections; history events remain the replay authority. |
| Activities | `ActivityStub`, queued activity jobs, `workflow_logs` activity entries | `activity_executions`, `activity_attempts`, `workflow_tasks`, `workflow_history_events` | `ActivityScheduled`, `ActivityStarted`, `ActivityCompleted`, `ActivityFailed`, `ActivityCancelled`, `ActivityTimedOut`, `ActivityRetryScheduled`, `ActivityHeartbeatRecorded` | Activity scheduling is a workflow-task command; cancellation/repair is an operator command when externally requested | `RunActivityView`, `ActivitySnapshot`, `ActivityAttemptSnapshots`, `RunTaskView`, operator metrics activity keys | Parity. Every normal activity is a durable queued activity. Local activities are not inferred from short runtime or same-process execution. |
| Activity retries | v1 retry middleware/options and failed activity retry behavior | `activity_executions.retry_policy`, `activity_attempts`, retry workflow tasks | `ActivityRetryScheduled`, later `ActivityStarted`/terminal activity event | Retry is an engine decision recorded on history, not a hidden queue redelivery | `RunActivityView`, `RunTaskView`, `OperatorMetrics.activities.retrying` | Parity with safer defaults. V2 workflows default to no retry unless an explicit policy is set. |
| Activity heartbeats | Activity `heartbeat()` progress callbacks | `activity_attempts.last_heartbeat_at`, `activity_attempts.heartbeat_details`, lease renewal state | `ActivityHeartbeatRecorded`, `ActivityTimedOut` for heartbeat timeout | Heartbeat is worker-plane progress, not a workflow command | `RunActivityView`, `ActivityAttemptSnapshots`, activity timeout health and repair surfaces | Parity plus explicit attempt lease renewal. Heartbeat progress never lives only in process memory. |
| Timers and sleep | `Timer`, `workflow_timers`, delayed queue wakeups | `workflow_run_timers`, `workflow_run_timer_entries`, timer `workflow_tasks`, one logical `timer_id` per wait | `TimerScheduled`, `TimerFired`, `TimerCancelled` | Timer fire is scheduler/execution-plane state; external cancellation is an operator command when exposed | `RunTimerView`, `RunWaitView`, `HistoryTimeline`, scheduler health | Parity. Legacy `workflow_timers` remains v1 drain data; v2 run timers are the durable home for new runs. |
| Condition waits | Wait-until callbacks and signal/timer combinations | `workflow_run_waits`, `workflow_run_timers`, history event sequence | `ConditionWaitOpened`, `ConditionWaitSatisfied`, `ConditionWaitTimedOut`, timer events when a timeout exists | None for pure condition waits; signal/update commands may satisfy the predicate | `RunWaitView`, `ConditionWaits`, Waterline wait block | Parity plus declared wait identity. Predicate truth is reconstructed from committed history. |
| Signals | `#[SignalMethod]`, `workflow_signals`, external signal calls | `workflow_commands`, `workflow_signal_records`, `workflow_messages` sequence reservation, workflow task payload | `SignalReceived`, `SignalApplied`, `SignalWaitOpened`, `MessageCursorAdvanced` | `signal_received`, `rejected_unknown_signal`, `rejected_invalid_arguments`, `rejected_not_active`, `rejected_not_started` | `RunSignalView`, `RunTaskView`, `RunCommandContract`, Waterline signal and command panels | Parity with v2 command validation. Signals are instance-targeted by default and survive continue-as-new through ordered message sequencing. |
| Queries | Query methods reading workflow state | Replay-safe reads from committed `workflow_history_events`; no durable command row | No new event. Query replay consumes existing activity, timer, signal, update, side-effect, version, memo, search, and child events | Query validation failures are request errors, not durable command outcomes | `QueryStateReplayer`, `QueryResponse`, `RunDetailView`, CLI `dw run describe`, Waterline live-debug | Parity with stricter guardrails. Queries must not schedule activities, timers, children, signals, updates, memos, or search-attribute mutations. |
| Updates | Not a v1 primitive; commonly modeled as signal plus query/polling | `workflow_updates`, `workflow_commands`, workflow task payload, optional linked service calls | `UpdateAccepted`, `UpdateRejected`, `UpdateApplied`, `UpdateCompleted` | `update_accepted`, `rejected_unknown_update`, `rejected_invalid_arguments`, `rejected_not_active`, completed/failed lifecycle states | `RunUpdateView`, `UpdateWaits`, `RunCommandContract`, Waterline update panel | New in v2. Exposes both wait-for-accepted and wait-for-completed modes. Validators run before the handler mutates replay state. |
| Namespace service catalog and boundary policy | App-owned route tables, auth middleware, service registries, or direct database lookups | `workflow_service_endpoints`, `workflow_services`, `workflow_service_operations`, effective `boundary_policy` snapshots, `ServiceBoundaryPolicy` | No dedicated workflow history event. The catalog rows and service-call audit rows are the durable policy authority. | `accepted`, `rejected_not_found`, `rejected_forbidden`, `rejected_throttled`, `rejected_concurrency_limited`, `rejected_circuit_open` as `ServiceCallOutcome` values | `ServiceCatalog`, `ServiceEndpointView`, `ServiceView`, `ServiceOperationView`, `ServiceBoundaryAuditRecorder`, Waterline namespace-scoped service catalog | New in v2. Authorization, rate limits, concurrency limits, and circuit-breaks are evaluated before handler dispatch and recorded as durable audit facts. |
| Cross-namespace service calls | App-defined HTTP calls, queues, or child workflow conventions | `workflow_service_calls`, `resolved_binding_kind`, linked workflow run/update/signal/query/activity/carrier references, payload reference columns | Linked workflow, update, signal, query, activity, child, or message history records the handler-side work. The service-call row is the lifecycle and outcome authority. | `accepted`, `completed`, `cancelled`, `timed_out`, `degraded`, `handler_failed`, and boundary rejection outcomes | `ServiceCallView`, `DefaultServiceControlPlane`, `RunDetailView`, service-call Waterline panels, CLI service-call diagnostics | New in v2. Every cross-namespace invocation has a durable service-call id; raw transport logs are diagnostics only. |
| Child workflows | `ChildWorkflowStub`, parent/child workflow rows | `workflow_child_calls`, `workflow_links`, child `workflow_runs`, parent and child history | `ChildWorkflowScheduled`, `ChildRunStarted`, `ChildRunCompleted`, `ChildRunFailed`, `ChildRunCancelled`, `ChildRunTerminated`, `ParentClosePolicyApplied`, `ParentClosePolicyFailed` | Child start is a workflow-task command; parent close policies may emit operator-visible outcomes | `ChildRunHistory`, `RunLineageView`, `RunWaitView`, `HistoryTimeline`, Waterline lineage | Parity plus durable `child_call_id`. The parent history event is authoritative for parent resume; links are projections or lookup aids. |
| Continue-as-new | `continueAsNew` and continued workflow status | New `workflow_runs` row under same `workflow_instance_id`, lineage links in `workflow_run_lineage_entries` and `workflow_links` | `WorkflowContinuedAsNew`, next run `WorkflowStarted`, inherited memo/search/message cursor events as applicable | External instance commands resolve to active run after the transition; run-targeted commands do not retarget | `RunLineageView`, `RunDetailView`, `MessageStreamCursor`, Waterline lineage and selected-run detail | Parity plus explicit lineage. Active v1 runs finish on v1; v2 does not import them into a new v2 run mid-flight. |
| Side effects | `sideEffect()` stored in workflow logs | `workflow_history_events` only | `SideEffectRecorded` | None. A side effect is a workflow step, not an external command | `WorkflowStepHistory`, `QueryStateReplayer`, `HistoryTimeline` | Parity. Side effects are for replay-safe snapshots that do not need retry semantics; external calls remain activities. |
| Versioning and patches | `getVersion()`, `patched()`, `deprecatePatch()` | `workflow_history_events`, workflow definition fingerprint on run start | `VersionMarkerRecorded`, `WorkflowStarted` fingerprint fields | None | `VersionResolver`, `WorkflowDefinitionFingerprint`, Waterline timeline/detail version marker fields | Parity plus fingerprint fallback for pre-marker runs. Compatibility markers still govern mixed-fleet routing. |
| Sagas and compensation | `addCompensation()`, `compensate()`, compensation flags | Workflow history for the compensating activity/child/timer steps and workflow failure state | Normal activity, child, timer, and failure events; no separate hidden saga log | None unless compensation is triggered by an external cancel/terminate command | `WorkflowExecutor`, `ParallelFailureSelector`, `HistoryTimeline` | Parity. Compensation closures are replayed through normal v2 steps; they do not get a separate uninspectable store. |
| Parallel coordination | `async`, `all`, child/activity fan-out | `workflow_history_events` with `parallel_group_id`, `parallel_group_path`, child/activity durable rows | Activity and child scheduled/terminal events with parallel group metadata | None for pure workflow coordination | `ParallelChildGroup`, `ParallelFailureSelector`, `WorkflowStepHistory`, Waterline timeline | Parity plus deterministic barrier metadata. Closure-based `async(...)` remains app-local authoring sugar, not a cross-process closure transport. |
| Cancellation and termination | Cancel/terminate operations, exception statuses | `workflow_commands`, `workflow_runs`, `workflow_failures`, activity/child cancellation state | `CancelRequested`, `WorkflowCancelled`, `TerminateRequested`, `WorkflowTerminated`, activity and child cancel/terminate events | Accepted/rejected cancel and terminate command outcomes | `RunCommandContract`, `FailureSnapshots`, `ChildRunHistory`, Waterline command/failure panels | Parity plus typed operator commands. Terminate is immediate; cancel is cooperative where activities and children can observe it. |
| Schedules | v1 schedule helpers or app scheduler patterns | `workflow_schedules`, `workflow_schedule_history_events`, durable start `workflow_commands`, timers/tasks for fire evaluation | `ScheduleCreated`, `SchedulePaused`, `ScheduleResumed`, `ScheduleUpdated`, `ScheduleTriggered`, `ScheduleDeleted`, `ScheduleTriggerSkipped`, run `StartAccepted`/`WorkflowStarted` | Schedule CRUD and trigger outcomes are typed schedule/control-plane results | `ScheduleManager`, `OperatorMetrics.schedules`, Waterline schedule registry/history, CLI schedule history | Parity/new first-class surface. Schedule truth is the schedule row plus its audit stream; triggered runs record normal workflow history. |
| Search attributes | Not a first-class v1 indexed metadata contract | `workflow_search_attributes`, projected visibility filters, run summary denormalization | `SearchAttributesUpserted`, `WorkflowStarted` initial attributes | Upsert is a workflow-task command; invalid size/count/type fails closed | `VisibilityFilters`, `RunListItemView`, `RunDetailView`, Waterline list filters | New in v2. Indexed search attributes and non-indexed memos are separate first-release contracts. |
| Memo | v1 ad hoc metadata or output fields | `workflow_memos`, run detail projection | `MemoUpserted`, `WorkflowStarted` initial memo | Upsert is a workflow-task command; invalid size/count fails closed | `RunDetailView`, `MemoUpsertService`, Waterline selected-run detail | New in v2. Memo is returned-only metadata and is not a filterable search surface. |
| Inbox/outbox message streams | Usually modeled with signals, database rows, or app queues | One `workflow_messages` model with `direction`, stream keys, message cursor, and payload references | `MessageCursorAdvanced`; signals/updates also reserve the instance message sequence | Message send/receive is workflow authoring surface; signal/update commands reserve command outcomes separately | `MessageService`, `MessageStream`, history export, Waterline message diagnostics as exposed | New in v2. Repeated human-input and workflow-to-workflow streams survive continue-as-new without in-memory counters. |
| External payload storage | v1 serializer payloads in workflow rows/logs | Payload envelopes, optional `ExternalPayloadReference`, configured external payload storage driver | History events carry payload envelopes or references; bundle integrity verifies referenced payloads | Invalid payload references fail decode/command processing closed | `PayloadEnvelopeResolver`, `HistoryExport`, `BundleIntegrityVerifier`, replay verification | New in v2. External payload storage is an opt-in size/transport mechanism, not a replacement for typed history. |
| Webhooks and external ingress | App-defined routes and event listeners | `workflow_commands`, command context, typed webhook/control-plane handlers | Command request events and terminal workflow/update/signal events as applicable | Accepted/rejected command outcomes are durable and inspectable | Webhook contract docs, `RunCommandContract`, command projections | Parity/new formal ingress. Operator actions are typed engine commands from first release. |
| Replay debug and history export | v1 logs and manual replay debugging | Versioned history-export bundles from `workflow_history_events` and related projections | All frozen history events plus export metadata | Replay verification reports mismatch, decode, and integrity outcomes; it does not mutate workflow state | `HistoryExport`, `WorkflowReplayer`, `ReplayDiff`, `BundleIntegrityVerifier`, `workflow:v2:history-export`, `workflow:v2:replay-verify` | New in v2. Bundles are inspectable, versioned, and suitable for cross-SDK replay tooling. |
| Embedded v2 history import | Not applicable to v1; embedded v2 deployments formerly stayed embedded unless migrated manually | History-export bundles copied into v2 durable rows, `workflow_runs.import_source`, `workflow_runs.import_id`, `workflow_runs.import_dedupe_key`, `workflow_runs.import_contract_version`, `workflow_runs.imported_at` | Imported `workflow_history_events` remain the replay authority; projections are rebuilt from imported durable rows | `workflow:v2:history-import` report statuses such as `imported`, `already_imported`, `dry_run`, or validation findings | `EmbeddedV2HistoryImport`, `EmbeddedV2ImportContract`, `RunDetailView` import fields, `RunSummaryProjector.engine_source`, server import endpoint | New in v2. Imports embedded v2 history into a standalone server; v1 history import remains out of scope and active v1 runs still finish on v1. |
| Waterline observability | Waterline v1 reads legacy workflow rows/logs | Dedicated v2 projections: `workflow_run_summaries`, waits, timers, timeline, lineage, failures, commands, typed metadata | Projection rebuilds consume frozen history events | Operator actions flow through typed commands; Waterline must not invent engine state | `WaterlineEngineSource`, `RunDetailView`, `OperatorMetrics`, `OperatorQueueVisibility` | Parity plus dedicated v2 projections. Projections may be rebuilt; history remains source of truth. |
| Worker compatibility and routing | Queue name and deployment convention | Compatibility marker on runs/tasks, `worker_compatibility_heartbeats`, routing snapshots | `WorkflowStarted` captures definition fingerprint and routing-related fields; task history records compatible work | Claim rejection exposes compatibility mismatch as worker-plane outcome | `WorkerCompatibility`, `WorkerCompatibilityFleet`, `OperatorQueueVisibility`, Waterline worker/fleet panels | New in v2. Active v1 runs finish on v1; v2 runs stay on compatible workers through retries, children, and continue-as-new. |
| Worker deployments and rollout controls | Build-id and queue drain conventions | `workflow_worker_build_id_rollouts`, `WorkerDeployment`, `DeploymentLifecycleState`, `WorkflowCompatibilityPolicy`, `DeploymentBlockage` | No workflow replay event. Deployment lifecycle is operator/control-plane state that gates fresh work and claim eligibility. | Promote, drain, resume, and rollback return typed blockage reasons such as `no_compatible_workers`, `missing_worker_heartbeat`, `fingerprint_mismatch`, `replay_safety_failed`, and `fleet_is_draining` | `DeploymentLifecyclePlan`, `WorkerDeployment`, `OperatorQueueVisibility`, Waterline deployment panels, server deployment API | New in v2. Deployments are first-class operator objects with pinned/auto-upgrade compatibility policy instead of inferred worker-count state. |
| Sticky execution | Not a first-class v1 contract | `workflow_runs.sticky_worker_id`, `workflow_runs.sticky_until`, `workflow_tasks.sticky_worker_id`, `workflow_tasks.sticky_until`, `workflow_tasks.sticky_replay_mode`, `workflow_tasks.sticky_claimed_at` | No new replay event. Sticky state is routing and diagnostic metadata; durable history remains the correctness authority. | Worker claim diagnostics use `sticky_hit_expected`, `cold_replay`, and `forced_cold_replay` replay modes | `StickyExecution`, `WorkflowTaskBridge`, `OperatorMetrics.sticky_execution`, worker protocol manifest, Waterline worker/task diagnostics | New in v2. Sticky execution is a replay optimization with mandatory cold-replay fallback; workflow code must remain replay-safe without process-local state. |
| Task matching and long-poll coordination | Laravel queue dispatch and polling | `workflow_tasks`, activity task leases, optional long-poll wake acceleration | Task lifecycle is reflected by workflow/activity history events and task outcome rows | Worker poll/claim/complete/fail outcomes are typed worker protocol responses | `MatchingRole`, `TaskDispatcher`, `OperatorQueueVisibility`, worker protocol fixtures | New formal contract. Cache wakeups accelerate discovery but never become the correctness boundary. |
| Backend capabilities and guardrails | Best-effort runtime assumptions | `BackendCapabilities`, `TaskBackendCapabilities`, health checks, structural limit config | Structural limit failures are recorded as typed failure/history where they affect a run | Doctor/readiness failures block or warn before accepting unsafe work | `HealthCheck`, `ReadinessContract`, `workflow:v2:doctor`, Waterline health surfaces | New in v2. Supported database, queue, cache, serializer, and migration combinations are validated early. |
| Retention and pruning | `workflow:prune`, deleted legacy rows | Retention cleanup across v2 run, history, activity, task, metadata, and projection rows | Archive/delete requests are command/history visible where they affect operator intent | Archive/delete operator outcomes are typed | `WorkflowRunRetentionCleanup`, `HistoryExport`, Waterline archive/export projections | Parity plus safer export-before-delete expectations. Retention must not silently remove active truth. |

## New-In-V2 Capabilities

The following capabilities have no direct v1 equivalent and are part of
the first v2 product surface:

| Capability | Durable home | Why it is not a v1 gap |
| --- | --- | --- |
| Typed history event wire formats | `workflow_history_events`, `HistoryEventPayloadContract`, `docs/api-stability.md` | V1 logs were useful but not a frozen cross-SDK replay protocol. |
| Durable identities | `workflow_instances`, `workflow_runs`, `workflow_run_lineage_entries` | V1 conflated logical workflow identity with execution row identity. |
| Validator-aware updates | `workflow_updates`, `workflow_commands`, update history events | V1 users composed signals and queries manually. |
| Search attributes | `workflow_search_attributes` | V1 had no typed indexed metadata contract. |
| Memo | `workflow_memos` | V1 had no typed returned-only metadata contract. |
| Message streams | `workflow_messages` and cursors | V1 repeated input flows were app-level patterns. |
| Namespace service catalog and boundary policy | `workflow_service_endpoints`, `workflow_services`, `workflow_service_operations`, `workflow_service_calls` | V1 cross-namespace calls were app-level integration code without one durable policy and audit contract. |
| Cross-namespace service calls | `workflow_service_calls` plus linked target references | V1 had no durable service-call id, lifecycle enum, outcome taxonomy, or namespace-scoped operator surface. |
| Worker compatibility | compatibility marker fields and `worker_compatibility_heartbeats` | V1 mixed-build safety depended mostly on queue/deployment discipline. |
| Worker deployments | `workflow_worker_build_id_rollouts`, `WorkerDeployment`, deployment blockage records | V1 worker rollout state was inferred from queue/build-id conventions instead of one typed lifecycle. |
| Sticky execution | `workflow_runs` and `workflow_tasks` sticky affinity fields | V1 did not freeze process-local replay caching as a supported optimization with cold-replay fallback. |
| Standalone server distribution | server HTTP API, worker protocol, cluster manifests | V1 was PHP/Laravel embedded only. |
| Embedded v2 history import | history-export bundles plus workflow run import marker columns | V1 migration remains finish-on-v1; this moves already-v2 embedded history into the standalone server. |
| Compiled workflow IR and Serverless Workflow import | `CompiledWorkflowDefinition`, `ServerlessWorkflowCompiler`, `WorkflowDefinitionVersionSelector` | Builder-authored and Serverless Workflow SDK JSON definitions compile into one schema-pinned IR with stable step ids before a concrete definition version is selected for runtime or storage. |
| Platform protocol specs | `PlatformProtocolSpecs`, `GET /api/cluster/info` `platform_protocol_specs` mirror | V1 did not publish one machine-readable catalog of normative server, worker, CLI, Waterline, and SDK specs. |
| Platform conformance | fixture catalog and harness result contract | V1 did not publish a single cross-repo compatibility proof. |
| Replay-debug bundles | `HistoryExport`, `WorkflowReplayer`, replay diff tooling | V1 relied on manual log inspection. |

## V2.0 Defaults

These defaults are load-bearing contract choices for the first v2.0
release:

- Queries replay from committed history and never mutate durable state.
- External commands default to instance-targeted active-run resolution.
- Updates expose both wait-for-accepted and wait-for-completed modes.
- Active v1 runs finish on v1 instead of being rewritten into v2 runs.
- Async closure transport is deferred from the correctness core; named
  workflow/activity/child types are the portable boundary.
- Workflow-mode guardrails ship early and fail closed when authoring code
  tries to use non-replay-safe primitives.
- Indexed search attributes and non-indexed memo are separate
  first-release contracts.
- Workflows default to no retry unless an explicit retry policy is set.
- Supported backend combinations are validated early by doctor/readiness
  checks.
- Operator actions are typed engine commands from first release.
- Cross-namespace service calls always write a durable service-call row
  before handler dispatch or rejection.
- Sticky execution is a replay optimization; cold replay remains the
  correctness fallback.
- Reset is reserved as a later-phase command.
- Local activities and worker sessions are deferred or opt-in only after
  they receive their own explicit runtime contracts.

## Gap Analysis

| Item | Classification | Decision |
| --- | --- | --- |
| Current v1 workflow authoring APIs | Ported | V2 provides workflow classes, activities, timers, signals, child workflows, side effects, versioning, sagas, parallel coordination, continue-as-new, status, output, cancellation, and pruning with typed durable homes. |
| Current v1 runtime observability | Ported and expanded | V2 replaces legacy logs with typed history, summaries, timeline, waits, timers, lineage, command, failure, activity, and metadata projections. |
| Active v1 runs | Supported by finish-on-v1 | Existing v1 runs are not rewritten in place. They continue on the v1 compatibility path while new starts use v2. This is a deliberate support boundary, not an unported feature. |
| Local activities | Deferred / no v2.0 API | V1 did not have a first-class local-activity correctness contract. V2 ordinary activities always cross the durable task boundary. |
| Worker sessions | Deferred / no v2.0 API | Worker sessions require shutdown, affinity, cancellation, and lease semantics that are outside the first-release durable kernel. |
| Sticky execution | Supported replay optimization | Sticky execution now has an explicit runtime contract. It does not make process-local workflow state durable and never replaces cold replay from history. |
| Cross-namespace service calls | New durable surface | Service calls are not v1 parity work, but v2 supports them through durable service-call rows, linked target references, and boundary-policy outcomes. |
| Embedded v2 history import | Supported migration support | Embedded v2 runs can be imported into the standalone server from history-export bundles. This is separate from active v1 run migration, which remains finish-on-v1. |
| Reset | Reserved later-phase operator command | Reset needs typed history truncation/branch semantics. It is intentionally not part of the v2.0 correctness core. |
| Cross-process async closure transport | Deferred | App-local closures remain authoring sugar where supported, but portable workflow boundaries are named types and durable command payloads. |
| Async closure compatibility across SDKs | Not promised | Cross-SDK workers cannot replay serialized PHP closures. Use typed workflow/activity names for portable execution. |
| Unsupported backend combinations | Blocked early | V2 fails doctor/readiness instead of accepting unsafe configurations that cannot preserve durable correctness. |

There are no known current v1 product features left without a v2 home.
Items listed as deferred are either not v1 parity requirements or are
explicitly reserved for a future contract before support is advertised.

## Relationship To Other Contracts

- [`docs/api-stability.md`](../api-stability.md) freezes public API and
  history-event wire-format rules.
- [`docs/architecture/query-and-live-debug.md`](../architecture/query-and-live-debug.md)
  defines replay-safe query and live-debug boundaries.
- [`docs/architecture/child-outcome-source-of-truth.md`](../architecture/child-outcome-source-of-truth.md)
  defines child-run outcome authority.
- [`docs/architecture/scheduler-correctness.md`](../architecture/scheduler-correctness.md)
  defines schedule and timer correctness boundaries.
- [`docs/architecture/worker-compatibility.md`](../architecture/worker-compatibility.md)
  defines mixed-fleet worker compatibility.
- [`docs/architecture/worker-deployment.md`](../architecture/worker-deployment.md)
  defines first-class deployment lifecycle and rollout blockage.
- [`docs/architecture/sticky-execution.md`](../architecture/sticky-execution.md)
  defines sticky replay-cache routing and cold-replay fallback.
- [`docs/architecture/workflow-service-calls-architecture.md`](../architecture/workflow-service-calls-architecture.md)
  defines cross-namespace service-call lifecycle and outcome semantics.
- [`docs/architecture/cross-namespace-service-policy.md`](../architecture/cross-namespace-service-policy.md)
  defines service boundary authorization, limits, and audit facts.
- [`docs/architecture/control-plane-split.md`](../architecture/control-plane-split.md)
  defines role ownership for commands, matching, scheduler, history, and
  API ingress.
- [`docs/deployment/ha-failover.md`](../deployment/ha-failover.md)
  and [`docs/deployment/multi-region.md`](../deployment/multi-region.md)
  define the self-serve HA, DR, region, replication, private-networking,
  backup, and restore behavior consumed by managed-cloud deployments.
- [`docs/workflow-messages-architecture.md`](../workflow-messages-architecture.md),
  [`docs/search-attributes-architecture.md`](../search-attributes-architecture.md),
  and [`docs/workflow-memos-architecture.md`](../workflow-memos-architecture.md)
  define the durable metadata and message-stream homes named above.
- [`docs/architecture/platform-conformance-suite.md`](../architecture/platform-conformance-suite.md)
  defines how implementations prove the mapped surfaces conform.

## Changing This Contract

Changing a durable home, removing a mapped feature row, changing a
support boundary from supported to deferred, or claiming support for a
deferred item requires updating this document and
`tests/Unit/V2/FeatureMappingDocumentationTest.php` in the same change.
Changing a managed-cloud readiness guarantee requires updating
`tests/Unit/V2/ManagedCloudReadinessDocumentationTest.php` in the same
change.
If the change affects a frozen history-event field, update
[`docs/api-stability.md`](../api-stability.md) and the corresponding
payload contract tests as well.
