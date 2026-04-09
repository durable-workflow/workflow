<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\UnsupportedBackendCapabilitiesException;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class TaskDispatcher
{
    public static function dispatch(WorkflowTask $task): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(static fn () => self::publish($task->id));

            return;
        }

        self::publish($task->id);
    }

    private static function publish(string $taskId): void
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()->find($taskId);

        if (! $task instanceof WorkflowTask || $task->status !== TaskStatus::Ready) {
            return;
        }

        $job = self::makeJob($task);
        $attemptedAt = now();

        try {
            self::ensureBackendSupportsDispatch($task);

            app(BusDispatcher::class)->dispatch($job);
            self::markDispatched($task, $attemptedAt);
        } catch (Throwable $throwable) {
            self::markDispatchFailure($task, $attemptedAt, $throwable->getMessage());

            throw $throwable;
        }
    }

    private static function ensureBackendSupportsDispatch(WorkflowTask $task): void
    {
        $snapshot = BackendCapabilities::snapshot(queueConnection: $task->connection);

        if (! BackendCapabilities::isSupported($snapshot)) {
            throw new UnsupportedBackendCapabilitiesException($snapshot);
        }
    }

    private static function makeJob(WorkflowTask $task): object
    {
        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };

        if ($task->connection !== null) {
            $job->onConnection($task->connection);
        }

        if ($task->queue !== null) {
            $job->onQueue($task->queue);
        }

        if ($task->task_type === TaskType::Timer && $task->available_at !== null) {
            $job->delay($task->available_at);
        }

        return $job;
    }

    private static function markDispatched(WorkflowTask $task, mixed $attemptedAt): void
    {
        $task->forceFill([
            'last_dispatch_attempt_at' => $attemptedAt,
            'last_dispatched_at' => $attemptedAt,
            'last_dispatch_error' => null,
        ])->save();

        self::refreshRunSummary($task);
    }

    private static function markDispatchFailure(WorkflowTask $task, mixed $attemptedAt, string $message): void
    {
        $task->forceFill([
            'last_dispatch_attempt_at' => $attemptedAt,
            'last_dispatch_error' => $message,
        ])->save();

        self::refreshRunSummary($task);
    }

    private static function refreshRunSummary(WorkflowTask $task): void
    {
        /** @var WorkflowRun|null $run */
        $run = WorkflowRun::query()->find($task->workflow_run_id);

        if ($run instanceof WorkflowRun) {
            RunSummaryProjector::project($run);
        }
    }
}
