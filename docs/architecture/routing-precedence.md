# Workflow V2 Routing Precedence and Inheritance Contract

This document freezes the v2 contract for how workflow-task and
activity-task routing targets are resolved at every entry point,
how snapped routing travels with the durable entity, and how
in-flight work inherits routing across retries, continue-as-new,
and parent-to-child transitions. It is the reference cited by the
v2 docs, CLI reasoning, Waterline diagnostics, server deployment
guidance, and test coverage so the whole fleet speaks one language
about routing decisions, their precedence, and their durability.

The guarantees below apply to the `durable-workflow/workflow` package
at v2 and to every host that embeds it or talks to it over the
worker protocol. A change to any named guarantee is a protocol-level
change and must be reviewed as such, even if the class that
implements it is `@internal`.

This contract builds on the semantics frozen in
`docs/architecture/execution-guarantees.md` (Phase 1), the
compatibility and routing scope frozen in
`docs/architecture/worker-compatibility.md` (Phase 2), and the
partition primitives frozen in `docs/architecture/task-matching.md`
(Phase 3). Those documents define what `connection`, `queue`, and
`compatibility` *are*; this document defines how they are *chosen*
and when they are *re-chosen*.

## Scope

The contract covers:

- **resolution precedence** — the per-level rule that decides which
  of the available inputs (per-call options, workflow class defaults,
  parent run snapshot, framework defaults) determines the
  `connection` and `queue` for a given piece of scheduled work.
- **snapped routing** — the durable `connection` and `queue` columns
  on `workflow_runs`, `workflow_tasks`, `activity_executions`, and
  `workflow_schedules` that pin a routing decision once made.
- **inheritance** — when a downstream run or retry re-uses snapped
  routing from an upstream durable row instead of re-resolving.
- **continue-as-new** — how the continued run carries the previous
  run's snapped routing and compatibility marker forward.
- **retries** — how workflow-task retries, activity-attempt retries,
  heartbeats, and resume tasks reuse the routing that was snapped on
  the scheduled entity they belong to.
- **schedule-triggered runs** — how `workflow_schedules.connection`
  and `workflow_schedules.queue` flow into the started run.
- **effective routing visibility** — which projection surfaces
  expose the snapped routing so operators and Waterline can reason
  about where a specific run or task is executing.

It does not cover:

- the dedicated task-matching service described by the Phase 3
  roadmap. That surface consumes the snapped `connection` and
  `queue` values frozen here; it does not re-resolve them.
- the control-plane/data-plane role split described by Phase 4.
  The split moves which role writes the snapshots but does not change
  the precedence rules named here.
- cache-backed wake propagation described by Phase 5. Wake
  signals are eligibility announcements, not routing decisions.
- host-level queue topology (priorities, sharding schemes, managed
  lanes). Those are operator choices that consume the snapshot; they
  do not define the precedence.
- worker compatibility marker selection, which remains frozen in
  `docs/architecture/worker-compatibility.md`. This document names
  compatibility only where it travels alongside routing on the same
  durable row.

## Terminology

- **Routing target** — the pair `(connection, queue)`. `connection`
  names the host queue connection. `queue` names the queue within
  that connection. A routing target is durable state pinned on the
  scheduled entity.
- **Per-call options** — the `WorkflowOptions`, `ChildWorkflowOptions`,
  or `ActivityOptions` value passed as the last argument (or via the
  start API) when scheduling a specific piece of work. Per-call
  options exist to override class defaults for one call; they are
  not a fleet-wide configuration surface.
- **Class defaults** — the `$connection` and `$queue` properties
  advertised by a workflow or activity class through
  `DefaultPropertyCache::for($class)`. Class defaults describe what
  the implementation author considers "normal" routing for that class.
- **Snapped routing** — the `connection` and `queue` columns stored on
  the durable row (`workflow_runs`, `workflow_tasks`,
  `activity_executions`, `workflow_schedules`) at the moment of
  creation. Snapped routing is the authoritative target the engine
  uses for redelivery, repair, and all subsequent tasks derived from
  that row.
- **Inheritance** — the rule that a child, retry, or continued run
  reuses an upstream row's snapped routing rather than re-resolving
  from class defaults. Inheritance is intentional: it preserves the
  operator-visible target for the lifetime of the workflow.
- **Resolution** — the act of choosing a routing target *for the
  first time*. Resolution happens at workflow-start, child-workflow
  scheduling, activity scheduling, and schedule creation. It does not
  happen again for the same durable entity.
- **Re-resolution** — explicitly choosing a new routing target when
  the API asks for one. Continue-as-new with new options and
  child-workflow calls with new options are the only supported
  re-resolution surfaces. Retries never re-resolve; they reuse the
  snapshot.
- **Dedicated-queue pattern** — a class that advertises a
  non-default `$queue` property so every run of that class lands on
  the same queue. This pattern is preserved by snapshotting: once a
  run picks its queue, retries, continue-as-new, and descendant
  tasks stay on that queue even if the class default changes.
- **Same-server affinity pattern** — a connection that maps to a
  specific worker set (often a single queue worker), pinning all
  tasks for a run to that set. This pattern is preserved by the
  same snapshotting rules as the dedicated-queue pattern.

## Guaranteeing authority

The contract authorities are:

- **`RoutingResolver`** — the sole authority that turns per-call
  options and class defaults into a `(connection, queue)` pair for
  a newly scheduled workflow run or activity attempt. Callers that
  create `workflow_runs` or `activity_executions` rows MUST use
  `RoutingResolver::workflowConnection`,
  `RoutingResolver::workflowQueue`,
  `RoutingResolver::activityConnection`, and
  `RoutingResolver::activityQueue`. No other class is allowed to
  compute `(connection, queue)` from the same inputs.
- **`WorkflowOptions`** — the durable wire value for workflow start
  routing overrides. The type carries only `?string $connection` and
  `?string $queue`. Any additional fields on `WorkflowOptions` are a
  protocol change.
- **`ActivityOptions`** — the durable wire value for activity
  scheduling overrides. Routing fields on `ActivityOptions` are
  `?string $connection` and `?string $queue`; retry and timeout
  fields are documented separately in
  `docs/architecture/execution-guarantees.md`.
  `ActivityOptions::hasRoutingOverrides()` is the authority on
  "did the author supply a routing override".
- **`ChildWorkflowOptions`** — the durable wire value for child
  workflow scheduling overrides. Routing fields are `?string
  $connection` and `?string $queue`, alongside the
  `parent_close_policy`. `ChildWorkflowOptions::hasRoutingOverrides()`
  is the authority on "did the caller supply a routing override for
  this child".

A subsystem that recomputes `(connection, queue)` from its own
inputs, or that carries extra fields on the options types without
extending the wire contract, is out of contract.

## Resolution at workflow start

Starting a workflow (`WorkflowStub::start`) resolves the run's
routing exactly once, at creation. The precedence is:

1. If the caller passed a `WorkflowOptions` argument with
   `$connection !== null`, that connection wins.
2. Otherwise, the workflow class's `$connection` default wins if the
   default is a non-empty string.
3. Otherwise, the stored value is `null`, meaning "the engine will
   use the framework default when dispatching".

Queue resolution for workflow-task dispatch follows the same
precedence on `$queue`, with one extra tail step: if no per-call or
class-default queue was chosen, the resolver reads
`config('queue.connections.<connection>.queue', 'default')` for the
connection selected above (falling back through
`config('queue.default')` when the connection is also `null`).

This is enforced by `RoutingResolver::workflowConnection` and
`RoutingResolver::workflowQueue`. The resolved value is snapped into
`workflow_runs.connection` and `workflow_runs.queue` and used to
create the first `workflow_tasks.connection` / `workflow_tasks.queue`
row. After this, the run's routing is snapped.

### Key rules

- Workflow start options override workflow defaults **only for
  workflow tasks**. Activities scheduled inside the run continue to
  use activity-level resolution. The `WorkflowOptions` value does not
  propagate to activity scheduling.
- A `WorkflowOptions` value with both fields `null` is equivalent to
  "no options passed" — the resolver falls through to class defaults.
- A `WorkflowOptions` value with only one field non-null sets only
  that field; the other field continues through class defaults.
- The resolver does not interpret connection or queue strings. A
  typo in a connection name produces a run that will not be claimed
  by any worker until the queue is actually provisioned.

## Resolution at child-workflow scheduling

Calling `child(...)` from a parent workflow schedules a child run.
`WorkflowExecutor::scheduleChildWorkflow` resolves the child's
routing at creation, using:

1. If the call passed `ChildWorkflowOptions` (or `WorkflowOptions`)
   with `$connection !== null`, that connection wins.
2. Otherwise, the child workflow class's `$connection` default wins
   if non-empty.
3. Otherwise, the value is `null` and the `RoutingResolver` queue
   fallback chain applies.

Queue resolution follows the same shape on `$queue`.

Child-call options override the child workflow's own task routing,
**not every activity inside that child**. Activities called from
the child resolve their own `(connection, queue)` via
`RoutingResolver::activityConnection` and `::activityQueue`, which
consult the child run's snapped values (not the parent's options).

The child run's `(connection, queue)` is snapped onto
`workflow_runs` for the child and onto the first
`workflow_tasks` row for the child's initial workflow task. Once
snapped, it survives the child's retries, continue-as-new, and its
own further descendants exactly as for a top-level run.

### Parent-to-child compatibility handoff

Compatibility inheritance is a separate rule documented in
`docs/architecture/worker-compatibility.md`: the child run inherits
the parent's `compatibility` marker so a pinned fleet stays pinned.
Routing and compatibility are independent — a child may inherit the
parent's compatibility marker while still resolving routing from its
own class defaults, and a child-call option that overrides routing
does not override compatibility.

## Resolution at activity scheduling

Scheduling an activity via `activity(...)` resolves the activity's
routing at creation. `RoutingResolver::activityConnection` applies
this precedence:

1. If `ActivityOptions::$connection !== null`, that connection wins.
2. Otherwise, the activity class's `$connection` default wins if
   non-empty.
3. Otherwise, the parent run's snapped `connection` wins if non-null.
4. Otherwise, the resolver falls through to
   `config('queue.default')`.

`RoutingResolver::activityQueue` mirrors this precedence on `$queue`,
with the same config tail as workflow-task resolution. Specifically:

- Per-call `ActivityOptions::$queue` wins.
- Otherwise, the activity class's `$queue` default wins.
- Otherwise, the parent run's snapped `queue` wins.
- Otherwise, the resolver reads
  `config('queue.connections.<connection>.queue', 'default')`.

### Key rules

- Activity-level routing defaults win for activity executions unless
  the API explicitly requests inheritance via `ActivityOptions` with
  both `$connection` and `$queue` left `null`, in which case the
  activity falls through to the run's snapped routing. Leaving
  `ActivityOptions` `null` entirely is equivalent to passing an
  options object with both fields `null`: it is the inheritance
  request shape.
- The run's snapped routing is the *fallback* for activities that
  declare no own defaults. It does not pre-empt an activity class
  default. An activity that declares `$queue = 'billing'` goes on
  the `billing` queue even if the parent run is snapped to
  `'default'`.
- The resolver never walks further up than the run row. It does not
  consult the root workflow instance, any ancestor parent run, or
  framework-wide routing policy for activities.

## Schedule-triggered runs

`ScheduleManager::createFromSpec` accepts `?string $connection` and
`?string $queue` arguments and snaps them onto
`workflow_schedules.connection` and `workflow_schedules.queue`. When
`PhpClassScheduleStarter::start` triggers a run from the schedule,
the schedule's routing flows into a `WorkflowOptions` value that is
appended to the start arguments. From there, workflow start
resolution applies exactly as above.

### Key rules

- A schedule's routing overrides the workflow class defaults for
  every run the schedule triggers, exactly as a
  `WorkflowOptions` value would.
- A schedule with `(connection, queue) = (null, null)` triggers runs
  that follow class defaults and the resolver's fallbacks. The
  schedule does not force `null` onto the run.
- Changing `workflow_schedules.connection` or
  `workflow_schedules.queue` affects future triggered runs only.
  Runs already created keep their snapped routing.

## Snapped routing columns

The durable columns that carry snapped routing are:

- `workflow_runs.connection`, `workflow_runs.queue` — the authoritative
  target for this run's workflow tasks. Pinned at run creation.
- `workflow_tasks.connection`, `workflow_tasks.queue` — the target for
  one specific workflow task (activity task, workflow task, timer
  task, or cancel task). Pinned at task creation.
- `activity_executions.connection`, `activity_executions.queue` — the
  target for every attempt of one activity execution. Pinned at
  activity scheduling. Retry attempts reuse these values directly;
  they do not re-read class defaults.
- `workflow_schedules.connection`, `workflow_schedules.queue` — the
  target carried into runs triggered by the schedule.

These columns are the source of truth for all downstream dispatch,
repair, and observability code paths. Nothing else should re-derive
`(connection, queue)` from class defaults once the column is
populated.

### Task creation inherits from the owning durable row

- Workflow-task rows for a run (start, continue, resume, cancel) use
  `$run->connection` and `$run->queue` directly. They do not
  re-consult workflow class defaults.
- Activity-task rows for an execution use `$execution->connection`
  and `$execution->queue` directly. They do not re-consult activity
  class defaults.
- Timer-task rows use `$run->connection` and `$run->queue`. Timers
  follow the run, not the timer-call site.

## Retry inheritance

### Activity-attempt retries

An activity attempt that fails and is eligible for retry produces a
new `activity_attempts` row (when separate from the execution) and
a new ready `workflow_tasks` row. Both reuse
`activity_executions.connection` and `activity_executions.queue`.
Activity retries never re-run resolution, so the activity stays on
its original queue even if the activity class default changes
mid-flight.

### Child-workflow retries

A child-workflow retry path (`WorkflowExecutor::retryChildWorkflow`)
creates a new child `workflow_runs` row. The retry run copies
`connection` and `queue` from the failed child run, not from the
child workflow class defaults and not from the parent's child-call
options.

### Workflow-task retries and repair

Workflow-task retries, resume-task redelivery, and repair-driven
re-creation (`TaskRepair::repairRun`,
`TaskRepair::recoverExistingTask`) all use the run's snapped
`connection` and `queue` when creating or recovering a task. The
repair path does not re-resolve routing even if the workflow class
defaults have changed since the run started.

### Activity heartbeats

Activity heartbeats write to `activity_attempts` under the attempt's
existing lease. They never change routing. A heartbeat can only
extend the lease on the queue the attempt already belongs to.

## Continue-as-new inheritance

`WorkflowExecutor::continueAsNew` creates the continued
`workflow_runs` row with `connection = $run->connection`,
`queue = $run->queue`, and `compatibility = $run->compatibility`.
The continued run therefore inherits both routing and compatibility
from the previous run by default.

### Overrides at continue-as-new

Continue-as-new may override routing only by the normal
`WorkflowOptions` surface: the caller passes a `WorkflowOptions`
value in the continue-as-new arguments, and that value is carried
into the continued run's `WorkflowMetadata`. `RoutingResolver`
applied to the continued run will prefer the `WorkflowOptions`
value over the previous run's snapshot when the options are present
and non-null.

### New run snapshots inherited values on creation

Continue-as-new is the only inheritance path that also snapshots a
new row. The continued `workflow_runs.connection` and
`workflow_runs.queue` are written at the moment of creation and do
not track the previous run after that point. Changing the original
run's columns after continue-as-new does not retroactively affect
the continued run.

## Snapped routing preserves dedicated-queue and same-server affinity

Operators rely on two established patterns, and both are preserved
by the snapshot/inheritance rules above:

- **Dedicated-queue pattern** — a workflow class or activity class
  advertises a non-default `$queue` string. Every run or execution
  of that class lands on that queue at creation. Retries,
  continue-as-new, and descendant tasks stay on it because they
  inherit from the snapshot. A later change to the class default
  does not retroactively move existing runs off the queue.
- **Same-server affinity pattern** — a `$connection` that targets a
  worker set co-located with a specific service (for example, a
  connection pinned to workers that hold a local cache warm-up).
  Snapping `(connection, queue)` at run creation keeps the run's
  tasks pinned to that set even as the fleet rolls or scales. The
  repair path uses the same snapshot, so a worker-loss repair
  re-enqueues on the same affinity lane.

These patterns are part of the contract: a subsystem that would
cause dedicated-queue or same-server affinity to silently move off
its snapshotted lane during retries, repair, or continue-as-new is
out of contract.

## Effective routing in projections

Operators need to answer "where is this run running?" without
reading the engine code. Snapped routing is exposed in the
projections used by Waterline, the CLI, and Cloud:

- `RunDetailView::forRun` exposes `connection` and `queue` on the
  returned payload. The same response also exposes the
  `compatibility_fleet` computed by `WorkerCompatibilityFleet::details`,
  which scopes the live-worker list by `(compatibility, connection,
  queue)` so operators can see which workers will accept the run's
  tasks.
- `RunListItemView` exposes the run's `queue` in the list payload,
  backed by the `workflow_run_summaries.queue` column maintained by
  the run summary projector. Listing screens can filter or sort by
  this column without joining back to `workflow_runs`.
- `OperatorQueueVisibility` groups ready and running counts by
  `(connection, queue, compatibility)` so queue-health metrics are
  always reported along the same partition axes the contract names.

These projections consume the snapped values; they do not recompute
routing from class defaults. A surface that would prefer a
recomputed value over the snapshot is out of contract.

## Interaction with compatibility

Routing and compatibility are independent axes. A task must match
both to be claimed:

- Routing narrows which workers see the task at all. A worker that
  does not poll `(connection, queue)` never observes the task.
- Compatibility determines whether a worker that does see the task
  is allowed to claim it. Compatibility is enforced at claim time by
  `ActivityTaskClaimer` and the workflow-task equivalent.

A routing override that silently weakens the compatibility
guarantee is out of contract. Specifically:

- A child-call or activity-call may not set a routing target that is
  known to be served only by an incompatible worker set. The host
  remains free to run incompatible workers on adjacent queues, but
  the engine does not arbitrate that choice beyond compatibility
  enforcement at claim time.
- A schedule's routing fields never widen or narrow the compatibility
  contract. The triggered run inherits the caller's current
  compatibility marker as documented in
  `docs/architecture/worker-compatibility.md`.

## Config surface and defaults

The runtime config surface for routing resolution is intentionally
small:

- `queue.default` — the host Laravel queue default connection.
  Consulted only when the resolver falls all the way through per-call,
  class-default, and parent-run-snapshot inputs with no value.
- `queue.connections.<name>.queue` — the default queue for a given
  connection. Consulted as the tail of queue resolution when the
  earlier precedence steps produce no queue.
- `workflows.v2.namespace` — the namespace value carried alongside
  the routing target on `workflow_runs`, `workflow_tasks`, and
  `workflow_schedules`. The namespace is not part of the routing
  decision; it is a separate filter on the poll surface documented
  in `docs/architecture/task-matching.md`.

The contract does not introduce new environment variables. All
routing behavior is controlled through the per-call options, class
defaults, and the host queue config above.

## What this contract does not yet guarantee

These are intentionally out of scope for the v2 routing precedence
and inheritance contract; each is covered elsewhere or deferred to a
follow-on phase:

- **Queue priority ordering.** The contract does not bake priority
  into queue names or into the resolver. Hosts that need priority
  traffic run priority queues and configure workers accordingly.
- **Sharding by task id.** The matching role does not shard tasks
  across pollers by hash of `task_id`. Sharding is an operator-level
  queue-naming choice per `docs/architecture/task-matching.md`.
- **Retry-time routing rewrites.** Activity and workflow-task retries
  never re-resolve. A future roadmap item may add explicit re-route
  semantics for degraded queues; adding one is a protocol change.
- **Cross-namespace routing.** Namespaces partition the poll surface
  and are not part of the routing decision. Cross-namespace calls are
  out of scope for this contract.
- **Webhook and command-ingress routing.** Command-plane ingress
  routing is tracked in `docs/architecture/control-plane-split.md`
  (Phase 4) and in the webhook/command taxonomy. Ingress
  routing is not the same as task routing and does not share
  resolution rules.
- **Dedicated matching service partitioning.** Phase 3 may
  introduce additional partition metadata on the matching side. When
  it does, the matching service will consume `(connection, queue,
  compatibility, namespace)` exactly as frozen in
  `docs/architecture/task-matching.md` and this document.

## Test strategy alignment

The behaviour frozen above is covered by the following coordinated
tests. A change to any of the rules above must update both the
documented contract and the matching test in the same change:

- `RoutingResolver` unit coverage for the workflow and activity
  resolution precedence, including the `WorkflowOptions` /
  `ActivityOptions` / class-default / parent-run-snapshot / config
  tail chains.
- `V2WorkflowStartTest`, `V2ActivityOptionsTest`, and
  `V2ChildWorkflowTest` suites exercising the concrete snapshot
  behaviour across start, child, activity, retry, and
  continue-as-new paths.
- `V2ScheduleTest` and `PhpClassScheduleStarterTest` for the
  schedule-to-run routing handoff.
- `V2OperatorQueueVisibilityTest` and the run-detail/list projection
  tests for the operator-visible routing exposure.
- This pinning test,
  `tests/Unit/V2/RoutingPrecedenceDocumentationTest.php`, which
  asserts the contract document contains the frozen headings, terms,
  authority class names, column names, and the rule statements named
  above.

## Changing this contract

A change to any of the resolution rules, snapshot columns,
inheritance paths, or exposed projection fields above is a
protocol-level change. The required process is:

1. Update this contract document first, including the terminology,
   the resolution and inheritance tables, and the test strategy
   alignment section as appropriate.
2. Update the pinning test
   `tests/Unit/V2/RoutingPrecedenceDocumentationTest.php` in the
   same change so the regression guard tracks the new rule.
3. Update the concrete behaviour tests listed above so they exercise
   the new rule.
4. Update product docs on the docs site, CLI reasoning, and
   Waterline surfaces that reference the rule so the fleet speaks
   one language.

This contract intentionally defers the following to future
roadmap issues rather than redefining them here:

- queue priority semantics, sharding, and partition metadata beyond
  the four primitives frozen in
  `docs/architecture/task-matching.md`.
- retry-time rerouting and degraded-queue drainage, which require a
  new contract extension.
- cross-namespace routing, which is out of scope for this contract.

See `docs/architecture/execution-guarantees.md`,
`docs/architecture/worker-compatibility.md`,
`docs/architecture/task-matching.md`, and
`docs/architecture/rollout-safety.md` for the adjacent frozen
contracts this document builds on.
