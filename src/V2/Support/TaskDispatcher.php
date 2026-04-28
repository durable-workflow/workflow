<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\UnsupportedBackendCapabilitiesException;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

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
        $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)->find($taskId);

        if (! $task instanceof WorkflowTask || $task->status !== TaskStatus::Ready) {
            return;
        }

        $job = self::makeJob($task);
        $attemptedAt = now();

        if (WorkflowStub::faked()) {
            self::markDispatched($task, $attemptedAt);

            if ($task->available_at === null || ! $task->available_at->isFuture()) {
                app()->call([$job, 'handle']);
            }

            return;
        }

        if (self::isPollMode()) {
            self::markDispatched($task, $attemptedAt);

            return;
        }

        try {
            self::ensureBackendSupportsDispatch($task);

            $fleetBlockReason = self::fleetBlockReason($task);

            if ($fleetBlockReason !== null) {
                self::markDispatchFailure($task, $attemptedAt, $fleetBlockReason);

                return;
            }

            app(BusDispatcher::class)->dispatch($job);
            self::markDispatched($task, $attemptedAt);
        } catch (Throwable $throwable) {
            self::markDispatchFailure($task, $attemptedAt, $throwable->getMessage());

            throw $throwable;
        }
    }

    private static function isPollMode(): bool
    {
        return config('workflows.v2.task_dispatch_mode') === 'poll';
    }

    private static function ensureBackendSupportsDispatch(WorkflowTask $task): void
    {
        $snapshot = BackendCapabilities::snapshot(queueConnection: $task->connection);

        if (! BackendCapabilities::isSupported($snapshot)) {
            throw new UnsupportedBackendCapabilitiesException($snapshot);
        }
    }

    private static function fleetBlockReason(WorkflowTask $task): ?string
    {
        if (config('workflows.v2.fleet.validation_mode') !== 'fail') {
            return null;
        }

        /** @var WorkflowRun|null $run */
        $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)->find($task->workflow_run_id);

        $required = TaskCompatibility::resolve($task, $run);

        if ($required === null) {
            return null;
        }

        $connection = $task->connection ?? $run?->connection;
        $queue = $task->queue ?? $run?->queue;

        if (WorkerCompatibilityFleet::activeWorkerCount($connection, $queue) === 0) {
            return null;
        }

        if (WorkerCompatibilityFleet::supports($required, $connection, $queue)) {
            return null;
        }

        $reason = WorkerCompatibilityFleet::mismatchReason($required, $connection, $queue);

        return $reason === null
            ? 'Dispatch blocked: no compatible worker in fleet.'
            : 'Dispatch blocked under fail validation mode. ' . $reason;
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

        if ($task->available_at !== null && $task->available_at->isFuture()) {
            $effectiveDelay = $task->task_type === TaskType::Timer
                ? TimerTransportChunker::cappedDispatchDelay($task->available_at, $task->connection)
                : $task->available_at;

            $job->delay($effectiveDelay);
        }

        return $job;
    }

    private static function markDispatched(WorkflowTask $task, mixed $attemptedAt): void
    {
        $task->forceFill([
            'last_dispatch_attempt_at' => $attemptedAt,
            'last_dispatched_at' => $attemptedAt,
            'last_dispatch_error' => null,
            'repair_available_at' => null,
        ])->save();

        self::refreshRunSummary($task);
    }

    private static function markDispatchFailure(WorkflowTask $task, mixed $attemptedAt, string $message): void
    {
        $task->forceFill([
            'last_dispatch_attempt_at' => $attemptedAt,
            'last_dispatch_error' => $message,
            'repair_available_at' => TaskRepairPolicy::repairAvailableAtAfterFailure(
                $task,
                $attemptedAt,
                immediateFirstFailure: true,
            ),
        ])->save();

        self::refreshRunSummary($task);
    }

    private static function refreshRunSummary(WorkflowTask $task): void
    {
        /** @var WorkflowRun|null $run */
        $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)->find($task->workflow_run_id);

        if ($run instanceof WorkflowRun) {
            self::historyProjectionRole()->projectRun(self::projectionRun($run));
        }
    }

    private static function historyProjectionRole(): HistoryProjectionRole
    {
        /** @var HistoryProjectionRole $role */
        $role = app(HistoryProjectionRole::class);

        return $role;
    }

    private static function projectionRun(WorkflowRun $run): WorkflowRun
    {
        return $run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']) ?? $run;
    }
}
