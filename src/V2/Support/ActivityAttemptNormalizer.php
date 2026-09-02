<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Str;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowTask;

final class ActivityAttemptNormalizer
{
    public static function ensureCurrentAttempt(
        ActivityExecution $execution,
        ?WorkflowTask $task = null,
    ): ?ActivityAttempt {
        $attemptNumber = self::attemptNumber($execution);

        if ($attemptNumber === null) {
            return null;
        }

        $attempt = self::findAttempt($execution, $attemptNumber);

        if (! $attempt instanceof ActivityAttempt) {
            $attemptId = self::stringValue($execution->current_attempt_id) ?? (string) Str::ulid();

            /** @var ActivityAttempt $attempt */
            $attempt = ActivityAttempt::query()->create([
                'id' => $attemptId,
                'workflow_run_id' => $execution->workflow_run_id,
                'activity_execution_id' => $execution->id,
                'workflow_task_id' => self::taskIdFor($execution, $task),
                'attempt_number' => $attemptNumber,
                'status' => self::statusFor($execution, $task)->value,
                'lease_owner' => self::leaseOwnerFor($execution, $task),
                'started_at' => $execution->started_at ?? $execution->created_at ?? now(),
                'last_heartbeat_at' => $execution->last_heartbeat_at,
                'lease_expires_at' => self::leaseExpiryFor($execution, $task),
                'closed_at' => self::closedAtFor($execution, $task),
            ]);
        }

        $executionChanges = [];

        if ($execution->current_attempt_id !== $attempt->id) {
            $executionChanges['current_attempt_id'] = $attempt->id;
        }

        if ((int) ($execution->attempt_count ?? 0) < $attemptNumber) {
            $executionChanges['attempt_count'] = $attemptNumber;
        }

        if ($executionChanges !== []) {
            $execution->forceFill($executionChanges)
                ->save();
        }

        $execution->forceFill([
            'current_attempt_id' => $attempt->id,
            'attempt_count' => max((int) ($execution->attempt_count ?? 0), $attemptNumber),
        ]);

        if (
            self::matchesExecution($execution, $task)
            && (int) ($task->attempt_count ?? 0) < $attemptNumber
        ) {
            $task->forceFill([
                'attempt_count' => $attemptNumber,
            ])->save();
        }

        return $attempt;
    }

    private static function findAttempt(ActivityExecution $execution, int $attemptNumber): ?ActivityAttempt
    {
        $attemptId = self::stringValue($execution->current_attempt_id);

        if ($attemptId !== null) {
            /** @var ActivityAttempt|null $attempt */
            $attempt = ActivityAttempt::query()
                ->lockForUpdate()
                ->find($attemptId);

            if ($attempt instanceof ActivityAttempt) {
                return $attempt;
            }
        }

        /** @var ActivityAttempt|null $attempt */
        $attempt = ActivityAttempt::query()
            ->lockForUpdate()
            ->where('activity_execution_id', $execution->id)
            ->where('attempt_number', $attemptNumber)
            ->first();

        return $attempt;
    }

    private static function attemptNumber(ActivityExecution $execution): ?int
    {
        $attemptCount = is_int($execution->attempt_count) ? $execution->attempt_count : 0;

        if ($attemptCount > 0) {
            return $attemptCount;
        }

        if (self::stringValue($execution->current_attempt_id) !== null) {
            return 1;
        }

        return match ($execution->status) {
            ActivityStatus::Running,
            ActivityStatus::Completed,
            ActivityStatus::Failed,
            ActivityStatus::Cancelled => 1,
            ActivityStatus::Pending => $execution->started_at !== null || $execution->closed_at !== null ? 1 : null,
        };
    }

    private static function taskIdFor(ActivityExecution $execution, ?WorkflowTask $task): ?string
    {
        return self::matchesExecution($execution, $task) ? $task->id : null;
    }

    private static function leaseOwnerFor(ActivityExecution $execution, ?WorkflowTask $task): ?string
    {
        return self::statusFor($execution, $task) === ActivityAttemptStatus::Running
            && self::matchesExecution($execution, $task)
            ? $task->lease_owner
            : null;
    }

    private static function leaseExpiryFor(ActivityExecution $execution, ?WorkflowTask $task): mixed
    {
        return self::statusFor($execution, $task) === ActivityAttemptStatus::Running
            && self::matchesExecution($execution, $task)
            ? $task->lease_expires_at
            : null;
    }

    private static function closedAtFor(ActivityExecution $execution, ?WorkflowTask $task): mixed
    {
        return self::statusFor($execution, $task) === ActivityAttemptStatus::Running
            ? null
            : ($execution->closed_at ?? $execution->updated_at ?? $execution->started_at ?? $execution->created_at ?? now());
    }

    private static function statusFor(ActivityExecution $execution, ?WorkflowTask $task): ActivityAttemptStatus
    {
        return match ($execution->status) {
            ActivityStatus::Running => ActivityAttemptStatus::Running,
            ActivityStatus::Completed => ActivityAttemptStatus::Completed,
            ActivityStatus::Failed => ActivityAttemptStatus::Failed,
            ActivityStatus::Cancelled => ActivityAttemptStatus::Cancelled,
            ActivityStatus::Pending => self::matchesExecution($execution, $task)
                && $task->status === TaskStatus::Leased
                ? ActivityAttemptStatus::Running
                : ActivityAttemptStatus::Expired,
        };
    }

    private static function matchesExecution(ActivityExecution $execution, ?WorkflowTask $task): bool
    {
        return $task instanceof WorkflowTask
            && $task->task_type === TaskType::Activity
            && $task->workflow_run_id === $execution->workflow_run_id
            && ($task->payload['activity_execution_id'] ?? null) === $execution->id;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
