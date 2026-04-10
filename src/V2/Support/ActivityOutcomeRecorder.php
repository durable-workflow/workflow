<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\Exceptions\NonRetryableExceptionContract;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class ActivityOutcomeRecorder
{
    /**
     * @return array{recorded: bool, reason: string|null, next_task: WorkflowTask|null}
     */
    public static function record(
        string $taskId,
        string $attemptId,
        int $attemptCount,
        mixed $result,
        ?Throwable $throwable,
        int $maxAttempts,
        int $backoffSeconds,
    ): array {
        return DB::transaction(function () use (
            $taskId,
            $attemptId,
            $attemptCount,
            $result,
            $throwable,
            $maxAttempts,
            $backoffSeconds,
        ): array {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->lockForUpdate()
                ->find($taskId);

            if (! $task instanceof WorkflowTask) {
                return self::ignored('task_not_found');
            }

            $activityExecutionId = $task->payload['activity_execution_id'] ?? null;

            if (! is_string($activityExecutionId) || $activityExecutionId === '') {
                return self::ignored('activity_execution_missing');
            }

            /** @var ActivityExecution $lockedExecution */
            $lockedExecution = ActivityExecution::query()
                ->lockForUpdate()
                ->findOrFail($activityExecutionId);

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()
                ->lockForUpdate()
                ->findOrFail($lockedExecution->workflow_run_id);

            // Ignore late activity outcomes once the lease has been reclaimed or a newer
            // attempt has already been claimed for this execution.
            if (
                $task->status !== TaskStatus::Leased
                || $task->attempt_count !== $attemptCount
                || $lockedExecution->attempt_count !== $attemptCount
                || $lockedExecution->current_attempt_id !== $attemptId
            ) {
                self::closeAttemptIfStale($run, $attemptId);

                return self::ignored('stale_attempt');
            }

            if (in_array($run->status, [RunStatus::Cancelled, RunStatus::Terminated], true)) {
                $lockedExecution->forceFill([
                    'status' => ActivityStatus::Cancelled,
                    'closed_at' => $lockedExecution->closed_at ?? now(),
                ])->save();

                self::closeAttempt($attemptId, ActivityAttemptStatus::Cancelled);

                $task->forceFill([
                    'status' => $task->status === TaskStatus::Cancelled ? TaskStatus::Cancelled : TaskStatus::Completed,
                    'lease_expires_at' => null,
                ])->save();

                ActivityCancellation::record($run, $lockedExecution, $task);

                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return self::recorded(null);
            }

            if (in_array($run->status, [RunStatus::Completed, RunStatus::Failed], true)) {
                $lockedExecution->forceFill([
                    'status' => $throwable === null ? ActivityStatus::Completed : ActivityStatus::Failed,
                    'result' => $throwable === null ? Serializer::serialize($result) : $lockedExecution->result,
                    'exception' => $throwable === null
                        ? $lockedExecution->exception
                        : Serializer::serialize(FailureFactory::payload($throwable)),
                    'closed_at' => $lockedExecution->closed_at ?? now(),
                ])->save();

                self::closeAttempt(
                    $attemptId,
                    $throwable === null ? ActivityAttemptStatus::Completed : ActivityAttemptStatus::Failed,
                );

                $task->forceFill([
                    'status' => TaskStatus::Completed,
                    'lease_expires_at' => null,
                ])->save();

                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return self::recorded(null);
            }

            $parallelMetadataPath = ParallelChildGroup::metadataPathForSequence(
                $run,
                (int) $lockedExecution->sequence,
            );
            if ($parallelMetadataPath === []) {
                $parallelMetadataPath = ParallelChildGroup::metadataPathFromPayload([
                    'parallel_group_path' => $lockedExecution->parallel_group_path,
                ]);
            }
            $parallelMetadata = ParallelChildGroup::payloadForPath($parallelMetadataPath);

            if ($throwable === null) {
                $lockedExecution->forceFill([
                    'status' => ActivityStatus::Completed,
                    'result' => Serializer::serialize($result),
                    'exception' => null,
                    'closed_at' => now(),
                ])->save();

                WorkflowHistoryEvent::record($run, HistoryEventType::ActivityCompleted, array_merge([
                    'activity_execution_id' => $lockedExecution->id,
                    'activity_attempt_id' => $attemptId,
                    'activity_class' => $lockedExecution->activity_class,
                    'activity_type' => $lockedExecution->activity_type,
                    'sequence' => $lockedExecution->sequence,
                    'attempt_number' => $attemptCount,
                    'result' => $lockedExecution->result,
                    'activity' => ActivitySnapshot::fromExecution($lockedExecution),
                ], $parallelMetadata ?? []), $task);

                self::closeAttempt($attemptId, ActivityAttemptStatus::Completed);
            } elseif (self::shouldRetry($throwable, $attemptCount, $maxAttempts)) {
                $exceptionPayload = FailureFactory::payload($throwable);
                $retryAvailableAt = now()->addSeconds($backoffSeconds);

                self::closeAttempt($attemptId, ActivityAttemptStatus::Failed);

                $lockedExecution->forceFill([
                    'status' => ActivityStatus::Pending,
                    'exception' => Serializer::serialize($exceptionPayload),
                    'last_heartbeat_at' => null,
                ])->save();

                $task->forceFill([
                    'status' => TaskStatus::Completed,
                    'lease_expires_at' => null,
                ])->save();

                /** @var WorkflowTask $retryTask */
                $retryTask = WorkflowTask::query()->create([
                    'workflow_run_id' => $run->id,
                    'task_type' => TaskType::Activity->value,
                    'status' => TaskStatus::Ready->value,
                    'available_at' => $retryAvailableAt,
                    'payload' => [
                        'activity_execution_id' => $lockedExecution->id,
                        'retry_of_task_id' => $task->id,
                        'retry_after_attempt_id' => $attemptId,
                        'retry_after_attempt' => $attemptCount,
                        'retry_backoff_seconds' => $backoffSeconds,
                        'max_attempts' => $maxAttempts === PHP_INT_MAX ? null : $maxAttempts,
                        'retry_policy' => $lockedExecution->retry_policy,
                    ],
                    'connection' => $lockedExecution->connection,
                    'queue' => $lockedExecution->queue,
                    'compatibility' => $run->compatibility,
                    'attempt_count' => $attemptCount,
                ]);

                WorkflowHistoryEvent::record($run, HistoryEventType::ActivityRetryScheduled, array_merge([
                    'activity_execution_id' => $lockedExecution->id,
                    'activity_attempt_id' => $attemptId,
                    'activity_class' => $lockedExecution->activity_class,
                    'activity_type' => $lockedExecution->activity_type,
                    'sequence' => $lockedExecution->sequence,
                    'retry_task_id' => $retryTask->id,
                    'retry_of_task_id' => $task->id,
                    'retry_available_at' => $retryAvailableAt->toJSON(),
                    'retry_backoff_seconds' => $backoffSeconds,
                    'retry_after_attempt_id' => $attemptId,
                    'retry_after_attempt' => $attemptCount,
                    'max_attempts' => $maxAttempts === PHP_INT_MAX ? null : $maxAttempts,
                    'retry_policy' => $lockedExecution->retry_policy,
                    'exception_type' => $exceptionPayload['type'] ?? null,
                    'exception_class' => $exceptionPayload['class'] ?? get_class($throwable),
                    'message' => $exceptionPayload['message'] ?? $throwable->getMessage(),
                    'code' => $throwable->getCode(),
                    'exception' => $exceptionPayload,
                    'activity' => ActivitySnapshot::fromExecution($lockedExecution),
                ], $parallelMetadata ?? []), $task);

                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return self::recorded($retryTask);
            } else {
                $exceptionPayload = FailureFactory::payload($throwable);

                /** @var WorkflowFailure $failure */
                $failure = WorkflowFailure::query()->create(array_merge(
                    FailureFactory::make($throwable),
                    [
                        'workflow_run_id' => $run->id,
                        'source_kind' => 'activity_execution',
                        'source_id' => $lockedExecution->id,
                        'propagation_kind' => 'activity',
                        'handled' => false,
                    ],
                ));

                $lockedExecution->forceFill([
                    'status' => ActivityStatus::Failed,
                    'exception' => Serializer::serialize($exceptionPayload),
                    'closed_at' => now(),
                ])->save();

                WorkflowHistoryEvent::record($run, HistoryEventType::ActivityFailed, array_merge([
                    'activity_execution_id' => $lockedExecution->id,
                    'activity_attempt_id' => $attemptId,
                    'activity_class' => $lockedExecution->activity_class,
                    'activity_type' => $lockedExecution->activity_type,
                    'sequence' => $lockedExecution->sequence,
                    'attempt_number' => $attemptCount,
                    'failure_id' => $failure->id,
                    'exception_type' => $exceptionPayload['type'] ?? null,
                    'exception_class' => $failure->exception_class,
                    'message' => $failure->message,
                    'code' => $throwable->getCode(),
                    'exception' => $exceptionPayload,
                    'activity' => ActivitySnapshot::fromExecution($lockedExecution),
                ], $parallelMetadata ?? []), $task);

                self::closeAttempt($attemptId, ActivityAttemptStatus::Failed);
            }

            $task->forceFill([
                'status' => TaskStatus::Completed,
                'lease_expires_at' => null,
            ])->save();

            $closedStatus = $throwable === null
                ? ActivityStatus::Completed
                : ActivityStatus::Failed;

            if (
                $parallelMetadataPath !== []
                && ! ParallelChildGroup::shouldWakeParentOnActivityClosure(
                    $run,
                    $parallelMetadataPath,
                    $closedStatus,
                )
            ) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return self::recorded(null);
            }

            /** @var WorkflowTask $resumeTask */
            $resumeTask = WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now(),
                'payload' => [],
                'connection' => $run->connection,
                'queue' => $run->queue,
                'compatibility' => $run->compatibility,
            ]);

            RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return self::recorded($resumeTask);
        });
    }

    /**
     * @return array{recorded: bool, reason: string|null, next_task: WorkflowTask|null}
     */
    public static function recordForAttempt(string $attemptId, mixed $result, ?Throwable $throwable): array
    {
        /** @var ActivityAttempt|null $attempt */
        $attempt = ActivityAttempt::query()
            ->with('execution')
            ->find($attemptId);

        if (! $attempt instanceof ActivityAttempt) {
            return self::ignored('attempt_not_found');
        }

        if (! is_string($attempt->workflow_task_id) || $attempt->workflow_task_id === '') {
            return self::ignored('task_not_found');
        }

        $execution = $attempt->execution;

        if (! $execution instanceof ActivityExecution) {
            return self::ignored('activity_execution_missing');
        }

        $attemptNumber = max(1, (int) $attempt->attempt_number);

        return self::record(
            $attempt->workflow_task_id,
            $attempt->id,
            $attemptNumber,
            $result,
            $throwable,
            ActivityRetryPolicy::maxAttemptsFromSnapshot($execution),
            ActivityRetryPolicy::backoffSecondsFromSnapshot($execution, $attemptNumber),
        );
    }

    /**
     * @return array{recorded: bool, reason: string|null, next_task: WorkflowTask|null}
     */
    private static function recorded(?WorkflowTask $nextTask): array
    {
        return [
            'recorded' => true,
            'reason' => null,
            'next_task' => $nextTask,
        ];
    }

    /**
     * @return array{recorded: bool, reason: string|null, next_task: WorkflowTask|null}
     */
    private static function ignored(string $reason): array
    {
        return [
            'recorded' => false,
            'reason' => $reason,
            'next_task' => null,
        ];
    }

    private static function closeAttempt(string $attemptId, ActivityAttemptStatus $status): void
    {
        /** @var ActivityAttempt|null $attempt */
        $attempt = ActivityAttempt::query()
            ->lockForUpdate()
            ->find($attemptId);

        if (! $attempt instanceof ActivityAttempt || $attempt->status !== ActivityAttemptStatus::Running) {
            return;
        }

        $attempt->forceFill([
            'status' => $status,
            'lease_expires_at' => null,
            'closed_at' => $attempt->closed_at ?? now(),
        ])->save();
    }

    private static function closeAttemptIfStale(WorkflowRun $run, string $attemptId): void
    {
        $status = in_array($run->status, [RunStatus::Cancelled, RunStatus::Terminated], true)
            ? ActivityAttemptStatus::Cancelled
            : ActivityAttemptStatus::Expired;

        self::closeAttempt($attemptId, $status);
    }

    private static function shouldRetry(Throwable $throwable, int $attemptCount, int $maxAttempts): bool
    {
        return ! $throwable instanceof NonRetryableExceptionContract
            && $attemptCount < $maxAttempts;
    }
}
