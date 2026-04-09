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
        return DB::transaction(static function () use ($taskId, $leaseOwner, $releaseFutureTasks): array {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->lockForUpdate()
                ->find($taskId);

            if ($task === null || $task->task_type !== TaskType::Activity || $task->status !== TaskStatus::Ready) {
                return [null, null];
            }

            if ($task->available_at !== null && $task->available_at->isFuture()) {
                return [null, $releaseFutureTasks ? self::releaseDelaySeconds($task) : null];
            }

            $activityExecutionId = $task->payload['activity_execution_id'] ?? null;

            if (! is_string($activityExecutionId) || $activityExecutionId === '') {
                return [null, null];
            }

            /** @var ActivityExecution $execution */
            $execution = ActivityExecution::query()
                ->lockForUpdate()
                ->findOrFail($activityExecutionId);

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()
                ->lockForUpdate()
                ->findOrFail($execution->workflow_run_id);

            TaskCompatibility::sync($task, $run);

            if (TaskBackendCapabilities::recordClaimFailureIfUnsupported($task) !== null) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return [null, null];
            }

            if (! TaskCompatibility::supported($task, $run)) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return [null, null];
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

            $execution->forceFill([
                'status' => ActivityStatus::Running,
                'attempt_count' => $attemptCount,
                'current_attempt_id' => $attemptId,
                'started_at' => $now,
                'last_heartbeat_at' => null,
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

            RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return [new ActivityTaskClaim($task, $run, $execution, $attempt), null];
        });
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
