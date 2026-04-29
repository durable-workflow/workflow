# Workflow V2 Control-Plane and Execution-Plane Role Split Contract

This document freezes the v2 contract for how control-plane and
execution-plane responsibilities are named, separated, and combined so
operators, SDKs, CLI, Waterline diagnostics, server deployment guidance,
and test coverage can reason about node roles the same way. It is the
reference cited across the product when talking about how scaling,
failure domains, and authority boundaries map onto running processes.

The guarantees below apply to the `durable-workflow/workflow` package at
v2, to the standalone `durable-workflow/server` that embeds it, and to
every host that embeds the package directly or talks to the server over
HTTP. A change to any named guarantee is a protocol-level change and
must be reviewed as such, even if the class that implements it is
`@internal`.

This contract builds on the semantics frozen in
`docs/architecture/execution-guarantees.md` (Phase 1), the routing
guarantees frozen in `docs/architecture/worker-compatibility.md`
(Phase 2), and the matching and dispatch guarantees frozen by the
Phase 3 roadmap. Duplicate execution, retries, redelivery,
compatibility, and task matching keep the language they have there;
this document adds the language for which role owns which durable
responsibility and how those roles combine into deployment topologies.

## Scope

The contract covers:

- **role taxonomy** — the named roles that together make up a v2
  cluster: API ingress, control plane, matching, history/projection,
  scheduler, and execution workers. Each role is one logical
  responsibility, not one process type.
- **authority boundaries** — which role owns which durable mutation
  surface and which role is only allowed to observe.
- **failure domains** — how an outage on one role affects the others,
  what is expected to keep running, and what degrades gracefully.
- **scaling boundaries** — which roles scale independently and which
  scale with the workload they support.
- **deployment topologies** — the three supported shapes (embedded,
  standalone server, split control/execution) and which role
  assignments are legal in each.
- **migration path** — how a deployment moves from today's uniform
  node model to the split role model without a hard cutover.

It does not cover:

- the scheduler cache independence work described by Phase 5.
  Phase 5 will replace the shared-cache wake backend with a stronger
  primitive but must preserve the role boundaries named here.
- the rollout safety enforcement work described by Phase 6.
- host-level infrastructure choices such as reverse proxy selection,
  service mesh, TLS termination, Kubernetes vs Nomad vs bare Docker,
  or database placement. Those are deployment concerns that consume
  this contract; they do not define it.
- any move away from SQL persistence or the existing HTTP worker
  protocol. Role split is a topology change, not an engine rewrite.

## Terminology

- **Role** — a logical responsibility named by this contract. A role
  is owned by at least one process but is not itself a process type.
  A single process may fill multiple roles; that is the embedded
  deployment shape.
- **Control plane** — the role that owns durable state transitions
  (start, signal, cancel, terminate, reset, retry, continue-as-new,
  repair, archive, query, update) and the schedule automation that
  drives them.
- **Execution plane** — the role that owns workflow-task replay and
  activity-task execution. Execution plane processes are the workers
  themselves; they claim tasks, execute user code, and commit
  progress through the worker protocol.
- **Matching role** — the role that owns ready-task discovery, claim
  arbitration, dispatch publication, and wake notification. Defined
  by the Phase 3 matching contract; this contract names it as a
  first-class role so topologies can host it explicitly.
- **History/projection role** — the role that owns `HistoryEvent`
  persistence, visibility projection via
  `Workflow\V2\Support\RunSummaryProjector`, the
  `Workflow\V2\Contracts\HistoryProjectionRole` /
  `Workflow\V2\Support\DefaultHistoryProjectionRole` binding seam,
  and the observability surface via
  `Workflow\V2\Support\DefaultOperatorObservabilityRepository`.
- **Scheduler role** — the role that evaluates active
  `WorkflowSchedule` rows, resolves cron/interval triggers to
  workflow starts, and hands the start to the control plane through
  `Workflow\V2\Support\ScheduleManager`.
- **API ingress role** — the role that terminates HTTP for workers
  and operators, authenticates the request, maps it to the
  appropriate plane, and returns the JSON response shape frozen by
  the worker and control-plane contracts.
- **Deployment topology** — an assignment of roles to processes.
  Three topologies are supported: embedded, standalone server, and
  split control/execution.
- **Authority boundary** — the explicit list of durable mutations a
  role is allowed to perform. Roles may read widely; they may write
  only inside their authority boundary.

## Role taxonomy

The v2 system decomposes into six named roles. Each role is owned by
one or more live processes and has a single durable authority
boundary.

### Control-plane role

Authority:

- start, signal, query, update, cancel, terminate, reset, retry,
  continue-as-new, repair, and archive on workflow instances and
  runs.
- issuing workflow tasks, query tasks, and update tasks that capture
  the operator command.
- transitioning `workflow_runs.status` and recording the
  corresponding history events (`WorkflowStarted`, `WorkflowSignaled`,
  `WorkflowCancelRequested`, `WorkflowTerminated`, etc.).

Canonical implementation surface:

- `Workflow\V2\Contracts\WorkflowControlPlane` and
  `Workflow\V2\Support\DefaultWorkflowControlPlane` in the package.
- In the standalone server, the `/api/workflows/*` routes and
  `App\Http\Controllers\WorkflowController` delegate to that
  contract.

Guarantees:

- The control plane is the **only** role authorised to perform the
  mutations above. The execution plane MUST NOT invent new workflow
  starts, issue control commands, or transition run status outside
  of the outcomes it was asked to commit for a claimed task.
- Every control-plane mutation is durable before the operator call
  returns. The returned payload reflects state after commit, not an
  optimistic projection.
- Every control-plane mutation is visible to the history/projection
  role inside the same transaction. A successful operator response
  implies the projection will reflect the new state on the next
  read.

### Execution-plane role

Authority:

- claiming one workflow task or one activity task at a time through
  the matching role.
- executing user workflow code to replay history up to the next
  yield, or user activity code to produce an outcome.
- committing task completion or failure, heartbeat, and lease
  renewal through the worker protocol.

Canonical implementation surface:

- Worker processes using
  `Workflow\V2\Worker\WorkflowTaskWorker` and
  `Workflow\V2\Worker\ActivityTaskWorker`, including the Queue
  worker jobs `Workflow\V2\Support\Jobs\RunWorkflowTask`,
  `RunActivityTask`, and `RunTimerTask`.
- The HTTP surface that workers use:
  `POST /api/worker/workflow-tasks/poll`,
  `POST /api/worker/workflow-tasks/{taskId}/complete`,
  `POST /api/worker/workflow-tasks/{taskId}/fail`,
  `POST /api/worker/activity-tasks/poll`,
  `POST /api/worker/activity-tasks/{taskId}/complete`,
  `POST /api/worker/activity-tasks/{taskId}/fail`, and their
  heartbeat counterparts.

Guarantees:

- The execution plane is the **only** role authorised to run user
  code. The control plane, matching role, history/projection role,
  and scheduler MUST NOT import or execute workflow/activity bodies
  as part of their steady-state duties.
- The execution plane is allowed to write only the task-outcome and
  history surfaces named by the worker protocol. It MUST NOT edit
  schedule rows, namespace rows, or worker registration rows except
  through the registration/heartbeat surfaces already dedicated to
  it.
- The execution plane depends on the control plane and the matching
  role as upstream services. If either is unreachable, the execution
  plane backs off the Phase 3 wake channels and surfaces
  degraded-upstream state through its heartbeat rather than stalling
  silently.

### Matching role

Authority and canonical surface are frozen by the Phase 3 contract
in `docs/architecture/task-matching.md`. This contract adds:

- The matching role is topologically between the control plane
  (which produces ready tasks) and the execution plane (which
  consumes them). It owns no workflow state transitions; it owns
  only the assignment of a ready row to one leased owner.
- The matching role is allowed to run in three deployment shapes:
  as a library inside each worker process, as an in-server HTTP
  module, or as a dedicated matching service. The choice of shape
  is a deployment decision and does not change any Phase 1, Phase 2,
  or Phase 3 guarantee.
- The matching role MUST NOT run user workflow or activity code.
  Matching is a routing layer; execution is a separate role.

### History/projection role

Authority:

- writing `HistoryEvent` rows through the canonical history
  recording path inside the transaction that produced the event.
- projecting run, activity, timer, update, and signal state into
  `WorkflowRunSummary` and the operator observability surfaces via
  `Workflow\V2\Support\RunSummaryProjector`,
  `Workflow\V2\Contracts\HistoryProjectionRole`, and
  `Workflow\V2\Support\DefaultOperatorObservabilityRepository`.
- exporting redacted history through
  `Workflow\V2\Support\HistoryExport` and the
  `GET /api/workflows/{id}/runs/{runId}/history/export` route.

Canonical implementation surface:

- `Workflow\V2\Contracts\HistoryProjectionRole` and
  `Workflow\V2\Support\DefaultHistoryProjectionRole` own the
  synchronous run-projection entrypoints exposed by task-claim paths
  and `workflow:v2:rebuild-projections`.

Guarantees:

- History recording is **exactly-once per logical event** per the
  Phase 1 contract. Splitting the role out of process must preserve
  that guarantee; it MUST NOT introduce a second writer.
- Projection is synchronous with the event it reflects from the
  caller's point of view. A successful operator command or a
  successful task claim MUST be immediately readable through the
  projection. This is the seam the contract protects even after the
  role moves out of process.
- The observability surface is read-only for consumers. Cloud UI,
  Waterline, and CLI diagnostics read through
  `OperatorObservabilityRepository` and
  `Workflow\V2\Support\OperatorMetrics`; they MUST NOT write
  observability caches back through the same call path.

### Scheduler role

Authority:

- scanning active `WorkflowSchedule` rows and resolving the next
  fire time.
- invoking the control plane's `start()` to create a scheduled
  workflow instance; recording trigger outcome through
  `ScheduleTriggerResult` and `ScheduleStartResult`.
- pause, resume, backfill, and delete of schedules through
  `Workflow\V2\Support\ScheduleManager` when invoked by an
  authenticated operator.

Canonical implementation surface:

- `Workflow\V2\Contracts\SchedulerRole` and
  `Workflow\V2\Support\DefaultSchedulerRole` own the scheduler-role
  tick entrypoint exposed by `workflow:v2:schedule-tick`.
- `Workflow\V2\Support\ScheduleManager` and
  `Workflow\V2\Contracts\ScheduleWorkflowStarter` remain the
  schedule-lifecycle and scheduled-start boundary inside that role.

Guarantees:

- The scheduler is the **only** role authorised to fire scheduled
  workflow starts. Execution-plane workers MUST NOT race the
  scheduler to trigger a schedule; if they observe a due schedule,
  they leave it to the scheduler.
- Schedule starts route through the control plane's start
  contract. The scheduler does not bypass duplicate-start
  policies, compatibility pinning, or namespace checks.
- Schedule evaluation is deduplicated per trigger so a restart or
  split-leader scenario does not produce duplicate starts. Phase 6
  will harden this with explicit coordination health; this
  contract requires that the scheduler-role surface NOT create a
  new race the Phase 6 work must paper over.

### API ingress role

Authority:

- terminating HTTP for worker traffic and operator traffic,
  authenticating the request against the server's token model,
  and forwarding to the control plane, matching role, or
  observability surface.
- resolving the negotiated protocol version through
  `App\Http\ControlPlaneVersionResolver` and
  `App\Http\WorkerProtocolVersionResolver` so downstream roles see
  a single version for the request.

Guarantees:

- API ingress MUST NOT hold durable state. Every mutation is
  delegated to the control plane, matching role, or scheduler; the
  ingress layer is a stateless translator from HTTP to contract.
- API ingress is the one place authentication is enforced. Once a
  request has been authorised and forwarded, downstream roles MAY
  trust the namespace, role, and tenant scope carried on the
  request context without reauthenticating.
- API ingress maps every frozen contract reason code (Phase 1
  termination reasons, Phase 2 `compatibility_blocked` /
  `compatibility_unsupported`, Phase 3 claim reason codes) to a
  stable HTTP response shape. It MUST NOT collapse reason codes
  into a single generic error.

## Authority boundaries

The authority boundaries above are summarised here so a topology
designer can see them at a glance. Each row names the durable
mutation, the owning role, and the observing roles that MAY read but
MAY NOT write it.

| Durable mutation | Owning role | Read access |
| ---------------- | ----------- | ----------- |
| `workflow_instances`, `workflow_runs` status | Control plane | History/projection, API ingress |
| `workflow_tasks` (create, retire) | Control plane and history/projection | Matching, execution |
| `workflow_tasks` (lease, claim, release) | Matching | Execution, control plane |
| `activity_executions`, `activity_attempts` | Execution plane (outcomes) and control plane (creation) | History/projection |
| `history_events` | History/projection | Control plane, execution plane, API ingress |
| `run_summaries` | History/projection | Control plane, matching, API ingress |
| `workflow_schedules` | Scheduler (fire) and control plane (CRUD) | API ingress |
| `worker_compatibility_heartbeats` | Execution plane | Matching, history/projection, API ingress |
| `worker_registrations` (server model) | API ingress on register/heartbeat | Matching, observability |

Reads of another role's authority surface are always allowed;
writes are always forbidden. Role authority is the one invariant
Phase 4 protects during the split.

## Failure domains

Each role has an explicit expected behaviour when another role is
degraded. The goal is to make failure modes observable and bounded,
not to eliminate them.

- **Control plane down** — operator commands fail fast with an
  explicit transport error. Execution-plane workers continue
  processing tasks they have already claimed and let in-flight
  leases expire for tasks they have not. Schedules continue to
  fire only if the scheduler can still reach the control plane;
  otherwise scheduler evaluations back off and a missed fire is
  recorded on the schedule row.
- **Execution plane down** — control plane and scheduler continue
  accepting commands and creating ready tasks. Ready depth
  accumulates; operators see it through `OperatorMetrics::snapshot()`
  under `tasks.ready`. No work is lost. When execution plane
  capacity returns the matching role hands out the accumulated
  tasks using the normal claim contract.
- **Matching role down** — workers cannot claim new tasks. The
  Phase 3 fallback still applies: workers fall back to direct
  ready-task discovery against the task table, at the cost of
  increased database load. Wake notifications may be missed;
  operators see rising `tasks.ready` with falling claim rate.
- **History/projection role down** — the control plane and
  execution plane continue to persist their authority surfaces.
  Projection readers see stale data. The synchronous-projection
  guarantee is relaxed under this degraded state; operators see
  the relaxation through the `projection_lag_seconds` metric that
  `OperatorMetrics::snapshot()` is allowed to expose when the role
  runs out of process.
- **Scheduler down** — scheduled workflows do not fire. Missed
  fires are preserved as schedule state rather than fabricated on
  recovery, per the Phase 6 rollout-safety seam. Direct operator
  starts continue to work.
- **API ingress down** — no external HTTP traffic reaches the
  cluster. Embedded-mode deployments may still drive the control
  plane through in-process calls. This is the primary reason
  embedded mode remains supported.

## Scaling boundaries

Each role scales along a different axis so the topology can respond
to load without adding identical uniform nodes.

- **API ingress** scales with incoming HTTP connection count and
  request rate. Ingress processes are horizontally stateless and
  are fronted by any load balancer or reverse proxy; the contract
  places no ordering requirement across ingress replicas.
- **Control plane** scales with the rate of operator commands and
  the rate of run lifecycle transitions. It is horizontally
  scalable across replicas sharing one workflow database; ordering
  within a single run is serialised by the per-run row locks
  already frozen by the Phase 1 contract.
- **Matching role** scales with ready-task rate and poller count.
  It is horizontally scalable in its in-worker library shape and
  in-server HTTP shape; the dedicated-service shape is also
  horizontally scalable and is permitted to partition on
  `(namespace, connection, queue)` to reduce database fan-out.
- **History/projection role** scales with durable event rate. It
  is horizontally scalable across replicas; projection writers
  serialise on the run id they are projecting.
- **Scheduler role** scales with active schedule count. It is
  horizontally scalable with per-schedule leader election; Phase 6
  will freeze the leader-election contract, and topologies
  before that should run a single scheduler replica.
- **Execution plane** scales with workflow-task and activity-task
  rate. It is horizontally scalable and is the primary surface
  that autoscalers should touch; the other roles move far less
  under steady-state load.

The split makes autoscaling meaningful: scaling the execution
plane no longer implicitly scales the control plane, and scaling
the control plane no longer implicitly adds worker capacity.

## Supported deployment topologies

Three topologies are supported. Each is a legal role-to-process
assignment. Other role splits are possible but are not contract-
guaranteed.

### Embedded topology

One process fills every role. The host application embeds the
`durable-workflow/workflow` package directly and runs workers in
the same process via the Queue worker.

- All roles run in-process; the API ingress role is not active
  because there is no external HTTP surface.
- The control plane is the direct
  `DefaultWorkflowControlPlane` binding in the container.
- The matching role uses the in-worker library shape.
- Scheduler runs as a normal Laravel schedule hook against the
  app's database.
- When a server-hosted cluster discovery surface is present, the
  logical process-class name for this shape is
  `application_process`.

This topology is the lowest-friction way to adopt v2 and MUST
continue to work. The split is opt-in; no existing embedded host
is required to migrate.

### Standalone server topology

Three logical process classes: `server_http_node`,
`scheduler_node`, and `worker_node`. The HTTP node fills API
ingress, control plane, matching role (HTTP shape), and the
history/projection role. The scheduler runs as its own process
class, and worker nodes fill the execution plane plus a local
matching-library cache for polling.

- The server is `durable-workflow/server`, which embeds the
  `workflow` package.
- Workers talk to the server through the worker protocol.
- All three process classes share one workflow database.
- Scheduler runs on `scheduler_node` under per-schedule leader
  election; pre-Phase-6 deployments should run a single scheduler
  replica.
- `GET /api/cluster/info` publishes
  `App\Support\ServerTopology` with
  `current_shape: standalone_server`, the HTTP node's
  `current_roles`, and
  `shape_assignments.standalone_server.process_classes` naming
  `server_http_node`, `scheduler_node`, and `worker_node`.

This is the topology the standalone `server` repo ships today. It
MUST continue to work without topology-specific configuration
once the split lands.

### Split control/execution topology

Five logical process classes, with roles split for independent
scaling. Operators may co-locate some of them into four or more
live process groups, but the logical class names stay stable:

- **`ingress_node`** runs only API ingress.
- **`control_plane_node`** runs the control plane and the
  history/projection role.
- **`scheduler_node`** runs only the scheduler role.
- **`matching_node`** runs the matching role in its
  dedicated-service shape and may be co-located with the control
  plane for simpler deployments.
- **`execution_node`** runs only workers.

Requirements for this topology:

- All nodes share one workflow database and one wake-notification
  backend (Redis or database cache per the Phase 3 contract).
- The `durable-workflow/server` process image can host any subset
  of the non-execution roles by configuration; workers always
  run as their own process image.
- Protocol versions negotiated across roles remain single-stepped
  per the Phase 2 mixed-version guidance.
- `GET /api/cluster/info` keeps publishing the same
  `current_shape`, `current_roles`, `matching_role`, and
  `shape_assignments` vocabulary even when some logical classes are
  co-located physically.

This topology is the target shape for the Phase 4 work and is the
topology the migration path below lands on without a hard cutover.

## Migration path

The split lands incrementally. No deployment is required to change
shape; deployments that want the split scale benefits can adopt
each step independently.

1. **Audit role boundaries.** Every direct mutation of a role's
   authority surface from outside that role becomes a Phase 4
   violation. Tooling (Public Boundary scan, pinning tests) flags
   regressions but does not change runtime behaviour.
2. **Expose role binding points.** The package binds every role's
   canonical implementation through named container bindings so
   an out-of-process adapter can replace the binding without
   patching the package. Today's bindings are
   `WorkflowControlPlane`, `OperatorObservabilityRepository`,
   `MatchingRole`, `HistoryProjectionRole`,
   `HistoryProjectionMaintenanceRole`, `SchedulerRole`,
   `WorkflowTaskBridge`, `ActivityTaskBridge`,
   `LongPollWakeStore`, and the scheduler's
   `ScheduleWorkflowStarter`. The matching role now crosses the
   queue-loop wake and dedicated daemon entrypoints through
   `DefaultMatchingRole`, so a future out-of-process adapter can
   replace that binding without patching `Looping` listeners or
   `workflow:v2:repair-pass`. The history/projection role now
   crosses the matching seam through the
   `HistoryProjectionRole` / `HistoryProjectionMaintenanceRole`
   bindings backed by `DefaultHistoryProjectionRole`, so a future
   out-of-process adapter can replace that binding without
   patching the claim paths or the
   `workflow:v2:rebuild-projections` maintenance command. The
   scheduler role now crosses `workflow:v2:schedule-tick` through
   `DefaultSchedulerRole`, so a future out-of-process adapter can
   replace that binding without patching the command entrypoint.
3. **Introduce the dedicated matching shape.** The Phase 3
   contract already allows a dedicated matching role; Phase 4
   provides the deployment guidance for running it as a separate
   process and the topology doc above.
4. **Split history/projection.** The role becomes a separate
   process class that owns `history_events` and projections. The
   synchronous-projection guarantee is preserved by having
   projection commit in the same transaction as the event it
   reflects; splitting the role is a physical change, not a
   semantic one.
5. **Split scheduler.** The scheduler becomes a separate process
   class under leader election. Existing single-replica deployments
   remain legal.
6. **Optional execution partitioning.** Execution-plane workers
   partition on `(namespace, connection, queue, compatibility)`
   per the Phase 3 routing primitives for stronger fleet isolation.

Each step is reversible; collapsing the roles back onto a single
node is always a legal topology.

## Protocol version coordination

The split multiplies the number of protocol-version surfaces. Each
boundary carries its own negotiated version:

- The **worker protocol version** between execution nodes and the
  matching/control-plane HTTP surface is resolved by
  `App\Http\WorkerProtocolVersionResolver` and frozen by the
  Phase 2 contract.
- The **control-plane protocol version** between operator traffic
  and the control-plane HTTP surface is resolved by
  `App\Http\ControlPlaneVersionResolver` and carries the shape of
  the operator JSON envelopes.
- The **internal role-to-role version** between the API ingress
  and the control plane, matching role, and history/projection role
  is bound by the `durable-workflow/workflow` package version
  deployed; the split topology MUST keep these versions aligned
  within one single-step range per the Phase 2 guidance.

Mixed-version safety across the split is explicit: no role is
allowed to assume a newer contract than it has already negotiated
with its caller.

## Authority over worker registration

Worker registration is a multi-role contract today. Workers call
`POST /api/worker/register` and `POST /api/worker/heartbeat` through
API ingress. The server-side `WorkerRegistration` model is the
durable record. Phase 4 freezes the role split for that surface:

- **API ingress** authenticates the worker bearer token and maps
  it to a tenant / namespace context.
- **Execution plane** (the worker process) is the source of truth
  for its own identity and heartbeat. The heartbeat TTL contract
  named by Phase 2 applies unchanged.
- **Matching role** reads `WorkerRegistration` and
  `workflow_worker_compatibility_heartbeats` to decide which live
  workers are eligible claimers. It does not mutate either table.
- **Control plane** reads registrations to surface them to
  operators through
  `App\Http\Controllers\WorkerManagementController`. The
  `DELETE /api/workers/{workerId}` surface is the explicit escape
  hatch; operators, not workers, own it.

## Operator-visible role state

Operators must be able to see which roles are healthy and which
are degraded without reading model tables directly:

- The standalone server exposes
  `GET /api/cluster/info`, backed by
  `App\Support\ServerTopology`, so operators can read the current
  role split (`current_shape`, `current_process_class`,
  `current_roles`, `matching_role`, and `shape_assignments`)
  without inferring it from deployment notes.
- Embedded or package-local installs expose the same
  `durable-workflow.v2.role-topology` manifest through
  `php artisan workflow:v2:doctor --json` under the `topology`
  key, so operators can read the current application-process
  role bundle without standing up the standalone server first.
- `Workflow\V2\Support\OperatorMetrics::snapshot()` reports per-role
  health signals: `tasks.ready`, `tasks.leased`,
  `tasks.claim_failed`, `repair.missing_task_candidates`,
  `workers.active_workers`, `schedules.active`, and
  `schedules.missed`.
- `Workflow\V2\Support\OperatorQueueVisibility::forNamespace()` and
  `::forQueue()` report matching-role health per partition.
- The standalone server exposes the snapshot at
  `GET /api/system/metrics` and the admin-only repair and
  retention surfaces at `GET /api/system/repair`,
  `POST /api/system/repair/pass`,
  `GET /api/system/activity-timeouts`,
  `POST /api/system/activity-timeouts/pass`,
  `GET /api/system/retention`, and
  `POST /api/system/retention/pass`.
- Cloud UI and Waterline read these surfaces through the
  `OperatorObservabilityRepository` binding so the role split is
  observable to humans.

Guarantees:

- Role health is surfaced through the metrics snapshot rather than
  through inferred behaviour. Operators MUST NOT have to guess
  whether a role is up from indirect signals.
- A role running out of process is visible to
  `OperatorMetrics::snapshot()` with at least the same fidelity as
  when it ran in-process. Splitting a role MUST NOT make operations
  harder to observe.

## Test strategy alignment

- `tests/Feature/V2/V2OperatorMetricsTest.php` covers the operator
  metrics surface that exposes role-health signals.
- `tests/Feature/V2/V2OperatorQueueVisibilityTest.php` covers the
  matching-role health per partition.
- `tests/Feature/V2/V2CompatibilityWorkflowTest.php` covers the
  control-plane and execution-plane split for compatibility
  routing.
- `tests/Feature/V2/V2TaskDispatchTest.php` covers the matching-role
  publication contract across topologies.
- `tests/Feature/V2/V2ScheduleManagerTest.php` (when present) and
  `tests/Feature/V2/V2ScheduleTest.php` cover the scheduler-role
  boundary against the control plane.
- This document is pinned by
  `tests/Unit/V2/ControlPlaneSplitDocumentationTest.php`. A change
  that renames, removes, or narrows any named guarantee (the six
  roles, the authority boundary table, the three supported
  topologies, the migration path, or the protocol-version
  coordination rule) must update the pinning test and this
  document in the same change so the split contract does not
  drift silently.

## What this contract does not yet guarantee

The following are explicitly deferred to later roadmap phases and
must not be assumed:

- A dedicated matching-role process image shipped by this repo.
  The contract permits the dedicated shape; the operator tooling
  and Helm/Compose overlays for it are tracked separately and are
  out of scope here.
- Per-tenant or per-namespace role pinning (for example, running
  a dedicated control-plane replica per tenant). Namespace is a
  routing primitive, not a role boundary.
- Multi-region active/active topology. Cross-region coordination
  is a future roadmap topic and is not covered.
- Replacement of the shared wake backend. That is Phase 5.
- Rollout-safety enforcement and scheduler leader election. That
  is Phase 6.

## Changing this contract

A change to any named guarantee in this document is a protocol-level
change for the purposes of `docs/api-stability.md` and downstream
SDKs. Reviewers should treat unmotivated changes to the language
above as breaking changes and require explicit cross-SDK
coordination before merge. The Phase 4 roadmap owns updates
to this contract; Phase 5 and Phase 6 must extend the
contract rather than silently redefine it.
