<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;

final class HistoryTimeline
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing(['historyEvents', 'commands', 'tasks', 'activityExecutions', 'timers', 'failures']);

        /** @var Collection<string, WorkflowCommand> $commands */
        $commands = $run->commands->keyBy('id');
        /** @var Collection<string, WorkflowTask> $tasks */
        $tasks = $run->tasks->keyBy('id');
        /** @var Collection<string, ActivityExecution> $activities */
        $activities = $run->activityExecutions->keyBy('id');
        /** @var Collection<string, WorkflowTimer> $timers */
        $timers = $run->timers->keyBy('id');
        /** @var Collection<string, WorkflowFailure> $failures */
        $failures = $run->failures->keyBy('id');

        return $run->historyEvents
            ->sortBy('sequence')
            ->map(
                static fn (WorkflowHistoryEvent $event): array => self::mapEvent(
                    $event,
                    $commands,
                    $tasks,
                    $activities,
                    $timers,
                    $failures,
                )
            )
            ->values()
            ->all();
    }

    private static function mapEvent(
        WorkflowHistoryEvent $event,
        Collection $commands,
        Collection $tasks,
        Collection $activities,
        Collection $timers,
        Collection $failures,
    ): array {
        /** @var array<string, mixed> $payload */
        $payload = is_array($event->payload) ? $event->payload : [];
        $commandId = self::stringValue($event->workflow_command_id)
            ?? self::stringValue($payload['workflow_command_id'] ?? null);
        $taskId = self::stringValue($event->workflow_task_id);
        $activityId = self::stringValue($payload['activity_execution_id'] ?? null);
        $timerId = self::stringValue($payload['timer_id'] ?? null);
        $failureId = self::stringValue($payload['failure_id'] ?? null);

        /** @var WorkflowCommand|null $command */
        $command = $commandId === null ? null : $commands->get($commandId);
        /** @var WorkflowTask|null $task */
        $task = $taskId === null ? null : $tasks->get($taskId);
        /** @var ActivityExecution|null $activity */
        $activity = $activityId === null ? null : $activities->get($activityId);
        /** @var WorkflowTimer|null $timer */
        $timer = $timerId === null ? null : $timers->get($timerId);
        /** @var WorkflowFailure|null $failure */
        $failure = $failureId === null ? null : $failures->get($failureId);

        return [
            'id' => $event->id,
            'sequence' => $event->sequence,
            'type' => $event->event_type->value,
            'kind' => self::kindFor($event->event_type),
            'summary' => self::summaryFor($event, $command, $task, $activity, $timer, $failure),
            'recorded_at' => self::timestamp($event->recorded_at),
            'command_id' => $commandId,
            'command_sequence' => $command?->command_sequence,
            'task_id' => $taskId,
            'command_type' => $command?->command_type?->value ?? self::stringValue($payload['command_type'] ?? null),
            'command_status' => $command?->status?->value,
            'command_outcome' => $command?->outcome?->value ?? self::stringValue($payload['outcome'] ?? null),
            'command_rejection_reason' => $command?->rejection_reason ?? self::stringValue(
                $payload['rejection_reason'] ?? null
            ),
            'workflow_sequence' => self::intValue($payload['sequence'] ?? null),
            'signal_wait_id' => self::stringValue($payload['signal_wait_id'] ?? null),
            'signal_name' => $command?->targetName() ?? self::stringValue($payload['signal_name'] ?? null),
            'update_name' => $command?->targetName() ?? self::stringValue($payload['update_name'] ?? null),
            'activity_execution_id' => $activity?->id ?? $activityId,
            'activity_type' => $activity?->activity_type ?? self::stringValue($payload['activity_type'] ?? null),
            'activity_class' => $activity?->activity_class ?? self::stringValue($payload['activity_class'] ?? null),
            'activity_status' => $activity?->status?->value,
            'timer_id' => $timer?->id ?? $timerId,
            'delay_seconds' => $timer?->delay_seconds ?? self::intValue($payload['delay_seconds'] ?? null),
            'child_workflow_instance_id' => self::stringValue($payload['child_workflow_instance_id'] ?? null),
            'child_workflow_run_id' => self::stringValue($payload['child_workflow_run_id'] ?? null),
            'child_workflow_type' => self::stringValue($payload['child_workflow_type'] ?? null),
            'child_workflow_class' => self::stringValue($payload['child_workflow_class'] ?? null),
            'child_status' => self::stringValue($payload['child_status'] ?? null),
            'failure_id' => $failure?->id ?? $failureId,
            'exception_class' => $failure?->exception_class ?? self::stringValue($payload['exception_class'] ?? null),
            'message' => $failure?->message ?? self::stringValue($payload['message'] ?? null),
            'closed_reason' => $payload['closed_reason'] ?? null,
            'command' => self::commandMetadata($command, $payload, $commandId),
            'task' => self::taskMetadata($task, $taskId),
            'activity' => self::activityMetadata($activity, $payload, $activityId),
            'timer' => self::timerMetadata($timer, $payload, $timerId),
            'child' => self::childMetadata($payload),
            'failure' => self::failureMetadata($failure, $payload, $failureId),
        ];
    }

    private static function kindFor(HistoryEventType $eventType): string
    {
        return match ($eventType) {
            HistoryEventType::StartAccepted,
            HistoryEventType::StartRejected,
            HistoryEventType::SignalReceived,
            HistoryEventType::UpdateAccepted,
            HistoryEventType::RepairRequested,
            HistoryEventType::CancelRequested,
            HistoryEventType::TerminateRequested => 'command',
            HistoryEventType::SignalWaitOpened,
            HistoryEventType::SignalApplied => 'signal',
            HistoryEventType::UpdateApplied,
            HistoryEventType::UpdateCompleted => 'update',
            HistoryEventType::ChildWorkflowScheduled,
            HistoryEventType::ChildRunStarted,
            HistoryEventType::ChildRunCompleted,
            HistoryEventType::ChildRunFailed,
            HistoryEventType::ChildRunCancelled,
            HistoryEventType::ChildRunTerminated => 'child',
            HistoryEventType::ActivityScheduled,
            HistoryEventType::ActivityCompleted,
            HistoryEventType::ActivityFailed => 'activity',
            HistoryEventType::TimerScheduled,
            HistoryEventType::TimerFired => 'timer',
            default => 'workflow',
        };
    }

    private static function summaryFor(
        WorkflowHistoryEvent $event,
        ?WorkflowCommand $command,
        ?WorkflowTask $task,
        ?ActivityExecution $activity,
        ?WorkflowTimer $timer,
        ?WorkflowFailure $failure,
    ): string {
        /** @var array<string, mixed> $payload */
        $payload = is_array($event->payload) ? $event->payload : [];
        $activityLabel = self::displayLabel(
            $activity?->activity_type
            ?? $payload['activity_type']
            ?? $activity?->activity_class
            ?? $payload['activity_class']
            ?? 'activity'
        );
        $delaySeconds = $timer?->delay_seconds ?? $payload['delay_seconds'] ?? null;
        $message = $failure?->message ?? $payload['message'] ?? null;
        $outcome = $command?->outcome?->value ?? $payload['outcome'] ?? null;
        $rejectionReason = $command?->rejection_reason ?? $payload['rejection_reason'] ?? null;
        $signalName = $command?->targetName() ?? $payload['signal_name'] ?? null;
        $updateName = $command?->targetName() ?? $payload['update_name'] ?? null;
        $childLabel = self::displayLabel(
            $payload['child_workflow_type']
            ?? $payload['child_workflow_class']
            ?? $payload['child_workflow_run_id']
            ?? 'child workflow'
        );

        return match ($event->event_type) {
            HistoryEventType::StartAccepted => $outcome === null
                ? 'Start accepted.'
                : sprintf('Start accepted as %s.', $outcome),
            HistoryEventType::StartRejected => $rejectionReason === null
                ? 'Start rejected.'
                : sprintf('Start rejected: %s.', $rejectionReason),
            HistoryEventType::WorkflowStarted => 'Workflow run started.',
            HistoryEventType::WorkflowContinuedAsNew => sprintf(
                'Continued as new on run %s.',
                self::stringValue($payload['continued_to_run_id'] ?? null) ?? 'unknown'
            ),
            HistoryEventType::ChildWorkflowScheduled => sprintf('Scheduled child workflow %s.', $childLabel),
            HistoryEventType::ChildRunStarted => sprintf('Child workflow %s started.', $childLabel),
            HistoryEventType::ChildRunCompleted => sprintf('Child workflow %s completed.', $childLabel),
            HistoryEventType::ChildRunFailed => $message === null
                ? sprintf('Child workflow %s failed.', $childLabel)
                : sprintf('Child workflow %s failed: %s.', $childLabel, $message),
            HistoryEventType::ChildRunCancelled => sprintf('Child workflow %s cancelled.', $childLabel),
            HistoryEventType::ChildRunTerminated => sprintf('Child workflow %s terminated.', $childLabel),
            HistoryEventType::SignalWaitOpened => $signalName === null
                ? 'Waiting for signal.'
                : sprintf('Waiting for signal %s.', $signalName),
            HistoryEventType::SignalReceived => $signalName === null
                ? 'Signal received.'
                : sprintf('Signal %s received.', $signalName),
            HistoryEventType::SignalApplied => $signalName === null
                ? 'Signal applied.'
                : sprintf('Applied signal %s.', $signalName),
            HistoryEventType::UpdateAccepted => $updateName === null
                ? 'Update accepted.'
                : sprintf('Accepted update %s.', $updateName),
            HistoryEventType::UpdateApplied => $updateName === null
                ? 'Update applied.'
                : sprintf('Applied update %s.', $updateName),
            HistoryEventType::UpdateCompleted => $message === null
                ? ($updateName === null ? 'Update completed.' : sprintf('Completed update %s.', $updateName))
                : ($updateName === null
                    ? sprintf('Update failed: %s.', $message)
                    : sprintf('Update %s failed: %s.', $updateName, $message)),
            HistoryEventType::RepairRequested => match ($outcome) {
                'repair_dispatched' => sprintf(
                    'Repair recreated %s task.',
                    self::displayLabel(
                        $task?->task_type?->value
                        ?? self::stringValue($payload['task_type'] ?? null)
                        ?? 'workflow'
                    ),
                ),
                'repair_not_needed' => 'Repair accepted; the run already had a durable resume source.',
                default => 'Repair accepted.',
            },
            HistoryEventType::CancelRequested => 'Cancel requested.',
            HistoryEventType::WorkflowCancelled => 'Workflow cancelled.',
            HistoryEventType::TerminateRequested => 'Terminate requested.',
            HistoryEventType::WorkflowTerminated => 'Workflow terminated.',
            HistoryEventType::ActivityScheduled => sprintf('Scheduled %s.', $activityLabel),
            HistoryEventType::ActivityCompleted => sprintf('Completed %s.', $activityLabel),
            HistoryEventType::ActivityFailed => $message === null
                ? sprintf('Failed %s.', $activityLabel)
                : sprintf('Failed %s: %s.', $activityLabel, $message),
            HistoryEventType::TimerScheduled => $delaySeconds === null
                ? 'Scheduled timer.'
                : sprintf('Scheduled timer for %s.', self::durationLabel($delaySeconds)),
            HistoryEventType::TimerFired => $delaySeconds === null
                ? 'Timer fired.'
                : sprintf('Timer fired after %s.', self::durationLabel($delaySeconds)),
            HistoryEventType::WorkflowCompleted => 'Workflow completed.',
            HistoryEventType::WorkflowFailed => $message === null
                ? 'Workflow failed.'
                : sprintf('Workflow failed: %s.', $message),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function childMetadata(array $payload): ?array
    {
        if (
            ! array_key_exists('child_workflow_instance_id', $payload)
            && ! array_key_exists('child_workflow_run_id', $payload)
            && ! array_key_exists('child_workflow_type', $payload)
            && ! array_key_exists('child_workflow_class', $payload)
        ) {
            return null;
        }

        return [
            'instance_id' => self::stringValue($payload['child_workflow_instance_id'] ?? null),
            'run_id' => self::stringValue($payload['child_workflow_run_id'] ?? null),
            'type' => self::stringValue($payload['child_workflow_type'] ?? null),
            'class' => self::stringValue($payload['child_workflow_class'] ?? null),
            'status' => self::stringValue($payload['child_status'] ?? null),
            'run_number' => self::intValue($payload['child_run_number'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function commandMetadata(?WorkflowCommand $command, array $payload, ?string $commandId): ?array
    {
        if (
            $command === null
            && $commandId === null
            && ! array_key_exists('outcome', $payload)
            && ! array_key_exists('rejection_reason', $payload)
            && ! array_key_exists('command_type', $payload)
        ) {
            return null;
        }

        return [
            'id' => $command?->id ?? $commandId,
            'sequence' => $command?->command_sequence,
            'type' => $command?->command_type?->value ?? self::stringValue($payload['command_type'] ?? null),
            'target_name' => $command?->targetName()
                ?? self::stringValue($payload['signal_name'] ?? null)
                ?? self::stringValue($payload['update_name'] ?? null),
            'source' => $command?->source,
            'caller_label' => $command?->callerLabel(),
            'auth_status' => $command?->authStatus(),
            'auth_method' => $command?->authMethod(),
            'request_method' => $command?->requestMethod(),
            'request_path' => $command?->requestPath(),
            'request_route_name' => $command?->requestRouteName(),
            'request_fingerprint' => $command?->requestFingerprint(),
            'status' => $command?->status?->value,
            'outcome' => $command?->outcome?->value ?? self::stringValue($payload['outcome'] ?? null),
            'rejection_reason' => $command?->rejection_reason ?? self::stringValue(
                $payload['rejection_reason'] ?? null
            ),
            'accepted_at' => self::timestamp($command?->accepted_at),
            'applied_at' => self::timestamp($command?->applied_at),
            'rejected_at' => self::timestamp($command?->rejected_at),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function taskMetadata(?WorkflowTask $task, ?string $taskId): ?array
    {
        if ($task === null && $taskId === null) {
            return null;
        }

        return [
            'id' => $task?->id ?? $taskId,
            'type' => $task?->task_type?->value,
            'status' => $task?->status?->value,
            'available_at' => self::timestamp($task?->available_at),
            'leased_at' => self::timestamp($task?->leased_at),
            'lease_expires_at' => self::timestamp($task?->lease_expires_at),
            'attempt_count' => $task?->attempt_count,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function activityMetadata(?ActivityExecution $activity, array $payload, ?string $activityId): ?array
    {
        if (
            $activity === null
            && $activityId === null
            && ! array_key_exists('activity_type', $payload)
            && ! array_key_exists('activity_class', $payload)
        ) {
            return null;
        }

        return [
            'id' => $activity?->id ?? $activityId,
            'sequence' => $activity?->sequence ?? self::intValue($payload['sequence'] ?? null),
            'type' => $activity?->activity_type ?? self::stringValue($payload['activity_type'] ?? null),
            'class' => $activity?->activity_class ?? self::stringValue($payload['activity_class'] ?? null),
            'status' => $activity?->status?->value,
            'attempt_count' => $activity?->attempt_count,
            'connection' => $activity?->connection,
            'queue' => $activity?->queue,
            'started_at' => self::timestamp($activity?->started_at),
            'closed_at' => self::timestamp($activity?->closed_at),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function timerMetadata(?WorkflowTimer $timer, array $payload, ?string $timerId): ?array
    {
        if (
            $timer === null
            && $timerId === null
            && ! array_key_exists('delay_seconds', $payload)
            && ! array_key_exists('fire_at', $payload)
            && ! array_key_exists('fired_at', $payload)
        ) {
            return null;
        }

        return [
            'id' => $timer?->id ?? $timerId,
            'sequence' => $timer?->sequence ?? self::intValue($payload['sequence'] ?? null),
            'status' => $timer?->status?->value,
            'delay_seconds' => $timer?->delay_seconds ?? self::intValue($payload['delay_seconds'] ?? null),
            'fire_at' => self::timestamp($timer?->fire_at ?? self::stringValue($payload['fire_at'] ?? null)),
            'fired_at' => self::timestamp($timer?->fired_at ?? self::stringValue($payload['fired_at'] ?? null)),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function failureMetadata(?WorkflowFailure $failure, array $payload, ?string $failureId): ?array
    {
        if (
            $failure === null
            && $failureId === null
            && ! array_key_exists('message', $payload)
            && ! array_key_exists('exception_class', $payload)
            && ! array_key_exists('source_kind', $payload)
            && ! array_key_exists('source_id', $payload)
        ) {
            return null;
        }

        return [
            'id' => $failure?->id ?? $failureId,
            'source_kind' => $failure?->source_kind ?? self::stringValue($payload['source_kind'] ?? null),
            'source_id' => $failure?->source_id ?? self::stringValue($payload['source_id'] ?? null),
            'propagation_kind' => $failure?->propagation_kind,
            'handled' => $failure?->handled,
            'exception_class' => $failure?->exception_class ?? self::stringValue($payload['exception_class'] ?? null),
            'message' => $failure?->message ?? self::stringValue($payload['message'] ?? null),
            'file' => $failure?->file,
            'line' => $failure?->line,
        ];
    }

    private static function displayLabel(string $value): string
    {
        return str_contains($value, '\\')
            ? class_basename($value)
            : $value;
    }

    private static function durationLabel(int $seconds): string
    {
        return $seconds === 1
            ? '1 second'
            : sprintf('%d seconds', $seconds);
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        return self::stringValue($value);
    }
}
