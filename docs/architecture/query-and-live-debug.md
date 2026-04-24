# Workflow V2 Query and Live-Debug Non-Durability Contract

This document freezes the v2 contract for how query handlers and
operator live-debug surfaces observe workflow state without entering
history, without mutating durable state, and without affecting other
workflow callers. It is the reference cited by the v2 docs, CLI
reasoning, Waterline diagnostics, server HTTP documentation, SDK
documentation, and test coverage so the whole fleet speaks one
language about non-durable reads.

The guarantees below apply to the `durable-workflow/workflow` package
at v2 and to every host that embeds it or talks to it over the
worker protocol. A change to any named guarantee is a protocol-level
change and must be reviewed as such, even if the class that
implements it is `@internal`.

This contract builds on the semantics frozen in
`docs/architecture/execution-guarantees.md` (Phase 1). Phase 1 names
query handlers briefly as "non-durable reads against the
currently-resolved run state"; this document extends that language
into the full contract for query invocation, live-debug surfaces,
command-dispatch suppression, and the error-code boundary that
separates "query not supported" from "workflow run unavailable".

## Scope

The contract covers:

- **query handlers** — methods declared with the `QueryMethod`
  attribute on a workflow class. Queries return a value to the
  caller derived from the workflow's currently-resolved state.
- **live-debug surfaces** — the operator-facing read surfaces that
  let humans and automation inspect a running workflow without
  sending a signal, update, or cancel. This includes Waterline's
  run-detail views, the CLI `dw run describe` surfaces, the
  server HTTP run-read APIs, and the aggregate
  `OperatorMetrics` / `OperatorQueueVisibility` snapshots.
- **command-dispatch suppression** — the mechanism by which the
  engine runs authoring or update code inside a replay without
  producing new durable commands. Queries are the most prominent
  caller but update-replay inside `WorkflowExecutor` uses the
  same switch.
- **query response codes** — the 200/409/422 boundary returned by
  `Workflow\V2\Support\QueryResponse::execute()` so callers can
  distinguish validation errors, missing definitions, and
  temporary execution-unavailability.

It does not cover:

- signal and update semantics, which DO enter history and mutate
  durable state. Those live in Phase 1's execution-guarantees
  contract. Queries are specifically the non-durable counterpart.
- the durable operator observability repository bindings
  described in `docs/architecture/control-plane-split.md`. That
  contract names WHO writes and WHO serves the observability
  surface; this contract adds what the non-durable READ contract
  guarantees at those surfaces.
- host-level live-debug features such as IDE attach, tracing,
  profiling, or APM integrations. Those are consumer conveniences
  that layer on top of the non-durable surfaces here.

## Terminology

- **Query** — a read-only call that asks the workflow "what is
  your state right now?" and returns a derived value. Queries
  do not produce history events, do not mutate database rows,
  do not dispatch commands, and do not race with concurrent
  workflow tasks.
- **Live-debug surface** — any operator-facing read that reports
  the current state of a run. Live-debug surfaces render
  projections, history rows, typed summary rows, and computed
  waits but do not trigger any replay-driven authoring code.
  Queries are the only v2 surface that replays authoring code in
  a non-durable way.
- **Command-dispatch suppression** — the internal flag
  `commandDispatchEnabled` on `Workflow\V2\Workflow`. While the
  flag is false, authoring methods that would normally enqueue
  signals, updates, or child-workflow signals through handles
  return null without side effect. The flag is the in-process
  safeguard that makes query replay side-effect-free.
- **Non-durable** — a read that leaves zero durable state
  behind. The run's history, workflow_runs row, workflow_tasks
  rows, workflow_messages rows, workflow_commands rows,
  workflow_child_calls rows, and workflow_updates rows are
  identical before and after the read.
- **Currently-resolved state** — the workflow authoring object
  rebuilt from history up to the latest committed event, plus
  any already-applied update mutations. It is the same state the
  next committed workflow task would see if it started right now.

## Query invocation contract

`Workflow\V2\WorkflowStub::query()` / `::queryWithArguments()` is the
sole client entry point for v2 queries. The invocation contract:

- A query request names a run indirectly through a `WorkflowStub`
  bound to a workflow instance id. `WorkflowStub::refresh()`
  reloads the current run before the query runs so the query
  sees the latest committed state.
- `Workflow\V2\Support\WorkflowDefinition::hasQueryMethod()` is
  the authoritative predicate for whether a method is a query.
  The method must carry the `Workflow\QueryMethod` attribute on
  the declared class; arbitrary public methods are NOT implicitly
  queryable.
- `WorkflowStub::resolveQueryTarget()` resolves name aliases to
  the canonical `['name' => ..., 'method' => ...]` descriptor.
  Queries may be named independently of their PHP method name.
- `WorkflowStub::queryWithArguments()` validates arguments
  against the declared method signature via
  `validatedQueryArgumentsForRun()`. Validation errors raise
  `Workflow\V2\Exceptions\InvalidQueryArgumentsException` carrying
  the query name and structured validation errors.
- `Workflow\V2\Support\WorkflowExecutionGate::blockedReason()` is
  consulted before replay. If the gate returns a non-null reason,
  the query raises
  `Workflow\V2\Exceptions\WorkflowExecutionUnavailableException`
  with the blocked reason code. The only code defined today is
  `BLOCKED_WORKFLOW_DEFINITION_UNAVAILABLE` with the value
  `'workflow_definition_unavailable'`.
- The query method is invoked with command dispatch suppressed:
  `$workflow->setCommandDispatchEnabled(false)` is set by
  `Workflow\V2\Support\QueryStateReplayer::query()` before
  calling the method and remains set for the duration of the
  call.
- The invoked method MAY read workflow state, MAY throw, and MAY
  return any serialisable value. It MUST NOT call activities,
  schedule timers, send signals that reach other workflows,
  open child workflows, or otherwise issue durable commands.

## Query response contract

`Workflow\V2\Support\QueryResponse::execute()` is the single
canonical HTTP response encoder for v2 queries. Consumers that
surface queries over HTTP (server, cloud control plane,
webhooks path) MUST route through it so the status-code
contract stays consistent.

Response codes:

- **200** — the query executed and returned a value. The
  payload carries `query_name`, `workflow_id`, `run_id`,
  `target_scope`, and `result`.
- **409** — the query cannot execute right now. The payload
  carries `query_name`, `workflow_id`, `run_id`, `target_scope`,
  and `message`. When raised through
  `WorkflowExecutionUnavailableException` the payload also
  carries `blocked_reason`. This code covers both the "workflow
  definition unavailable" gate reason and any other generic
  `LogicException` (for example, "query not declared on run")
  raised before replay reaches the handler body.
- **422** — the query arguments failed validation. The payload
  carries `query_name`, `workflow_id`, `run_id`, `target_scope`,
  `message`, and `validation_errors`. The `validation_errors`
  structure is the exception's `validationErrors()` array.

Consumers MAY translate these to transport-native status codes
(HTTP, gRPC, queue-worker response) but MUST preserve the
three-way boundary between success, temporary unavailability,
and argument validation failure. Merging 409 and 422 into one
status code is a protocol-level breaking change.

The `target_scope` field threads the scoping context supplied by
the caller (for example `'run'` versus `'instance-current'`) so
downstream clients can distinguish a query against a specific
run from a query against the current run for an instance.

## Non-durability guarantees

A successful query MUST leave zero durable state behind.
Specifically:

- no row is written to `workflow_history_events`,
  `workflow_commands`, `workflow_updates`, `workflow_tasks`,
  `workflow_activity_executions`, `workflow_activity_attempts`,
  `workflow_timers`, `workflow_links`, `workflow_child_calls`,
  `workflow_messages`, `workflow_memos`, `workflow_search_attributes`,
  or any backfill/repair table as a consequence of a query,
- no Laravel job is enqueued,
- no lifecycle hook fires (webhook dispatch lives in
  `Workflow\V2\Webhooks` for the workflow's own lifecycle; query
  invocation does NOT trigger a lifecycle hook, even when the
  query body runs through a successful replay),
- no wake notification is signalled on the
  `LongPollWakeStore` channels,
- `WorkflowRunSummary` is not re-projected,
- the `workflow_runs` row's `status`, `started_at`, `closed_at`,
  and lifecycle columns are untouched,
- any payload envelope codec is read in order to decode stored
  values but is never written through.

A query that raises fails the request without producing any of
the above writes. Consumers that observe persisted state change
after a query has executed MUST file this as a v2 bug, not a
product design gap.

Live-debug surfaces must carry the same non-durability
guarantee. Reading a run through Waterline, through
`dw run describe`, through `GET` endpoints on the server HTTP
API, or through the cloud observability repository MUST NOT
mutate the run. Any write-through observability path that reads
`workflow_run_summaries` or similar projection tables is part of
the projection layer, not the live-debug surface, and is
governed by the projector idempotency contract.

## Command-dispatch suppression

`Workflow\V2\Workflow::setCommandDispatchEnabled(bool)` toggles
the in-process flag that silences dispatch from authoring code.
While the flag is false:

- `ChildWorkflowHandle::signalWithArguments()` and the
  `__call()` alias return `null` without sending a signal.
- `Workflow::children()` returns handles constructed with the
  same flag so any child signalling attempt through the handle
  returns `null`.
- Activity calls, timer starts, child workflow starts, signal
  dispatches to non-handles, update dispatches, side effects,
  and version markers made by authoring code inside the
  replay continue to be intercepted by the executor's normal
  replay machinery, which does not emit new commands. Replay
  suppresses new commands on its own path; this flag is the
  extra safeguard for the handle-based signal dispatch that
  does NOT route through the executor.

Callers MUST NOT rely on `setCommandDispatchEnabled(false)` as
a general sandbox. Query replay is side-effect-free because it
runs inside `QueryStateReplayer::replayState()` and because the
authoring code itself is required to be deterministic and
side-effect-free by Phase 1. The flag closes the specific
signalling seam that handles expose.

The flag is set by:

- `Workflow\V2\Support\QueryStateReplayer::query()` immediately
  after replaying history, before invoking the query method.
- `Workflow\V2\Support\QueryStateReplayer::replayState()` when
  the final replay state is returned to a non-query caller such
  as a lineage view.
- `Workflow\V2\Support\WorkflowExecutor` during update-handler
  replay inside a workflow task. The executor sets the flag to
  `false` before replaying `UpdateApplied` events and restores
  it to `true` in a `finally` block so the post-replay decision
  path can dispatch commands again.

## Live-debug surfaces

The operator-facing live-debug surfaces bound by this contract:

- **Waterline run detail** — renders `RunLineageView`,
  `RunWaitView`, `HistoryTimeline`, and child lineage blocks
  from durable state. Waterline MUST NOT synthesize authoring
  replay, call queries, or dispatch commands to render the
  view.
- **CLI `dw run describe` / `dw run list`** — reads the same
  projected and history surfaces as Waterline. Argument
  completion and filtering are client-side.
- **Server HTTP run APIs** — `GET` endpoints on the standalone
  server that serve run detail, history pagination, and
  child-lineage queries. These routes are explicitly read-only
  and MUST NOT carry side-effect semantics.
- **`OperatorMetrics::snapshot()` and
  `OperatorQueueVisibility::forNamespace()` /
  `::forQueue()`** — aggregate roll-ups that read durable tables
  and return counts. The snapshots are deterministic functions
  of committed state at read time.
- **Cloud observability repository** — the control-plane-split
  contract names the binding that produces these snapshots for
  cloud callers. Its read surface inherits this contract; its
  write surface is governed by the projection contract.

Live-debug surfaces MAY cache read results in a short-lived,
per-request memo. They MUST NOT write cache entries or
invalidation markers into durable tables; any caching layer
must be observability-side and expire on its own clock.

## Query handler authoring rules

Workflow authors writing a query handler MUST:

- declare the method with `#[QueryMethod]` on the workflow
  class. Non-`#[QueryMethod]` methods cannot be queried;
  `WorkflowDefinition::hasQueryMethod()` returns false and the
  stub raises `LogicException`.
- keep the body effect-free. Queries do not send signals, call
  activities, start children, schedule timers, upsert memos or
  search attributes, or mutate any external state. Queries MAY
  read fields already populated on the workflow object (they
  reflect the replayed state).
- return a serialisable value. The codec used to serialise the
  return payload is the payload envelope attached to the call,
  not the workflow run's `payload_codec`.
- tolerate being invoked multiple times concurrently. Two
  queries against the same run may race. The replay machinery
  is read-only, so concurrent queries do not need to coordinate.

Workflow authors MUST NOT:

- perform IO. The query body runs in the caller's request
  context; blocking IO would block the caller.
- raise for valid arguments to signal business state. Queries
  that want to report a structured state MUST return a
  value. Exceptions bubble up as `500`-class errors and are not
  part of the 200/409/422 contract.
- relay updates back into the workflow. Queries that need to
  "do something" must dispatch a signal or update separately;
  a query is not an RPC for workflow mutation.

## Query error taxonomy

The error boundary consumers must respect:

| Condition | Exception | HTTP code | Payload reason |
|---|---|---|---|
| Run not started yet | `LogicException` | 409 | generic message |
| Method not declared as a query | `LogicException` | 409 | generic message |
| Arguments fail validation | `InvalidQueryArgumentsException` | 422 | `validation_errors` |
| Workflow definition not resolvable | `WorkflowExecutionUnavailableException` | 409 | `blocked_reason = workflow_definition_unavailable` |
| Query body raised | underlying throwable | 500 | propagated |

The 500 case is explicitly out of scope for this contract.
Queries that throw represent an application bug and follow the
host's regular exception handling path; they do not carry a
`target_scope` wrapper.

## Consumers bound by this contract

The canonical consumers that invoke queries or render live-debug
surfaces:

- `Workflow\V2\WorkflowStub` — client-side query invocation.
- `Workflow\V2\Support\QueryStateReplayer` — replay engine for
  queries.
- `Workflow\V2\Support\QueryResponse` — HTTP response encoder.
- `Workflow\V2\Webhooks` — lifecycle hook dispatch that may
  surface query results.
- `Workflow\V2\Support\WorkflowExecutionGate` — blocked-reason
  gate.
- `Workflow\V2\Support\WorkflowDefinition` — query-method
  declaration authority.
- `Workflow\V2\Support\RunLineageView` — Waterline live-debug
  lineage surface.
- `Workflow\V2\Support\RunWaitView` — Waterline live-debug
  wait-cause surface.
- `Workflow\V2\Support\HistoryTimeline` — Waterline
  history-timeline surface.
- `Workflow\V2\Support\OperatorMetrics` and
  `Workflow\V2\Support\OperatorQueueVisibility` — live-debug
  aggregate roll-ups.

Any new consumer that issues a query or renders a live-debug
surface MUST route through the canonical classes above rather
than re-implementing replay, argument validation, or response
code translation.

## Test strategy alignment

- `tests/Feature/V2/V2QueryWorkflowTest.php` pins the query
  invocation, argument validation, and unavailability boundaries
  end-to-end.
- `tests/Unit/V2/WorkflowFacadeTest.php` and
  `tests/Unit/V2/WorkflowFiberContextTimeTest.php` cover the
  replay-time fiber context that queries share with the main
  executor.
- `tests/Feature/V2/V2RunDetailViewTest.php` pins the live-debug
  run-detail surface.
- `tests/Feature/V2/V2OperatorMetricsTest.php` and
  `tests/Feature/V2/V2OperatorQueueVisibilityTest.php` pin the
  aggregate live-debug surfaces.
- This document is pinned by
  `tests/Unit/V2/QueryAndLiveDebugDocumentationTest.php`. A
  future change that renames, removes, or narrows any named
  guarantee (non-durability, command-dispatch suppression, the
  three-way response code boundary, the blocked-reason code
  value, or the query-authoring rules) must update the pinning
  test and this document in the same change so the contract
  does not drift silently.

## What this contract does not yet guarantee

The following are explicitly deferred and must not be assumed:

- Queries are not cached by the engine. A second identical
  query against the same run re-runs replay. A caching layer
  would be a host-level observability policy.
- Queries do not snapshot state. A query that observes state X
  at time T is not guaranteed to observe state X again after
  more history has been committed. Callers that need a
  point-in-time snapshot must copy the result out.
- Streaming or subscription-based queries are not part of v2.
  Clients that want update streams must use durable message
  streams or webhook subscriptions.
- Queries do not cross run boundaries. A query against a parent
  run does not transparently replay children; consumers must
  query children directly or read the child lineage surface.
- Fanout queries across many runs are a host-level concern. The
  contract covers per-run invocation only.

## Changing this contract

A change to any named guarantee in this document is a protocol-level
change for the purposes of `docs/api-stability.md` and downstream
SDKs. Reviewers should treat unmotivated changes to the language
above as breaking changes and require explicit cross-SDK
coordination before merge. Phase 1 (execution guarantees) owns the
upstream boundary between durable and non-durable operations; any
change to this contract must preserve the authority boundary Phase 1
froze.
