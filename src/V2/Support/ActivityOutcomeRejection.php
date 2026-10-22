<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class ActivityOutcomeRejection
{
    /**
     * Record only under the original task/execution/run mutation locks.
     */
    public static function record(
        WorkflowRun $run,
        ActivityExecution $execution,
        WorkflowTask $task,
        string $attemptId,
        int $attemptNumber,
        string $outcome,
        string $reason,
    ): ?WorkflowHistoryEvent {
        if ($run->details_pruned_at !== null || ! in_array($outcome, ['completed', 'failed'], true)) {
            return null;
        }
        $attempt = ActivityAttempt::query()->lockForUpdate()->find($attemptId);
        if (! $attempt instanceof ActivityAttempt
            || $attempt->id !== $attemptId
            || ! is_string($attempt->lease_owner)
            || $attempt->lease_owner === ''
            || $attempt->workflow_task_id !== $task->id
            || $attempt->activity_execution_id !== $execution->id
            || $attempt->workflow_run_id !== $run->id
            || $attempt->attempt_number !== $attemptNumber
            || $execution->workflow_run_id !== $run->id
            || $task->workflow_run_id !== $run->id
            || $task->namespace !== $run->namespace
            || ($task->payload['activity_execution_id'] ?? null) !== $execution->id
            || ! self::lostAuthority($attempt, $task, $execution, $run, $reason)) {
            return null;
        }

        $existing = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityOutcomeRejected)
            ->where('workflow_task_id', $task->id)
            ->where('payload->activity_attempt_id', $attemptId)
            ->first();
        if ($existing instanceof WorkflowHistoryEvent) {
            return $existing;
        }

        // Passing the stored ID, rather than a task model, deliberately omits
        // the generic task snapshot and its unrestricted dispatch diagnostics.
        $event = WorkflowHistoryEvent::record($run, HistoryEventType::ActivityOutcomeRejected, [
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attempt->id,
            'attempt_number' => $attempt->attempt_number,
            'lease_owner' => $attempt->lease_owner,
            'outcome' => $outcome,
            'reason' => $reason,
            'observed_at' => now()
                ->toJSON(),
        ], (string) $task->id);
        RunSummaryProjector::projectActivityOutcomeRejection($run, $event);
        RunTimelineProjector::projectActivityOutcomeRejection($run, $event);

        return $event;
    }

    private static function lostAuthority(
        ActivityAttempt $attempt,
        WorkflowTask $task,
        ActivityExecution $execution,
        WorkflowRun $run,
        string $reason,
    ): bool {
        if ($reason === 'stale_attempt' && $attempt->status === ActivityAttemptStatus::Expired) {
            return true;
        }

        return $attempt->status === ActivityAttemptStatus::Running
            && ActivityAttemptAuthority::expiredReason($task, $execution, now(), $run) === $reason;
    }
}
