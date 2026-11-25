# Worker interceptors

Embedded Durable Workflow runs ordered worker interceptors around the workflow
tasks, activities and queries it executes in your application's processes.
Use them for audit logging, metric tags, tenant context or tracing that you want
to write once instead of in every workflow and activity. The hook shape matches
the Python, PHP and Rust SDK interceptors.

## Configure interceptors

List interceptor classes in `config/workflows.php`. They are resolved from the
Laravel container, so constructor injection works, and they run in the listed
order; the first is the outermost wrapper.

```php
'v2' => [
    // ...
    'interceptors' => [
        App\Workflows\AuditInterceptor::class,
    ],
],
```

Each interceptor implements `Workflow\V2\Interceptors\WorkerInterceptor`.
Extend `Workflow\V2\Interceptors\PassthroughWorkerInterceptor` to implement
only the hooks you need, and call `$next($context)` to continue the chain.

```php
namespace App\Workflows;

use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Workflow\V2\Interceptors\ActivityInterceptorContext;
use Workflow\V2\Interceptors\PassthroughWorkerInterceptor;
use Workflow\V2\Interceptors\WorkflowTaskInterceptorContext;
use Workflow\V2\Interceptors\WorkflowTaskOutcome;

final class AuditInterceptor extends PassthroughWorkerInterceptor
{
    public function __construct(private readonly LoggerInterface $audit)
    {
    }

    public function executeWorkflowTask(WorkflowTaskInterceptorContext $context, callable $next): WorkflowTaskOutcome
    {
        $outcome = $next($context);
        // The task commits after the chain returns; log only once it has.
        DB::afterCommit(fn () => $this->audit->info('workflow task', [
            'workflow_id' => $context->workflowId,
            'run_id' => $context->runId,
            'attempt' => $context->workflowTaskAttempt,
            'recorded' => $outcome->historyEventTypes,
        ]));

        return $outcome;
    }

    public function executeActivity(ActivityInterceptorContext $context, callable $next): mixed
    {
        $this->audit->info('activity started', [
            'activity_type' => $context->activityType,
            'attempt' => $context->attemptNumber,
            'workflow_id' => $context->workflowId,
        ]);
        try {
            return $next($context);
        } finally {
            $this->audit->info('activity finished', ['activity_type' => $context->activityType]);
        }
    }
}
```

The container builds the configured interceptors for each task. Bind one as a
singleton when it must keep state between tasks.

## Hooks

| Hook | Context | `$next` returns |
| --- | --- | --- |
| `executeWorkflowTask($context, $next)` | `workerId`, `connection`, `taskQueue`, `namespace`, `task`, `taskId`, `workflowType`, `workflowId`, `runId`, `workflowTaskAttempt`, `historyLength`, `replaying` | `WorkflowTaskOutcome` with `historyEventTypes`, `runStatus`, `nextTaskId` |
| `executeActivity($context, $next)` | `workerId`, `connection`, `taskQueue`, `namespace`, `task`, `activityType`, `activityClass`, `arguments`, `attemptNumber`, `taskId`, `activityExecutionId`, `activityAttemptId`, `workflowId`, `runId`, `local`, `activity` | the activity result |
| `executeQueryTask($context, $next)` | `workerId` (always null), `connection`, `taskQueue`, `namespace`, `task`, `queryName`, `workflowType`, `workflowId`, `runId`, `arguments` | the query result |

`task` is an array snapshot of the claimed task row, never the model itself.
`workerId` identifies the queue worker process by host and process ID. Queries
run in the calling process, so their `workerId` is null.

## Determinism

Workflow-task interceptors cannot change what a workflow records:

- `executeWorkflowTask` wraps the workflow task inside its database
  transaction, once per task attempt. `replaying` is `true` when earlier
  workflow tasks already recorded commands for the run, so the workflow code
  re-ran that committed prefix before reaching new code. It is not a
  deduplication key: `historyEventTypes` lists only the events this task
  appended. Hook side effects run before the task commits and can repeat when
  an attempt is retried, so defer them with `DB::afterCommit()` or key them on
  `taskId` and `workflowTaskAttempt`.
- The engine commits the history the workflow code produced. The outcome is a
  read-only view; returning a different outcome, or passing a different context
  to `$next`, changes nothing. A hook that rolls back a nested transaction
  around `$next` fails the task instead of committing a partial task.
- A failure from the workflow code reaches the engine unchanged, even if an
  interceptor catches it and returns normally.
- If an interceptor throws out of the chain, skips `$next` or calls it more
  than once, the task's transaction rolls back, so nothing is recorded. The
  task is released with `WorkflowTaskInterceptorFailed` as its last error and
  retried after the usual repair backoff. An outer interceptor may still catch
  an exception an inner interceptor threw after `$next` and let the task
  commit; a skipped or repeated `$next` always fails the task.
- Hooks run while the run and task rows are locked, so keep them short. Local
  activities run inside the task, so a hook that keeps failing after `$next`
  makes each retry run those local activities again.
- A workflow that resolves deferred entity references outside the task
  transaction runs the task in phases. The hook then runs once per phase, and
  the earlier phases see the internal deferral as an exception from `$next`.

Activity and query interceptors may change arguments with
`$context->withArguments(...)` and may change the returned value, because an
activity result is recorded once and a query records nothing. Rewritten query
arguments are validated against the query contract again. Activity exceptions
pass through every interceptor unless one handles them, and then go through
the activity's normal retry policy. Activity interceptors wrap queued and local
activities; `local` tells them apart. Mocked activities in `WorkflowStub::fake()`
do not run interceptors.

## Tracing and external workers

When [embedded tracing](embedded-tracing.md) is enabled, the built-in workflow
and activity spans stay outermost, so interceptors run inside the active span
and can add attributes or child spans.

Interceptors wrap only work this package executes in-process. Tasks handed to
external SDK workers through the worker task bridges run under that SDK's own
interceptors.
