# Workflow V2 Local Activities Contract

This document is the workflow-owned runtime contract for v2 local
activities. A local activity is an activity invocation that runs inside
the workflow worker process that is currently executing the workflow
task. It keeps activity retry, timeout, heartbeat, visibility, and
history semantics, but bypasses ordinary activity-task queueing.

## Authoring API

Workflow code may invoke a local activity through either authoring
surface:

```php
use Workflow\V2\Support\LocalActivityOptions;
use function Workflow\V2\localActivity;

$result = localActivity(
    SendShortNotification::class,
    new LocalActivityOptions(
        maxAttempts: 3,
        startToCloseTimeout: 10,
        scheduleToCloseTimeout: 30,
        heartbeatTimeout: 5,
    ),
    $recipientId,
);
```

The static workflow facade exposes the same primitive:

```php
$result = Workflow::localActivity(SendShortNotification::class, $recipientId);
$result = Workflow::executeLocalActivity(SendShortNotification::class, $recipientId);
```

`LocalActivityOptions` accepts retry and timeout options that apply to
the local execution. It intentionally rejects `connection`, `queue`,
worker-session, and schedule-to-start routing options because no ordinary
activity task is admitted to a task queue.

## Execution Semantics

The workflow task that encounters `localActivity(...)` creates an
`activity_executions` row with `activity_options.execution_mode=local`
and `queue_bypassed=true`. It records `ActivityScheduled` and
`ActivityStarted` history with `payload.execution_mode=local` and
`payload.local_activity=true`, then instantiates the activity class in
the same PHP process and calls its activity entry method.

No `TaskType::Activity` workflow task is created. The worker process must
already contain the activity class and its dependencies. If the activity
returns, the runtime records `ActivityCompleted` and resumes the workflow
fiber with the decoded result. If the activity throws, times out, or is
cancelled, the runtime records the corresponding activity terminal event
and throws the deterministic replay exception back into workflow code.

History remains the replay authority. Query replay and cold workflow
replay do not call a local activity when a terminal local activity event
already exists; they read the recorded activity event just like ordinary
activity replay.

## Heartbeating

A running local activity owns the workflow task lease rather than an
activity task lease. At local attempt start the runtime renews the
workflow task lease and stores the lease on the `activity_attempts` row.
When activity code calls `$this->heartbeat($progress)`, the runtime:

- records `ActivityHeartbeatRecorded` with the local activity marker;
- updates `activity_executions.last_heartbeat_at`;
- updates the current `activity_attempts` row;
- renews the owning workflow task lease and run-summary lease
  projection.

Long-running local activity code must call `heartbeat()` often enough to
keep both its heartbeat timeout and the workflow task lease healthy.

## Timeouts

`startToCloseTimeout` limits one local attempt. `scheduleToCloseTimeout`
limits the whole local activity execution across all attempts. The
optional `heartbeatTimeout` limits the gap between recorded local
activity heartbeats.

Timeouts are enforced cooperatively at attempt start, activity heartbeat,
activity completion, and by the activity timeout sweeper. A retryable
local timeout schedules a workflow task with
`workflow_wait_kind=local_activity`; it does not create an activity task.
An exhausted timeout records `ActivityTimedOut` with the local marker and
wakes the parent workflow through a normal workflow task.

## Retry Attempts

Each local attempt creates an `activity_attempts` row and increments
`activity_executions.attempt_count`. A new attempt starts when:

- the original local call starts;
- a retry workflow task becomes available after backoff;
- cold replay finds a started local attempt without a terminal history
  event and schedules a replay attempt.

Retry policy is resolved from the activity class and local options using
the same retry-policy snapshot shape as ordinary activities. Backoff is
durable: retries are represented by a ready-at-later workflow task, and
`ActivityRetryScheduled` records `retry_reason` as `failure`, `timeout`,
or `cold_replay`.

## Cancellation And Shutdown

Workflow cancellation and termination remain workflow-level control-plane
operations. A local activity is not preempted by killing a separate
activity task, because there is no separate task. Cancellation is observed
at heartbeat, timeout, and attempt-completion boundaries. When a local
activity is cancelled, `ActivityCancelled` carries the same local marker
as other local activity events.

If the worker process shuts down gracefully, it should stop claiming new
workflow tasks and allow the in-process local attempt either to complete
or to reach a heartbeat/timeout boundary. If the process exits before a
terminal local activity event is committed, the workflow task lease
expires and normal task repair reclaims the workflow task. Cold replay
then either reruns the local call from the last committed workflow
history or schedules a retry with `retry_reason=cold_replay` when a
started local attempt is present without a terminal event.

## Routing And Admission

Local activities run on the workflow worker that is already executing the
workflow task. The runtime bypasses normal activity queue matching and
does not apply activity `connection`, `queue`, schedule-to-start, or
worker-session routing. Admission requires the activity class to resolve
locally through the v2 type registry and to fit the same structural
limits as ordinary activity scheduling.

Use ordinary activities when routing, separate worker pools, queue
backpressure, external scaling, long wall-clock execution, or independent
activity-task leasing matters.

## Visibility And Export

Operators can distinguish local activity attempts from queued activity
attempts through all durable views:

- `activity_executions.activity_options.execution_mode` is `local`;
- activity history payloads include `execution_mode=local` and
  `local_activity=true`;
- `RunActivityView`, `ActivitySnapshot`, and `HistoryExport` surface
  `execution_mode` and `local_activity`;
- `OperatorMetrics.activities.local*` counts local executions and
  attempts separately from queued activity counts.

The history event names remain ordinary activity event names so existing
replay, export, and timeline tooling can preserve ordering while using
the marker fields to render local execution distinctly.

## Authoring Guidance

Use local activities for short, idempotent, low-latency work that needs
activity retry/timeout/heartbeat semantics but does not need queue
routing or independent worker scaling. Examples include small local
adapter calls, lightweight process-local SDK operations, and short
requests where the workflow worker is the only appropriate executor.

Use ordinary activities for remote calls, slow I/O, CPU-heavy work, work
that needs a dedicated worker fleet, or anything whose execution should
survive workflow worker shutdown through a separately leased activity
task.

Use `sideEffect(...)` only for replay-safe snapshots that do not need
retry, timeout, heartbeat, or cancellation semantics. A local activity is
still an activity; it is not a replacement for deterministic side-effect
recording.
