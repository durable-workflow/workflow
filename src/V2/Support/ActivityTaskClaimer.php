<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\LifecycleEventDispatcher;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class ActivityTaskClaimer
{
    /**
     * @return array{0: ActivityTaskClaim|null, 1: int|null}
     */
    public static function claim(
        string $taskId,
        ?string $leaseOwner = null,
        bool $releaseFutureTasks = false,
    ): array {
        $result = self::claimDetailed($taskId, $leaseOwner, $releaseFutureTasks);

        return [$result['claim'], $result['retry_after_seconds']];
    }

    /**
     * @return array{
     *     claim: ActivityTaskClaim|null,
     *     retry_after_seconds: int|null,
     *     reason: string|null,
     *     backend_error: string|null,
     *     compatibility_reason: string|null
     * }
     */
    public static function claimDetailed(
        string $taskId,
        ?string $leaseOwner = null,
        bool $releaseFutureTasks = false,
    ): array {
        return DB::transaction(static function () use ($taskId, $leaseOwner, $releaseFutureTasks): array {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->lockForUpdate()
                ->find($taskId);

            if ($task === null) {
                return self::claimFailure('task_not_found');
            }

            if ($task->task_type !== TaskType::Activity) {
                return self::claimFailure('task_not_activity');
            }

            if ($task->status !== TaskStatus::Ready) {
                return self::claimFailure('task_not_ready');
            }

            if ($task->available_at !== null && $task->available_at->isFuture()) {
                return self::claimFailure(
                    'task_not_due',
                    $releaseFutureTasks ? self::releaseDelaySeconds($task) : null,
                );
            }

            $activityExecutionId = $task->payload['activity_execution_id'] ?? null;

            if (! is_string($activityExecutionId) || $activityExecutionId === '') {
                return self::claimFailure('activity_execution_missing');
            }

            /** @var ActivityExecution|null $execution */
            $execution = ActivityExecution::query()
                ->lockForUpdate()
                ->find($activityExecutionId);

            if (! $execution instanceof ActivityExecution) {
                return self::claimFailure('activity_execution_not_found');
            }

            /** @var WorkflowRun|null $run */
            $run = WorkflowRun::query()
                ->lockForUpdate()
                ->find($execution->workflow_run_id);

            if (! $run instanceof WorkflowRun) {
                return self::claimFailure('workflow_run_missing');
            }

            TaskCompatibility::sync($task, $run);

            $backendError = TaskBackendCapabilities::recordClaimFailureIfUnsupported($task);

            if ($backendError !== null) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return self::claimFailure('backend_unsupported', null, $backendError);
            }

            if (! TaskCompatibility::supported($task, $run)) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return self::claimFailure(
                    'compatibility_unsupported',
                    null,
                    null,
                    TaskCompatibility::mismatchReason($task, $run),
                );
            }

            $now = now();
            $attemptId = (string) Str::ulid();
            $attemptCount = ((int) $task->attempt_count) + 1;

            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => $now,
                'lease_owner' => self::nonEmptyString($leaseOwner) ?? $taskId,
                'lease_expires_at' => ActivityLease::expiresAt(),
                'attempt_count' => $attemptCount,
                'last_claim_failed_at' => null,
                'last_claim_error' => null,
            ])->save();

            $retryPolicy = is_array($execution->retry_policy) ? $execution->retry_policy : [];
            $startToCloseTimeout = is_int($retryPolicy['start_to_close_timeout'] ?? null)
                ? $retryPolicy['start_to_close_timeout']
                : null;
            $closeDeadlineAt = $startToCloseTimeout !== null
                ? $now->copy()->addSeconds($startToCloseTimeout)
                : null;

            $execution->forceFill([
                'status' => ActivityStatus::Running,
                'attempt_count' => $attemptCount,
                'current_attempt_id' => $attemptId,
                'started_at' => $now,
                'last_heartbeat_at' => null,
                'close_deadline_at' => $closeDeadlineAt,
            ])->save();

            /** @var ActivityAttempt $attempt */
            $attempt = ActivityAttempt::query()->create([
                'id' => $attemptId,
                'workflow_run_id' => $run->id,
                'activity_execution_id' => $execution->id,
                'workflow_task_id' => $task->id,
                'attempt_number' => $attemptCount,
                'status' => ActivityAttemptStatus::Running->value,
                'lease_owner' => $task->lease_owner,
                'started_at' => $execution->started_at ?? $now,
                'lease_expires_at' => $task->lease_expires_at,
            ]);

            $parallelMetadataPath = ParallelChildGroup::metadataPathForSequence($run, (int) $execution->sequence);
            $parallelMetadata = ParallelChildGroup::payloadForPath($parallelMetadataPath);

            WorkflowHistoryEvent::record($run, HistoryEventType::ActivityStarted, array_merge([
                'activity_execution_id' => $execution->id,
                'activity_attempt_id' => $attemptId,
                'activity_class' => $execution->activity_class,
                'activity_type' => $execution->activity_type,
                'sequence' => $execution->sequence,
                'attempt_number' => $attemptCount,
                'activity' => ActivitySnapshot::fromExecution($execution),
            ], $parallelMetadata ?? []), $task);

            LifecycleEventDispatcher::activityStarted(
                $run,
                (string) $execution->id,
                (string) ($execution->activity_type ?? $execution->activity_class),
                (string) $execution->activity_class,
                (int) $execution->sequence,
                $attemptCount,
            );

            RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return self::claimSuccess(new ActivityTaskClaim($task, $run, $execution, $attempt));
        });
    }

    /**
     * @return array{
     *     claim: ActivityTaskClaim|null,
     *     retry_after_seconds: int|null,
     *     reason: string|null,
     *     backend_error: string|null,
     *     compatibility_reason: string|null
     * }
     */
    private static function claimSuccess(ActivityTaskClaim $claim): array
    {
        return [
            'claim' => $claim,
            'retry_after_seconds' => null,
            'reason' => null,
            'backend_error' => null,
            'compatibility_reason' => null,
        ];
    }

    /**
     * @return array{
     *     claim: ActivityTaskClaim|null,
     *     retry_after_seconds: int|null,
     *     reason: string|null,
     *     backend_error: string|null,
     *     compatibility_reason: string|null
     * }
     */
    private static function claimFailure(
        string $reason,
        ?int $retryAfterSeconds = null,
        ?string $backendError = null,
        ?string $compatibilityReason = null,
    ): array {
        return [
            'claim' => null,
            'retry_after_seconds' => $retryAfterSeconds,
            'reason' => $reason,
            'backend_error' => $backendError,
            'compatibility_reason' => $compatibilityReason,
        ];
    }

    private static function releaseDelaySeconds(WorkflowTask $task): int
    {
        $remainingMilliseconds = max(1, $task->available_at->getTimestampMs() - now()->getTimestampMs());

        return (int) ceil($remainingMilliseconds / 1000);
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
