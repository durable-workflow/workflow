<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class ActivityCancellation
{
    public static function record(
        WorkflowRun $run,
        ActivityExecution $execution,
        ?WorkflowTask $task = null,
        WorkflowCommand|string|null $command = null,
    ): ?WorkflowHistoryEvent {
        $cancelledAt = now();

        if ($execution->status !== ActivityStatus::Cancelled || $execution->closed_at === null) {
            $execution->forceFill([
                'status' => ActivityStatus::Cancelled,
                'closed_at' => $execution->closed_at ?? $cancelledAt,
            ])->save();
        }

        $attempt = self::currentAttempt($execution);

        if ($attempt instanceof ActivityAttempt && $attempt->status !== ActivityAttemptStatus::Cancelled) {
            $attempt->forceFill([
                'status' => ActivityAttemptStatus::Cancelled,
                'lease_expires_at' => null,
                'closed_at' => $attempt->closed_at ?? $cancelledAt,
            ])->save();
        }

        if ($task instanceof WorkflowTask && ($task->status !== TaskStatus::Cancelled || $task->lease_expires_at !== null)) {
            $task->forceFill([
                'status' => TaskStatus::Cancelled,
                'lease_expires_at' => null,
                'last_error' => null,
            ])->save();
        }

        if (self::alreadyRecorded($run, $execution->id)) {
            return null;
        }

        $commandId = $command instanceof WorkflowCommand ? $command->id : (is_string($command) ? $command : null);

        return WorkflowHistoryEvent::record($run, HistoryEventType::ActivityCancelled, [
            'workflow_command_id' => $commandId,
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attempt?->id ?? $execution->current_attempt_id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'attempt_number' => $execution->attempt_count,
            'cancelled_at' => ($execution->closed_at ?? $cancelledAt)
                ->toJSON(),
            'activity' => ActivitySnapshot::fromExecution($execution),
            'activity_attempt' => self::attemptSnapshot($attempt),
        ], $task, $command);
    }

    private static function currentAttempt(ActivityExecution $execution): ?ActivityAttempt
    {
        $attemptId = $execution->current_attempt_id;

        if (! is_string($attemptId) || $attemptId === '') {
            return null;
        }

        /** @var ActivityAttempt|null $attempt */
        $attempt = ActivityAttempt::query()
            ->where('workflow_run_id', $execution->workflow_run_id)
            ->where('activity_execution_id', $execution->id)
            ->whereKey($attemptId)
            ->first();

        return $attempt;
    }

    private static function alreadyRecorded(WorkflowRun $run, string $activityExecutionId): bool
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityCancelled->value)
            ->get()
            ->contains(
                static fn (WorkflowHistoryEvent $event): bool => ($event->payload['activity_execution_id'] ?? null) === $activityExecutionId
            );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function attemptSnapshot(?ActivityAttempt $attempt): ?array
    {
        if (! $attempt instanceof ActivityAttempt) {
            return null;
        }

        return array_filter([
            'id' => $attempt->id,
            'activity_execution_id' => $attempt->activity_execution_id,
            'task_id' => $attempt->workflow_task_id,
            'attempt_number' => $attempt->attempt_number,
            'status' => $attempt->status?->value,
            'lease_owner' => $attempt->lease_owner,
            'started_at' => $attempt->started_at?->toJSON(),
            'last_heartbeat_at' => $attempt->last_heartbeat_at?->toJSON(),
            'lease_expires_at' => $attempt->lease_expires_at?->toJSON(),
            'closed_at' => $attempt->closed_at?->toJSON(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
