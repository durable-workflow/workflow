<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class ActivityOutcomeRejection
{
    public static function recordExpiredAttempt(ActivityAttempt $original, string $outcome): ?string
    {
        $connection = $original->getConnection();
        $runId = (string) $original->workflow_run_id;

        return SqliteTransaction::run(static function () use ($original, $outcome, $connection, $runId): ?string {
            // Retention owns this same run lock before deleting detail rows.
            // A refused expired attempt never needs the business task locks.
            $run = WorkflowRun::on($connection->getName())->whereKey($runId)->lockForUpdate()->first();
            if (! $run instanceof WorkflowRun || $run->details_pruned_at !== null) {
                return 'stale_attempt';
            }
            $execution = ActivityExecution::on($connection->getName())->find($original->activity_execution_id);
            $task = WorkflowTask::on($connection->getName())->find($original->workflow_task_id);
            if (! $execution instanceof ActivityExecution || ! $task instanceof WorkflowTask) {
                return 'stale_attempt';
            }
            $attempt = ActivityAttempt::on($connection->getName())->find($original->id);
            if (! $attempt instanceof ActivityAttempt || $attempt->id !== $original->id) {
                return 'stale_attempt';
            }
            $reason = match ($attempt->status) {
                ActivityAttemptStatus::Expired => self::expiredAttemptReason($run, $execution),
                ActivityAttemptStatus::Running => self::runningExpiredReason($run, $execution, $task),
                default => 'stale_attempt',
            };
            if ($reason === null) {
                return null;
            }
            self::recordBoundAttempt($run, $execution, $task, $attempt, $outcome, $reason);

            return $reason;
        }, admission: static function () use ($connection, $runId): void {
            WorkflowRun::on($connection->getName())->whereKey($runId)->toBase()->update([
                'id' => $runId,
            ]);
        }, connection: $connection);
    }

    /**
     * The caller holds the original run mutation lock. Business writers also
     * retain task/execution locks; expired audit-only calls read their immutable
     * identity bindings without taking those locks in the reverse order.
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
        $attempt = ActivityAttempt::query()->lockForUpdate()->find($attemptId);
        if (! $attempt instanceof ActivityAttempt || $attempt->id !== $attemptId
            || $attempt->attempt_number !== $attemptNumber) {
            return null;
        }

        return self::recordBoundAttempt($run, $execution, $task, $attempt, $outcome, $reason);
    }

    private static function recordBoundAttempt(
        WorkflowRun $run,
        ActivityExecution $execution,
        WorkflowTask $task,
        ActivityAttempt $attempt,
        string $outcome,
        string $reason,
    ): ?WorkflowHistoryEvent {
        if ($run->details_pruned_at !== null || ! in_array($outcome, ['completed', 'failed'], true)) {
            return null;
        }
        if (! is_string($attempt->lease_owner)
            || $attempt->lease_owner === ''
            || $attempt->workflow_task_id !== $task->id
            || $attempt->activity_execution_id !== $execution->id
            || $attempt->workflow_run_id !== $run->id
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
            ->where('payload->activity_attempt_id', $attempt->id)
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
        if ($attempt->status === ActivityAttemptStatus::Expired) {
            return $reason === self::expiredAttemptReason($run, $execution);
        }

        return $attempt->status === ActivityAttemptStatus::Running
            && self::runningExpiredReason($run, $execution, $task) === $reason;
    }

    private static function runningExpiredReason(
        WorkflowRun $run,
        ActivityExecution $execution,
        WorkflowTask $task,
    ): ?string {
        $reason = ActivityAttemptAuthority::expiredReason($task, $execution, now(), $run);
        if ($reason === null) {
            return null;
        }
        $closedReason = self::expiredAttemptReason($run, $execution);

        return $closedReason === 'stale_attempt' ? $reason : $closedReason;
    }

    private static function expiredAttemptReason(WorkflowRun $run, ActivityExecution $execution): string
    {
        if (in_array($run->status, [RunStatus::Cancelled, RunStatus::Terminated], true)
            && ! ActivityAbandonment::allows($run, $execution)) {
            return $run->status === RunStatus::Terminated ? 'run_terminated' : 'run_cancelled';
        }

        return 'stale_attempt';
    }
}
