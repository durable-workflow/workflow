<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;

/**
 * Acquires activity rows in the canonical order used by workers and timeout
 * enforcement: attempt, execution, workflow run, then workflow task.
 *
 * This helper owns the shared attempt/execution prefix. Callers acquire the
 * related run and task only after these rows have been returned.
 */
final class ActivityRowLockOrder
{
    /**
     * @return array{
     *     attempt: ActivityAttempt|null,
     *     execution: ActivityExecution|null,
     *     snapshot_attempt_id: string|null
     * }
     */
    public static function lockForExecution(string $executionId): array
    {
        /** @var ActivityExecution|null $snapshot */
        $snapshot = ActivityExecution::query()->find($executionId);
        $snapshotAttemptId = $snapshot instanceof ActivityExecution
            ? self::runningAttemptId($snapshot)
            : null;

        /** @var ActivityAttempt|null $attempt */
        $attempt = $snapshotAttemptId === null
            ? null
            : ActivityAttempt::query()
                ->lockForUpdate()
                ->find($snapshotAttemptId);

        /** @var ActivityExecution|null $execution */
        $execution = ActivityExecution::query()
            ->lockForUpdate()
            ->find($executionId);

        return [
            'attempt' => $attempt,
            'execution' => $execution,
            'snapshot_attempt_id' => $snapshotAttemptId,
        ];
    }

    /**
     * @return array{
     *     attempt: ActivityAttempt|null,
     *     execution: ActivityExecution|null,
     *     snapshot_attempt_id: string
     * }
     */
    public static function lockForAttempt(string $attemptId): array
    {
        /** @var ActivityAttempt|null $attempt */
        $attempt = ActivityAttempt::query()
            ->lockForUpdate()
            ->find($attemptId);

        /** @var ActivityExecution|null $execution */
        $execution = $attempt instanceof ActivityAttempt
            ? ActivityExecution::query()
                ->lockForUpdate()
                ->find($attempt->activity_execution_id)
            : null;

        return [
            'attempt' => $attempt,
            'execution' => $execution,
            'snapshot_attempt_id' => $attemptId,
        ];
    }

    private static function runningAttemptId(ActivityExecution $execution): ?string
    {
        if ($execution->status !== ActivityStatus::Running) {
            return null;
        }

        $attemptId = $execution->current_attempt_id;

        return is_string($attemptId) && $attemptId !== ''
            ? $attemptId
            : null;
    }
}
