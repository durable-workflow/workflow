<?php

declare(strict_types=1);

namespace Workflow\V2\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
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
use Workflow\V2\Support\ActivityLease;
use Workflow\V2\Support\ActivityRetryPolicy;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\FailureFactory;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\TaskBackendCapabilities;
use Workflow\V2\Support\TaskCompatibility;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkerCompatibilityFleet;

final class RunActivityTask implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $taskId,
    ) {
        $this->afterCommit();
    }

    public function handle(): void
    {
        WorkerCompatibilityFleet::heartbeat(
            is_string($this->connection ?? null) ? $this->connection : null,
            is_string($this->queue ?? null) ? $this->queue : null,
        );

        [$claim, $releaseIn] = $this->claimTask();

        if ($releaseIn !== null) {
            $this->release($releaseIn);

            return;
        }

        if ($claim === null) {
            return;
        }

        $activityExecutionId = $claim['activity_execution_id'];
        $attemptId = $claim['attempt_id'];
        $attemptCount = $claim['attempt_count'];

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->with('run')
            ->findOrFail($activityExecutionId);

        $activityClass = TypeRegistry::resolveActivityClass($execution->activity_class, $execution->activity_type);
        $activity = new $activityClass($execution, $execution->run, $this->taskId);
        $arguments = $activity->resolveMethodDependencies(
            $execution->activityArguments(),
            new ReflectionMethod($activity, 'execute'),
        );

        $result = null;
        $throwable = null;

        try {
            $result = $activity->execute(...$arguments);
        } catch (Throwable $error) {
            $throwable = $error;
        }

        $maxAttempts = ActivityRetryPolicy::maxAttempts($execution, $activity);
        $backoffSeconds = ActivityRetryPolicy::backoffSeconds($execution, $activity, $attemptCount);

        $nextTask = DB::transaction(function () use (
            $execution,
            $result,
            $throwable,
            $attemptId,
            $attemptCount,
            $maxAttempts,
            $backoffSeconds,
        ): ?WorkflowTask {
            /** @var WorkflowTask $task */
            $task = WorkflowTask::query()
                ->lockForUpdate()
                ->findOrFail($this->taskId);

            /** @var ActivityExecution $lockedExecution */
            $lockedExecution = ActivityExecution::query()
                ->lockForUpdate()
                ->findOrFail($execution->id);

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

                return null;
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

                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return null;
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

                return null;
            }

            $parallelMetadataPath = \Workflow\V2\Support\ParallelChildGroup::metadataPathForSequence(
                $run,
                (int) $lockedExecution->sequence,
            );
            $parallelMetadata = \Workflow\V2\Support\ParallelChildGroup::payloadForPath($parallelMetadataPath);

            if ($throwable === null) {
                $lockedExecution->forceFill([
                    'status' => ActivityStatus::Completed,
                    'result' => Serializer::serialize($result),
                    'exception' => null,
                    'closed_at' => now(),
                ])->save();

                WorkflowHistoryEvent::record($run, HistoryEventType::ActivityCompleted, array_merge([
                    'activity_execution_id' => $lockedExecution->id,
                    'activity_class' => $lockedExecution->activity_class,
                    'activity_type' => $lockedExecution->activity_type,
                    'sequence' => $lockedExecution->sequence,
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
                    'exception_class' => $exceptionPayload['class'] ?? get_class($throwable),
                    'message' => $exceptionPayload['message'] ?? $throwable->getMessage(),
                    'code' => $throwable->getCode(),
                    'exception' => $exceptionPayload,
                    'activity' => ActivitySnapshot::fromExecution($lockedExecution),
                ], $parallelMetadata ?? []), $task);

                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return $retryTask;
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
                    'activity_class' => $lockedExecution->activity_class,
                    'activity_type' => $lockedExecution->activity_type,
                    'sequence' => $lockedExecution->sequence,
                    'failure_id' => $failure->id,
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
                && ! \Workflow\V2\Support\ParallelChildGroup::shouldWakeParentOnActivityClosure(
                    $run,
                    $parallelMetadataPath,
                    $closedStatus,
                )
            ) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return null;
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

            return $resumeTask;
        });

        if ($nextTask instanceof WorkflowTask) {
            TaskDispatcher::dispatch($nextTask);
        }
    }

    /**
     * @return array{0: array{activity_execution_id: string, attempt_id: string, attempt_count: int}|null, 1: int|null}
     */
    private function claimTask(): array
    {
        return DB::transaction(function (): array {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->lockForUpdate()
                ->find($this->taskId);

            if ($task === null || $task->task_type !== TaskType::Activity || $task->status !== TaskStatus::Ready) {
                return [null, null];
            }

            if ($task->available_at !== null && $task->available_at->isFuture()) {
                $remainingMilliseconds = max(1, $task->available_at->getTimestampMs() - now()->getTimestampMs());

                return [null, (int) ceil($remainingMilliseconds / 1000)];
            }

            $activityExecutionId = $task->payload['activity_execution_id'] ?? null;

            if (! is_string($activityExecutionId)) {
                return [null, null];
            }

            /** @var ActivityExecution $execution */
            $execution = ActivityExecution::query()
                ->lockForUpdate()
                ->findOrFail($activityExecutionId);

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()->findOrFail($execution->workflow_run_id);

            TaskCompatibility::sync($task, $run);

            if (TaskBackendCapabilities::recordClaimFailureIfUnsupported($task) !== null) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return [null, null];
            }

            if (! TaskCompatibility::supported($task, $run)) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return [null, null];
            }

            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => now(),
                'lease_owner' => $this->taskId,
                'lease_expires_at' => ActivityLease::expiresAt(),
                'attempt_count' => $task->attempt_count + 1,
                'last_claim_failed_at' => null,
                'last_claim_error' => null,
            ])->save();

            $attemptId = (string) Str::ulid();
            $attemptCount = $task->attempt_count;

            $execution->forceFill([
                'status' => ActivityStatus::Running,
                'attempt_count' => $attemptCount,
                'current_attempt_id' => $attemptId,
                'started_at' => now(),
                'last_heartbeat_at' => null,
            ])->save();

            ActivityAttempt::query()->create([
                'id' => $attemptId,
                'workflow_run_id' => $run->id,
                'activity_execution_id' => $execution->id,
                'workflow_task_id' => $task->id,
                'attempt_number' => $attemptCount,
                'status' => ActivityAttemptStatus::Running->value,
                'lease_owner' => $task->lease_owner,
                'started_at' => $execution->started_at ?? now(),
                'lease_expires_at' => $task->lease_expires_at,
            ]);

            $parallelMetadataPath = \Workflow\V2\Support\ParallelChildGroup::metadataPathForSequence(
                $run,
                (int) $execution->sequence,
            );
            $parallelMetadata = \Workflow\V2\Support\ParallelChildGroup::payloadForPath($parallelMetadataPath);

            WorkflowHistoryEvent::record($run, HistoryEventType::ActivityStarted, array_merge([
                'activity_execution_id' => $execution->id,
                'activity_class' => $execution->activity_class,
                'activity_type' => $execution->activity_type,
                'sequence' => $execution->sequence,
                'activity' => ActivitySnapshot::fromExecution($execution),
            ], $parallelMetadata ?? []), $task);

            RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return [[
                'activity_execution_id' => $activityExecutionId,
                'attempt_id' => $attemptId,
                'attempt_count' => $attemptCount,
            ], null];
        });
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
