<?php

declare(strict_types=1);

namespace Workflow\V2\Interceptors;

use Closure;
use InvalidArgumentException;
use Throwable;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\SqliteTransaction;
use Workflow\V2\Support\TaskRepairPolicy;

/**
 * @internal Resolves `workflows.v2.interceptors` and runs them around embedded task cores.
 */
final class WorkerInterceptors
{
    /**
     * Resolve the configured interceptors from the container, keeping their order.
     *
     * @return list<WorkerInterceptor>
     */
    public static function resolve(): array
    {
        $configured = config('workflows.v2.interceptors', []);
        if ($configured === null || $configured === []) {
            return [];
        }
        if (! is_array($configured) || ! array_is_list($configured)) {
            throw new InvalidArgumentException('workflows.v2.interceptors must be a list of interceptor class names.');
        }

        $resolved = [];
        foreach ($configured as $interceptor) {
            if (is_string($interceptor)) {
                $interceptor = app()
                    ->make($interceptor);
            }
            if (! $interceptor instanceof WorkerInterceptor) {
                throw new InvalidArgumentException(sprintf(
                    'Workflow interceptor [%s] must implement %s.',
                    get_debug_type($interceptor),
                    WorkerInterceptor::class,
                ));
            }
            $resolved[] = $interceptor;
        }

        return $resolved;
    }

    /**
     * Run one workflow task inside the chain and return the core's own result.
     *
     * Hooks see a read-only outcome. A core failure is rethrown unchanged even if
     * a hook catches it. A hook failure, or a hook that skips or repeats `$next`,
     * throws {@see WorkflowTaskInterceptorFailed}, which rolls the task back.
     *
     * @param Closure(): ?WorkflowTask $core
     */
    public static function workflowTask(WorkflowRun $run, WorkflowTask $task, Closure $core): ?WorkflowTask
    {
        $interceptors = self::resolve();
        if ($interceptors === []) {
            return $core();
        }

        $context = WorkflowTaskInterceptorContext::forTask($run, $task);
        $before = (int) ($run->last_history_sequence ?? 0);
        $state = new class() {
            public int $calls = 0;

            public bool $completed = false;

            public ?WorkflowTask $result = null;

            public ?Throwable $coreFailure = null;
        };
        $terminal = static function () use ($core, $state, $run, $before): WorkflowTaskOutcome {
            if (++$state->calls > 1) {
                throw new WorkflowTaskInterceptorFailed(
                    null,
                    'A workflow task interceptor called $next more than once.'
                );
            }
            try {
                $state->result = $core();
            } catch (Throwable $failure) {
                $state->coreFailure = $failure;

                throw $failure;
            }
            $state->completed = true;

            return self::outcome($run, $before, $state->result);
        };

        $handler = $terminal;
        foreach (array_reverse($interceptors) as $interceptor) {
            $next = $handler;
            $handler = static fn (): WorkflowTaskOutcome => self::guardWorkflowHook($interceptor, $context, $next);
        }

        try {
            $handler();
        } catch (Throwable $failure) {
            if ($state->coreFailure !== null) {
                throw $state->coreFailure;
            }

            throw $failure instanceof WorkflowTaskInterceptorFailed
                ? $failure
                : WorkflowTaskInterceptorFailed::threw($failure);
        }
        if ($state->coreFailure !== null) {
            throw $state->coreFailure;
        }
        if (! $state->completed) {
            throw new WorkflowTaskInterceptorFailed(
                null,
                'A workflow task interceptor returned without calling $next.'
            );
        }

        return $state->result;
    }

    /**
     * @param Closure(): ActivityInterceptorContext $context built only when interceptors are configured
     * @param Closure(array<int|string, mixed>): mixed $core receives the arguments to call the activity with
     * @param array<int|string, mixed> $arguments
     */
    public static function activity(Closure $context, array $arguments, Closure $core): mixed
    {
        $interceptors = self::resolve();
        if ($interceptors === []) {
            return $core($arguments);
        }

        $handler = static fn (ActivityInterceptorContext $ctx): mixed => $core($ctx->arguments);
        foreach (array_reverse($interceptors) as $interceptor) {
            $next = $handler;
            $handler = static fn (ActivityInterceptorContext $ctx): mixed => $interceptor->executeActivity($ctx, $next);
        }

        return $handler($context());
    }

    /**
     * @param Closure(): QueryTaskInterceptorContext $context built only when interceptors are configured
     * @param Closure(array<int|string, mixed>): mixed $core receives the arguments to query with
     * @param array<int|string, mixed> $arguments
     */
    public static function queryTask(Closure $context, array $arguments, Closure $core): mixed
    {
        $interceptors = self::resolve();
        if ($interceptors === []) {
            return $core($arguments);
        }

        $handler = static fn (QueryTaskInterceptorContext $ctx): mixed => $core($ctx->arguments);
        foreach (array_reverse($interceptors) as $interceptor) {
            $next = $handler;
            $handler = static fn (QueryTaskInterceptorContext $ctx): mixed => $interceptor->executeQueryTask(
                $ctx,
                $next
            );
        }

        return $handler($context());
    }

    /**
     * Release a workflow task whose interceptor failed, so it is retried with
     * repair backoff. The failed attempt's transaction already rolled back.
     */
    public static function releaseFailedTask(string $taskId, WorkflowTaskInterceptorFailed $failure): ?WorkflowTask
    {
        return SqliteTransaction::forTask($taskId, static function () use ($taskId, $failure): ?WorkflowTask {
            /** @var WorkflowTask|null $task */
            $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->lockForUpdate()
                ->find($taskId);
            if ($task === null || $task->status !== TaskStatus::Leased) {
                return null;
            }

            /** @var WorkflowRun $run */
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->lockForUpdate()
                ->findOrFail($task->workflow_run_id);
            if ($run->status->isTerminal()) {
                return null;
            }

            $now = now();
            $task->forceFill([
                'status' => TaskStatus::Ready,
                'leased_at' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'last_error' => $failure->getMessage(),
                'repair_count' => (int) $task->repair_count + 1,
                'available_at' => TaskRepairPolicy::repairAvailableAtAfterFailure($task, $now),
            ])->save();

            /** @var HistoryProjectionRole $projection */
            $projection = app(HistoryProjectionRole::class);
            $projection->projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return $task;
        });
    }

    public static function workerId(): string
    {
        return sprintf('%s:%d', gethostname() ?: 'unknown-host', getmypid());
    }

    /**
     * @return array<string, mixed>
     */
    public static function taskSnapshot(WorkflowTask $task): array
    {
        return [
            'id' => $task->id,
            'workflow_run_id' => $task->workflow_run_id,
            'namespace' => $task->namespace,
            'task_type' => $task->task_type?->value,
            'status' => $task->status?->value,
            'connection' => $task->connection,
            'queue' => $task->queue,
            'attempt_count' => (int) $task->attempt_count,
            'payload' => is_array($task->payload) ? $task->payload : [],
        ];
    }

    /**
     * The workflow core always runs with the engine's own context, so a hook
     * cannot swap the task it executes. Each hook must call `$next` exactly once.
     *
     * @param Closure(): WorkflowTaskOutcome $next
     */
    private static function guardWorkflowHook(
        WorkerInterceptor $interceptor,
        WorkflowTaskInterceptorContext $context,
        Closure $next,
    ): WorkflowTaskOutcome {
        $called = false;
        $outcome = $interceptor->executeWorkflowTask(
            $context,
            static function () use ($interceptor, $next, &$called): WorkflowTaskOutcome {
                if ($called) {
                    throw WorkflowTaskInterceptorFailed::calledNextTwice($interceptor::class);
                }
                $called = true;

                return $next();
            },
        );
        if (! $called) {
            throw WorkflowTaskInterceptorFailed::skippedNext($interceptor::class);
        }

        return $outcome;
    }

    private static function outcome(WorkflowRun $run, int $before, ?WorkflowTask $nextTask): WorkflowTaskOutcome
    {
        /** @var list<string> $types */
        $types = ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
            ->where('workflow_run_id', $run->id)
            ->where('sequence', '>', $before)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(
                static fn (mixed $type): string => $type instanceof \BackedEnum ? (string) $type->value : (string) $type
            )
            ->values()
            ->all();
        $status = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->whereKey($run->id)
            ->value('status');

        return new WorkflowTaskOutcome(
            $types,
            $status instanceof \BackedEnum ? (string) $status->value : (is_string($status) ? $status : null),
            $nextTask?->id,
        );
    }
}
