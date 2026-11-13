# Deadline-triggered cleanup (draft contract)

Draft contract for [workflow issue 863](https://github.com/durable-workflow/workflow/issues/863),
following General's 2026-11-04 decision on that issue. Nothing here is shipped.
The existing hard timeout stays the default and keeps its current meaning.

## Problem

A run or execution deadline closes the run with `WorkflowTimedOut` before any
workflow code runs (`WorkflowExecutor::timeoutIfDeadlineExpired()` →
`timeoutRun()`). A saga that needs to compensate when its business deadline
passes has no turn to do it: `finally` blocks and `catch` handlers never run.

## Admission: two clocks fixed at start

An author opts in per start with a finite cleanup allowance. The allowance
applies to whichever hard timeouts the start already sets.

```php
WorkflowStub::make(OrderSaga::class, 'order-42')->start(
    $order,
    new StartOptions(
        executionTimeoutSeconds: 70,     // hard cap, unchanged meaning
        timeoutCleanupSeconds: 10,       // proposed: the last 10 s are reserved for cleanup
    ),
);
```

`StartOptions::withTimeoutCleanup(?int $seconds)` would be the fluent form,
alongside the existing `withExecutionTimeout()` and `withRunTimeout()`.

- The **hard deadline** is exactly the existing `execution_deadline_at` or
  `run_deadline_at`. The allowance never extends it.
- The **work deadline** is `hard deadline − allowance`. For the example above,
  that is 60 seconds of ordinary work.
- The **cleanup deadline** is `min(work deadline + allowance, every applicable
  hard deadline)`. With the derived work deadline this equals the earliest hard
  deadline, and stating it as a minimum keeps it correct when both an execution
  and a run deadline apply.

Validation at admission, before any write:

- The allowance is an integer from 1 to 86,400 seconds.
- At least one hard timeout must be set.
- The allowance must be smaller than every hard timeout it applies to, so the
  work deadline is in the future.

The allowance is recorded in `WorkflowStarted` (`timeout_cleanup.allowance_seconds`,
plus the derived `work_deadline_at` per timeout kind), which is immutable. Native
reads the admitted values from `WorkflowStarted` and adds no column (server review
on [workflow pull request 1122](https://github.com/durable-workflow/workflow/pull/1122)).
Embedded also stores the earliest derived work deadline in
`workflow_runs.timeout_cleanup_work_deadline_at`, so the watchdog can wake an idle
run at that time; the column is nullable and set only for opted-in runs (General,
2026-11-13). Describe and run detail show them.

### Inheritance

| Path | Execution clocks | Run clocks |
| --- | --- | --- |
| Continue-as-new | Inherited unchanged. The successor keeps the original execution work deadline and hard cap. | A new run gets a new run deadline from its own run timeout, as today, and its work deadline is derived from it with the same allowance. |
| Retry of a timed-out run | Not applicable: a timed-out run is final. | |
| Redrive | The successor keeps the source's execution deadline (existing rule) and allowance. | Derived per run. |
| Child workflow | A child has its own timeouts and its own opt-in. A parent's cleanup can request cooperative cancellation of its children, but never grants a child time beyond the child's own caps. | |

Late detection, task retries, worker replacement, lease renewal and duplicate
delivery never refresh any clock. Every deadline is computed from admitted
values, not from when something noticed it.

## Transition: entering timeout cleanup once

When the work deadline passes on an opted-in run that is still open and has
not entered cleanup, the engine (watchdog, task claim or completion, whichever
notices first) does the following in one transaction under the run lock:

1. Records `WorkflowTimeoutCleanupStarted`:
   `{timeout_kind: execution|run, work_deadline_at, cleanup_deadline_at,
   hard_deadline_at, detected_at}`. It is recorded once per run.
2. Fences ordinary progress. New commands from workflow code are still
   accepted, because cleanup needs activities, timers and children. But the
   run's `cancellation_deadline_at` is set to the cleanup deadline, so the
   existing cleanup-deadline enforcement at task completion and lease renewal
   ([workflow issue 595](https://github.com/durable-workflow/workflow/issues/595)) applies unchanged.
3. Delivers the interruption through the existing cooperative-cancellation
   path. Workflow code receives a `WorkflowTimeoutCleanupRequested` exception,
   which extends `WorkflowCancellationRequestedException`, with a
   `CancellationContext` whose optional top-level `cause` is `timeout` and
   whose existing `cleanup_deadline_at` is the cleanup deadline. The timeout
   kind and work deadline are recorded on `WorkflowTimeoutCleanupStarted`, not
   in the context. `cause` is never inherited by descendants, scoped or not,
   and an explicit `cause: cancellation` round-trips unchanged. Existing
   `catch (WorkflowCancellationRequestedException)` compensation code
   therefore runs without changes. `cancellationShield()` scopes and the
   operation cancellation policies behave as they do for a cooperative
   cancellation request.

A user cancellation request that arrives during timeout cleanup is recorded
as its own command, rejected with reason `timeout_cleanup_running` and naming
the cleanup request and its deadline. It doesn't restart cleanup or move its
deadline, and the run still ends timed out. A timeout that arrives
during an earlier cooperative cancellation doesn't enter timeout cleanup:
the earlier request already owns cleanup, its deadline is capped by the hard
deadline, and the final outcome is the timeout if the hard deadline wins.

## Final outcome: always timed out

The business outcome is `WorkflowTimedOut` (`status: failed`,
`closed_reason: timed_out`), with the timeout kind and message as today, even
if compensation succeeds. The terminal event adds an independent cleanup
outcome:

```json
"timeout_cleanup": {
  "outcome": "completed | failed | expired | preempted",
  "started_at": "…",
  "cleanup_deadline_at": "…",
  "finished_at": "…",
  "failure_id": "… (failed only)"
}
```

| How cleanup ends | `outcome` | Notes |
| --- | --- | --- |
| Workflow code returns, or rethrows the timeout exception, before the cleanup deadline | `completed` | The return value is discarded and never becomes the run's output. |
| Workflow code throws any other exception before the cleanup deadline | `failed` | The exception is recorded as a failure row linked by `failure_id`. The run is still timed out, not failed. |
| The cleanup deadline passes | `expired` | The engine closes the run, as today's hard timeout does, and settles open work as [workflow issue 1117](https://github.com/durable-workflow/workflow/issues/1117) defines. |
| `terminate()`, hard `cancel()`, or a hard deadline before the cleanup deadline | `preempted` | The terminal event is the preempting one (`WorkflowTerminated`, `WorkflowCancelled`) and carries `timeout_cleanup.outcome: preempted`. |

An unavailable worker can't guarantee cleanup. If no worker runs the cleanup
turn before the cleanup deadline, the outcome is `expired`.

Run detail, describe, Waterline and history export show the cleanup state. While
it is open, the liveness state is the existing `cancellation_cleanup_running`
with `cause: timeout`. After that, they show the recorded outcome.

## Defaults and compatibility

- Without `withTimeoutCleanup()`, nothing changes: the hard timeout closes
  the run before workflow code runs.
- Histories recorded before this feature replay unchanged.
- The new history fields are additive. `WorkflowTimeoutCleanupStarted` is a
  new event type, so readers that don't know it refuse it as an unknown event.
  Service-mode SDKs therefore need the capability below before Server sends
  it.
- Service mode: Server advertises `timeout_cleanup` (`{supported: true,
  version: 1}`) only when it implements this contract, and a service worker
  advertises it only when its SDK delivers the typed timeout interruption.
  The check happens at admission only: Server refuses a start that asks for
  timeout cleanup on a task queue with no worker that advertises it, with a
  typed reason, and never silently falls back to a hard timeout. Delivery
  reuses the cooperative-cancellation delivery with the added cause and
  deadline fields; there is no new route.

## Vectors

Each vector names the admitted values, what happens, and the expected history
tail and outcome. `T` is the start time.

| # | Admission | Scenario | Expected |
| --- | --- | --- | --- |
| 1 | execution 70 s, cleanup 10 s | Workflow code is still running at T+60 and compensates by T+65 | `WorkflowTimeoutCleanupStarted{kind: execution, work: T+60, cleanup: T+70}` … `WorkflowTimedOut{timeout_cleanup.outcome: completed}` |
| 2 | same | Cleanup throws `RuntimeException` at T+62 | `WorkflowTimedOut{timeout_cleanup.outcome: failed, failure_id}`, run status failed, closed_reason timed_out |
| 3 | same | Cleanup still running at T+70 | `WorkflowTimedOut{timeout_cleanup.outcome: expired}`, and open activities and timers are settled before it |
| 4 | same | `terminate()` at T+63 | `WorkflowTerminated{timeout_cleanup.outcome: preempted}` |
| 5 | same | Watchdog first notices at T+67 | Cleanup starts with `detected_at: T+67`, `cleanup_deadline_at: T+70` (3 s left) |
| 6 | same | The workflow task holding the cleanup turn is retried or its worker is replaced | No second `WorkflowTimeoutCleanupStarted`, same deadlines |
| 7 | execution 70 s, cleanup 10 s | Continue-as-new at T+30 | The successor's execution work deadline is still T+60 and its hard cap T+70 |
| 8 | run 70 s, cleanup 10 s | Continue-as-new at T+30 | The successor's run work deadline is T+30+60 and its run hard cap T+30+70 |
| 9 | execution 70 s, run 40 s, cleanup 10 s | Run reaches T+30 | Cleanup starts for kind `run`, and `cleanup_deadline_at` is min(T+40, T+70) = T+40 |
| 10 | execution 70 s, cleanup 10 s | `requestCancellation()` at T+20 with a 120 s cleanup, still running at T+70 | No `WorkflowTimeoutCleanupStarted`, and the hard timeout wins: `WorkflowTimedOut` with the cooperative cancellation recorded as preempted |
| 11 | none | Run reaches its hard deadline | Unchanged: `WorkflowTimedOut` before workflow code runs, with no `timeout_cleanup` key |
| 12 | execution 10 s, cleanup 10 s | Start | Refused at admission: the allowance must be smaller than the timeout |

## Review decisions

- **Server (2026-11-13, on [workflow pull request 1122](https://github.com/durable-workflow/workflow/pull/1122)):** keep `WorkflowTimeoutCleanupRequested` as a
  subtype of the cancellation exception. Reuse the cooperative-cancellation
  delivery with cause and deadline fields, with no new route. No new
  `workflow_runs` column; native reads `WorkflowStarted.timeout_cleanup`. The
  capability check is admission-only. Reuse `cancellation_cleanup_running`
  with a `cause`.

- **General, as SDK owner (2026-11-13, on [workflow pull request 1122](https://github.com/durable-workflow/workflow/pull/1122)):** `cause` is the
  cross-SDK discriminator. Python uses a `BaseException`-rooted subclass of
  the SDK's cancellation-requested type, never `asyncio.CancelledError`. Rust
  adds no new error variant; a `cause` enum on `CancellationRequest` means
  existing `CooperativeCancellationRequested` arms also catch timeouts.

- **General (2026-11-13):** `failed` cleanup keeps the run's
  `failure_category` as `timeout`, with the cleanup failure linked through
  `timeout_cleanup.failure_id`, the same as compensation failures (initiating
  failure first, compensation failure attached). Describe, the history
  export and the failure list say "cleanup failed" plainly, so an operator
  doesn't read it as a clean timeout. Redrive eligibility follows the
  timeout category.

All open questions are decided. The embedded implementation follows this
contract; native and the SDKs follow through the `timeout_cleanup` capability.
