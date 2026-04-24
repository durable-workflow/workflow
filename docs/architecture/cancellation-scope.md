# Workflow V2 Cancellation-Scope Contract

This document freezes the v2 contract for how workflow cancellation
and termination propagate across the pieces of an in-flight run:
open tasks, open activity executions, open timers, and open child
workflows. It names the scope boundary (cancel is run-level, not
call-level), the two command types (`cancel` and `terminate`), the
heartbeat-based cooperation protocol with activity workers, the
parent-close policy that decides what happens to children when a
parent closes, and the typed history events that make the whole
propagation observable.

The guarantees below apply to the `durable-workflow/workflow` package
at v2 and to every host that embeds it or talks to it over the
worker protocol. A change to any named guarantee is a protocol-level
change and must be reviewed as such, even if the class that
implements it is `@internal`.

This contract builds on the semantics frozen in
`docs/architecture/execution-guarantees.md` (Phase 1). Phase 1 names
cancel and terminate as external commands with at-least-once delivery
and durable exactly-once typed history; this document extends that
language into the full run-level scope, the propagation rules, the
cooperative cancellation protocol for activities, and the
ParentClosePolicy enforcement path for children. It also builds on
the child-outcome source-of-truth contract
(`docs/architecture/child-outcome-source-of-truth.md`) which
describes how a cancelled child's outcome is observed by the parent.

## Scope

The contract covers:

- **cancel and terminate commands** — the two v2 external command
  types that close a run before its authoring code reaches a
  natural terminal state.
- **run-level scope** — the guarantee that cancel and terminate
  affect the entire run rather than a specific activity call,
  timer, or child invocation.
- **propagation to open work** — the rules that convert open tasks,
  activity executions, timers, and child links into their
  cancelled/terminated counterparts when the parent run closes.
- **cooperative activity cancellation** — the heartbeat-based
  protocol by which activity workers learn their activity has been
  cancelled and are expected to stop cooperatively.
- **parent-close policy** — the per-child-call policy that decides
  whether a still-running child is abandoned, asked to cancel, or
  terminated when the parent closes.
- **typed history surface** — the complete set of typed history
  events the engine emits so operator tools and SDK consumers can
  render the propagation path deterministically.

It does not cover:

- the wire format of cancel and terminate commands. That is
  frozen in `docs/api-stability.md`.
- per-activity retry policies. Those belong to the execution-
  guarantees contract; cancellation interrupts a retrying activity
  through the same propagation rules as a first-attempt activity.
- in-authoring cancellation scopes (nested try/catch-style
  cancellation islands, Temporal-style `CancellationScope`). See
  the non-goals section — v2 does not support per-call
  cancellation scopes; cancel is run-level only.
- the cloud control plane routing of cancel commands. That is
  `docs/architecture/control-plane-split.md` authority-boundary
  territory.

## Terminology

- **Cancel** — the cooperative close path. The engine throws
  `Workflow\V2\Exceptions\WorkflowCancelledException` on the parent
  fiber, records a `CancelRequested` event when the command is
  received, and a terminal `WorkflowCancelled` event when the run
  closes. `WorkflowCancelledException` extends `Error` rather than
  `Exception` so a generic `catch (\Exception)` block cannot
  accidentally swallow a cancellation.
- **Terminate** — the forceful close path. The engine throws
  `Workflow\V2\Exceptions\WorkflowTerminatedException`, records
  `TerminateRequested`, and a terminal `WorkflowTerminated` event.
  Terminate does not give authoring code or activities a chance to
  cooperate; it closes the run immediately.
- **Run-level scope** — the property that a cancel or terminate
  command closes the entire workflow run. Propagation to child
  runs follows the per-call `ParentClosePolicy`; there is no
  third option that closes just one call within the parent run.
- **Cooperative cancellation** — the path where an activity worker
  learns that its activity was cancelled (via heartbeat response
  flags) and stops voluntarily, then the engine records
  `ActivityCancelled` through the normal outcome path.
- **Parent-close policy** — the `Workflow\V2\Enums\ParentClosePolicy`
  value attached to every child call. Decides whether the child is
  `Abandon`, `RequestCancel`, or `Terminate` when the parent
  closes.

## Command types

`Workflow\V2\Enums\CommandType` names the two close-driven command
types that enter this contract:

- `CommandType::Cancel` (`'cancel'`) — cooperative close request.
- `CommandType::Terminate` (`'terminate'`) — forceful close request.

Both command types flow through the same external-command ingress
on `WorkflowStub::attemptCancel()` / `attemptTerminate()`:

- an incoming command row is recorded with the command payload,
  the optional `reason` string, and the caller's idempotency key.
  Commands are at-least-once by the Phase 1 contract.
- the command is applied inside a run-scoped transaction. The
  command applies exactly-once at the durable state layer; a
  retried duplicate command row is dedupe'd at ingress.
- the command is marked applied (`applied_at`) and the terminal
  history event is recorded.

The two command types produce different terminal state:

| Command | Terminal `RunStatus` | Terminal event | Failure exception class | `FailureCategory` | `propagation_kind` |
|---|---|---|---|---|---|
| `cancel` | `Cancelled` | `WorkflowCancelled` | `Workflow\V2\Exceptions\WorkflowCancelledException` | `Cancelled` | `cancelled` |
| `terminate` | `Terminated` | `WorkflowTerminated` | `Workflow\V2\Exceptions\WorkflowTerminatedException` | `Terminated` | `terminated` |

`CommandOutcome::Cancelled` / `CommandOutcome::Terminated` name
the terminal command outcome used in the `CommandResolved`
history event that closes each command.

## Run-level scope

Cancel and terminate are **run-level**, not **call-level**. When
the engine applies a cancel:

1. the run row is force-updated with `status = Cancelled`,
   `closed_reason`, `closed_at`, and `last_progress_at`,
2. a `WorkflowFailure` row is recorded with
   `source_kind = 'workflow_run'`, `source_id = <run_id>`,
   `propagation_kind = 'cancelled'`,
   `failure_category = 'cancelled'`, and `exception_class`
   pointing at `WorkflowCancelledException`,
3. a `WorkflowCancelled` terminal history event is recorded
   with the `failure_id`, `failure_category`, `closed_reason`,
   `exception_class`, `message`, and optional `reason`,
4. the command is marked applied,
5. `ParentClosePolicyEnforcer::enforce($run)` runs to propagate
   to children per their recorded `parent_close_policy`,
6. parent-resume tasks are created so the parent run (if any)
   can observe the cancellation of THIS run via its own
   `ChildRunCancelled` event,
7. `RunSummaryProjector::project()` is re-run so operator
   surfaces reflect the terminal state.

A terminate follows the same six steps with `Terminated` in place
of `Cancelled` everywhere. The only observable difference between
cancel and terminate at the propagation layer is the name of the
terminal state; they share the open-work cleanup path described
below.

There is no v2 API for cancelling a single activity call without
cancelling the whole run. Activity heartbeats can signal
`cancel_requested = true` to an activity worker because the RUN
is cancelled; a selective "cancel just this activity" surface is
outside this contract.

## Propagation to open work

When a cancel or terminate command closes a run, the engine
immediately cleans up the run's in-flight work:

### Open tasks

Every `WorkflowTask` for the run whose status is `Ready` or
`Leased` (except the command-applying task itself when the cancel
is being handled inside a workflow-task commit) is force-updated
to `TaskStatus::Cancelled` with `lease_expires_at` cleared. The
task cancellation does not produce its own typed history event;
it is an infrastructure cleanup that makes the terminal
`WorkflowCancelled` / `WorkflowTerminated` event the authoritative
closure marker.

### Open activity executions

Every `ActivityExecution` for the run whose status is `Pending`
or `Running` is force-updated to `ActivityStatus::Cancelled` with
`closed_at` populated, and
`Workflow\V2\Support\ActivityCancellation::record()` writes the
typed `ActivityCancelled` history event for that execution. If
the open execution's current attempt is claimed by a worker, the
next heartbeat from that worker returns `cancel_requested: true`
so the worker can cooperatively stop.

### Open timers

Every `WorkflowTimer` whose status is `Pending` is
force-updated to `TimerStatus::Cancelled` and
`Workflow\V2\Support\TimerCancellation::record()` writes the
typed `TimerCancelled` history event.

### Open child links

Child link cleanup is not in this list. Children are handled by
`ParentClosePolicyEnforcer::enforce()` in step 5 of the run
closure, which consults the per-child `parent_close_policy`
rather than blanket-cancelling every child.

## Cooperative cancellation for activities

Activity workers cooperate with cancellation through the heartbeat
protocol. The contract:

- Each activity heartbeat response carries a
  `cancel_requested: bool` flag. The flag is `true` when the
  owning activity execution has been marked cancelled by run
  closure, by an overlap policy, or by any other cancellation
  path that runs between heartbeats.
- The contract requires activity workers to check
  `cancel_requested` and stop cooperatively. A worker that keeps
  running after `cancel_requested = true` is free to do so — the
  engine has already committed the cancellation and any
  `complete`/`fail` call from that worker will be rejected
  because the execution is no longer in `Running`.
- A cooperating worker that stops cleanly reports the activity
  through `fail` with a cancellation-flavoured failure; the
  engine routes that through the normal outcome recorder which
  preserves the `ActivityCancelled` event already written.
- Activity workers MUST NOT use the `cancel_requested` signal to
  skip recording the outcome. The heartbeat-driven cancellation
  is cooperative but the outcome path is durable.

## Parent-close policy enforcement

`Workflow\V2\Enums\ParentClosePolicy` has three values and
defines how a closing parent affects each of its still-running
child calls:

- `ParentClosePolicy::Abandon` (`'abandon'`) — default. The child
  continues running independently after the parent closes. No
  further action is taken.
- `ParentClosePolicy::RequestCancel` (`'request_cancel'`) — the
  enforcer calls `WorkflowStub::attemptCancel($reason)` on the
  child with a reason string of the form
  `"Parent workflow closed (<closed_reason>); parent-close policy: <policy>."`.
- `ParentClosePolicy::Terminate` (`'terminate'`) — the enforcer
  calls `WorkflowStub::attemptTerminate($reason)` on the child
  with the same reason format.

Enforcement iterates every `workflow_links` row for the parent
run with `link_type = 'child_workflow'` and
`parent_close_policy != 'abandon'`:

- terminal children (child `RunStatus` is `Completed`, `Failed`,
  `Cancelled`, or `Terminated`) are skipped; the policy cannot
  re-close an already-closed child,
- non-terminal children receive the matching cancel or terminate
  command through `WorkflowStub::attemptCancel()` /
  `attemptTerminate()`,
- on success, a `ParentClosePolicyApplied` history event is
  recorded on the PARENT run with `child_instance_id`,
  `child_run_id`, `policy`, and `reason`,
- on failure (stub load error, command rejected because the
  child is already terminal, or any other exception), a
  `ParentClosePolicyFailed` event is recorded on the parent with
  the same fields plus `error`, the enforcement continues to the
  next child, and a warning is logged.

Enforcement is best-effort per child but exhaustive per link.
One child's failure does not abort the policy for other children.

The policy is frozen per child call at scheduling time via the
`parent_close_policy` column on `workflow_links`. The
`ChildRunStarted` history event carries the policy verbatim as
of the Phase 1 contract so replay sees it, and every subsequent
`ChildRunStarted` on a continued child run still carries the
original policy.

## History event surface

The typed history events used by this contract:

### On the parent run

- `CancelRequested` — command ingress for a cooperative close.
  Payload: `workflow_command_id`, `workflow_instance_id`,
  `workflow_run_id`, `command_type = 'cancel'`, optional
  `reason`.
- `WorkflowCancelled` — terminal close event for a cancel.
  Payload: `workflow_command_id`, `workflow_instance_id`,
  `workflow_run_id`, `failure_id`, `failure_category = 'cancelled'`,
  `closed_reason`, `exception_class`, `message`, optional
  `reason`.
- `TerminateRequested` — command ingress for a forceful close.
  Payload shape mirrors `CancelRequested` with `command_type =
  'terminate'`.
- `WorkflowTerminated` — terminal close event for a terminate.
  Payload shape mirrors `WorkflowCancelled` with
  `failure_category = 'terminated'`.
- `ActivityCancelled` — one per open activity execution that
  was cleaned up. Recorded by `ActivityCancellation::record()`.
- `TimerCancelled` — one per open timer that was cleaned up.
  Recorded by `TimerCancellation::record()`.
- `ParentClosePolicyApplied` — one per successfully-propagated
  child with policy `RequestCancel` or `Terminate`. Payload:
  `child_instance_id`, `child_run_id`, `policy`, `reason`.
- `ParentClosePolicyFailed` — one per child where the policy
  could not be applied. Payload: `child_instance_id`,
  `child_run_id`, `policy`, `reason`, `error`.
- `ChildRunCancelled` / `ChildRunTerminated` — recorded later on
  the parent when the now-cancelled child commits its own
  terminal state. These flow through the child-outcome
  source-of-truth precedence; see that contract.

### On the child run

- The child's own `CancelRequested` / `WorkflowCancelled` (or
  `TerminateRequested` / `WorkflowTerminated`) events record the
  child-side view of the propagated close. Their `reason` field
  carries the parent-perspective reason string.
- `ActivityCancelled` / `TimerCancelled` — propagation across
  the child's open work follows the same rules as the parent.

## Consumers bound by this contract

The canonical consumers of the cancellation scope and
propagation contract:

- `Workflow\V2\WorkflowStub::attemptCancel()` /
  `attemptTerminate()` — external-command ingress and terminal
  event authors.
- `Workflow\V2\Support\WorkflowExecutor` — run-level cancel
  propagation for timeouts (uses the same cleanup path with
  `FailureCategory::Timeout` in place of Cancelled/Terminated).
- `Workflow\V2\Support\ActivityOutcomeRecorder` — activity
  outcome recorder that respects cancelled executions.
- `Workflow\V2\Support\ActivityCancellation` — typed event
  writer for activity cancellation.
- `Workflow\V2\Support\TimerCancellation` — typed event writer
  for timer cancellation.
- `Workflow\V2\Support\ParentClosePolicyEnforcer` — parent-close
  policy enforcement.
- `Workflow\V2\Support\DefaultActivityTaskBridge` — activity
  heartbeat surface that carries `cancel_requested` back to
  workers.
- `Workflow\V2\Support\ChildCallService` — parent-side tracking
  of child `cancel_requested` statistics.
- `Workflow\V2\Support\RunLineageView` and
  `Workflow\V2\Support\RunWaitView` — operator surfaces that
  render the propagation state.
- `Workflow\V2\Support\RunSummaryProjector` — aggregate
  projection that counts cancelled/terminated state per run.

Any new consumer that renders or mutates cancellation state MUST
route through the canonical classes above so the typed-history
contract stays consistent.

## Non-goals

The following are explicitly outside v2 and will not become
features without a separate roadmap issue and a protocol-level
change review:

- **Per-call cancellation scopes.** v2 does not support nested
  `try { ... } catch (Cancelled)` scopes that re-throw to
  authoring code without closing the run. Cancel is run-level;
  authoring code that wants "stop doing X but keep the run
  alive" must model that as a signal-driven state transition,
  not a cancel.
- **Cancelling a specific activity call.** The activity
  heartbeat `cancel_requested` flag is a consequence of a
  run-level cancel, not a user-facing "cancel activity N" API.
  Operators who want selective cancellation must model it as an
  update that instructs the workflow to fail the specific
  call.
- **Cancel-then-continue.** A cancelled run cannot be resumed.
  The only continuation path from a cancelled run is an
  external start of a new run (new `workflow_run_id`), which is
  not a continuation of the cancelled one.
- **Silent child abandonment.** A parent-close policy of
  `Terminate` or `RequestCancel` always records either
  `ParentClosePolicyApplied` or `ParentClosePolicyFailed` per
  child. The engine never silently drops a policy outcome.
- **Overlapping cancel/terminate.** A run that has already
  accepted a cancel cannot be escalated to a terminate through a
  second command; the first terminal event wins. Operators who
  need stronger guarantees must issue terminate directly
  instead of cancel.

## Test strategy alignment

- `tests/Feature/V2/V2ParentClosePolicyTest.php` exercises the
  parent-close policy enforcement end-to-end across the three
  policy values.
- `tests/Feature/V2/V2CompatibilityWorkflowTest.php` and
  `tests/Feature/V2/V2ActivityTimeoutTest.php` exercise the
  shared cleanup path that cancels open tasks, activity
  executions, and timers.
- `tests/Feature/V2/V2RunDetailViewTest.php` pins the operator
  rendering of cancellation and termination state.
- `tests/Unit/V2/ChildCallServiceTest.php` pins the
  `cancel_requested` per-child counter.
- This document is pinned by
  `tests/Unit/V2/CancellationScopeDocumentationTest.php`. A
  future change that renames, removes, or narrows any named
  guarantee (the run-level scope, the two command types, the
  parent-close policy values, the cooperative heartbeat flag,
  or the typed history surface) must update the pinning test
  and this document in the same change so the contract does not
  drift silently.

## Changing this contract

A change to any named guarantee in this document is a protocol-level
change for the purposes of `docs/api-stability.md` and downstream
SDKs. Adding a fourth parent-close policy, adding a new typed
history event for cancellation, or promoting cancel/terminate to a
new command type are protocol-level extensions that require explicit
cross-SDK coordination. Narrowing the current guarantees — for
example, turning the run-level scope into a call-level scope, or
dropping `ParentClosePolicyFailed` — is a breaking change and must
be rejected by reviewers without a corresponding roadmap issue.
