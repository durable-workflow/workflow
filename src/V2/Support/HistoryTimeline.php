<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
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
        $commandMetadata = self::commandMetadata($event, $command, $payload, $commandId);
        $taskMetadata = self::taskMetadata($event, $task, $payload, $taskId);
        $activityMetadata = self::activityMetadata($event, $activity, $payload, $activityId, $failure);
        $timerMetadata = self::timerMetadata($event, $timer, $payload, $timerId);
        $childMetadata = self::childMetadata($event, $payload);
        $failureMetadata = self::failureMetadata($event, $failure, $payload, $failureId);
        $parallelMetadata = ParallelChildGroup::metadataFromPayload($payload);
        $parallelMetadataPath = ParallelChildGroup::metadataPathFromPayload($payload);

        return [
            'id' => $event->id,
            'sequence' => $event->sequence,
            'type' => $event->event_type->value,
            'kind' => self::kindFor($event->event_type),
            'entry_kind' => 'point',
            'source_kind' => self::sourceKindFor($event),
            'source_id' => self::sourceIdFor(
                $event,
                $commandMetadata,
                $taskMetadata,
                $activityMetadata,
                $timerMetadata,
                $childMetadata,
                $failureMetadata,
            ),
            'summary' => self::summaryFor(
                $event,
                $payload,
                $commandMetadata,
                $taskMetadata,
                $activityMetadata,
                $timerMetadata,
                $childMetadata,
                $failureMetadata,
            ),
            'recorded_at' => self::timestamp($event->recorded_at),
            'command_id' => $commandId,
            'command_sequence' => $commandMetadata['sequence'] ?? null,
            'requested_run_id' => $commandMetadata['requested_run_id'] ?? null,
            'resolved_run_id' => $commandMetadata['resolved_run_id'] ?? null,
            'task_id' => $taskId,
            'command_type' => $commandMetadata['type'] ?? null,
            'command_status' => $commandMetadata['status'] ?? null,
            'command_outcome' => $commandMetadata['outcome'] ?? null,
            'command_rejection_reason' => $commandMetadata['rejection_reason'] ?? null,
            'workflow_sequence' => self::intValue($payload['sequence'] ?? null),
            'signal_wait_id' => self::stringValue($payload['signal_wait_id'] ?? null),
            'condition_wait_id' => self::stringValue($payload['condition_wait_id'] ?? null),
            'signal_name' => $commandMetadata['target_name'] ?? self::stringValue($payload['signal_name'] ?? null),
            'update_name' => $commandMetadata['target_name'] ?? self::stringValue($payload['update_name'] ?? null),
            'version_change_id' => self::stringValue($payload['change_id'] ?? null),
            'version' => self::intValue($payload['version'] ?? null),
            'version_min_supported' => self::intValue($payload['min_supported'] ?? null),
            'version_max_supported' => self::intValue($payload['max_supported'] ?? null),
            'activity_execution_id' => $activityMetadata['id'] ?? null,
            'activity_type' => $activityMetadata['type'] ?? null,
            'activity_class' => $activityMetadata['class'] ?? null,
            'activity_status' => $activityMetadata['status'] ?? null,
            'timer_id' => $timerMetadata['id'] ?? null,
            'delay_seconds' => self::intValue($payload['timeout_seconds'] ?? null) ?? $timerMetadata['delay_seconds'] ?? null,
            'child_call_id' => $childMetadata['child_call_id'] ?? null,
            'child_workflow_instance_id' => $childMetadata['instance_id'] ?? null,
            'child_workflow_run_id' => $childMetadata['run_id'] ?? null,
            'child_workflow_type' => $childMetadata['type'] ?? null,
            'child_workflow_class' => $childMetadata['class'] ?? null,
            'child_status' => $childMetadata['status'] ?? null,
            'parallel_group_kind' => $parallelMetadata['parallel_group_kind'] ?? null,
            'parallel_group_id' => $parallelMetadata['parallel_group_id'] ?? null,
            'parallel_group_base_sequence' => $parallelMetadata['parallel_group_base_sequence'] ?? null,
            'parallel_group_size' => $parallelMetadata['parallel_group_size'] ?? null,
            'parallel_group_index' => $parallelMetadata['parallel_group_index'] ?? null,
            'parallel_group_path' => $parallelMetadataPath,
            'failure_id' => $failureMetadata['id'] ?? null,
            'exception_class' => $failureMetadata['exception_class'] ?? null,
            'message' => $failureMetadata['message'] ?? null,
            'closed_reason' => $payload['closed_reason'] ?? null,
            'command' => $commandMetadata,
            'task' => $taskMetadata,
            'activity' => $activityMetadata,
            'timer' => $timerMetadata,
            'child' => $childMetadata,
            'failure' => $failureMetadata,
        ];
    }

    private static function kindFor(HistoryEventType $eventType): string
    {
        return match ($eventType) {
            HistoryEventType::StartAccepted,
            HistoryEventType::StartRejected,
            HistoryEventType::SignalReceived,
            HistoryEventType::UpdateAccepted,
            HistoryEventType::UpdateRejected,
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
            HistoryEventType::ConditionWaitOpened,
            HistoryEventType::ConditionWaitSatisfied,
            HistoryEventType::ConditionWaitTimedOut => 'condition',
            HistoryEventType::ActivityScheduled,
            HistoryEventType::ActivityStarted,
            HistoryEventType::ActivityCompleted,
            HistoryEventType::ActivityFailed => 'activity',
            HistoryEventType::SideEffectRecorded => 'side_effect',
            HistoryEventType::VersionMarkerRecorded => 'version',
            HistoryEventType::TimerScheduled,
            HistoryEventType::TimerFired => 'timer',
            default => 'workflow',
        };
    }

    private static function summaryFor(
        WorkflowHistoryEvent $event,
        array $payload,
        ?array $command,
        ?array $task,
        ?array $activity,
        ?array $timer,
        ?array $child,
        ?array $failure,
    ): string {
        $activityLabel = self::displayLabel(
            $activity['type']
            ?? $activity['class']
            ?? 'activity'
        );
        $delaySeconds = $timer['delay_seconds'] ?? null;
        $message = $failure['message'] ?? null;
        $outcome = $command['outcome'] ?? null;
        $rejectionReason = $command['rejection_reason'] ?? null;
        $signalName = $command['target_name'] ?? self::stringValue($payload['signal_name'] ?? null);
        $updateName = $command['target_name'] ?? self::stringValue($payload['update_name'] ?? null);
        $changeId = self::stringValue($payload['change_id'] ?? null);
        $version = self::intValue($payload['version'] ?? null);
        $timerKind = self::stringValue($payload['timer_kind'] ?? null);
        $childLabel = self::displayLabel(
            $child['type']
            ?? $child['class']
            ?? $child['run_id']
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
            HistoryEventType::ConditionWaitOpened => ($payload['timeout_seconds'] ?? null) === null
                ? 'Waiting for condition.'
                : sprintf(
                    'Waiting for condition or timeout after %s.',
                    self::durationLabel(self::intValue($payload['timeout_seconds'] ?? null) ?? 0),
                ),
            HistoryEventType::ConditionWaitSatisfied => 'Condition satisfied.',
            HistoryEventType::ConditionWaitTimedOut => sprintf(
                'Condition timed out after %s.',
                self::durationLabel(self::intValue($payload['timeout_seconds'] ?? null) ?? $delaySeconds ?? 0),
            ),
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
            HistoryEventType::UpdateRejected => match (true) {
                $updateName !== null && $rejectionReason !== null => sprintf(
                    'Rejected update %s: %s.',
                    $updateName,
                    $rejectionReason,
                ),
                $updateName !== null => sprintf('Rejected update %s.', $updateName),
                $rejectionReason !== null => sprintf('Update rejected: %s.', $rejectionReason),
                default => 'Update rejected.',
            },
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
                        $task['type']
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
            HistoryEventType::ActivityStarted => sprintf('Started %s.', $activityLabel),
            HistoryEventType::ActivityCompleted => sprintf('Completed %s.', $activityLabel),
            HistoryEventType::ActivityFailed => $message === null
                ? sprintf('Failed %s.', $activityLabel)
                : sprintf('Failed %s: %s.', $activityLabel, $message),
            HistoryEventType::SideEffectRecorded => 'Recorded side effect.',
            HistoryEventType::VersionMarkerRecorded => match (true) {
                $changeId !== null && $version !== null => sprintf('Recorded version marker %s = %d.', $changeId, $version),
                $changeId !== null => sprintf('Recorded version marker %s.', $changeId),
                $version !== null => sprintf('Recorded version marker %d.', $version),
                default => 'Recorded version marker.',
            },
            HistoryEventType::TimerScheduled => $timerKind === 'condition_timeout'
                ? ($delaySeconds === null
                    ? 'Scheduled condition timeout.'
                    : sprintf('Scheduled condition timeout for %s.', self::durationLabel($delaySeconds)))
                : ($delaySeconds === null
                    ? 'Scheduled timer.'
                    : sprintf('Scheduled timer for %s.', self::durationLabel($delaySeconds))),
            HistoryEventType::TimerFired => $timerKind === 'condition_timeout'
                ? ($delaySeconds === null
                    ? 'Condition timeout fired.'
                    : sprintf('Condition timeout fired after %s.', self::durationLabel($delaySeconds)))
                : ($delaySeconds === null
                    ? 'Timer fired.'
                    : sprintf('Timer fired after %s.', self::durationLabel($delaySeconds))),
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
    private static function childMetadata(WorkflowHistoryEvent $event, array $payload): ?array
    {
        if (
            ! array_key_exists('child_call_id', $payload)
            && ! array_key_exists('child_workflow_instance_id', $payload)
            && ! array_key_exists('child_workflow_run_id', $payload)
            && ! array_key_exists('child_workflow_type', $payload)
            && ! array_key_exists('child_workflow_class', $payload)
            && ! in_array($event->event_type, [
                HistoryEventType::ChildWorkflowScheduled,
                HistoryEventType::ChildRunStarted,
                HistoryEventType::ChildRunCompleted,
                HistoryEventType::ChildRunFailed,
                HistoryEventType::ChildRunCancelled,
                HistoryEventType::ChildRunTerminated,
            ], true)
        ) {
            return null;
        }

        return [
            'child_call_id' => self::stringValue($payload['child_call_id'] ?? null)
                ?? self::legacyChildCallId($event, $payload),
            'instance_id' => self::stringValue($payload['child_workflow_instance_id'] ?? null),
            'run_id' => self::stringValue($payload['child_workflow_run_id'] ?? null),
            'type' => self::stringValue($payload['child_workflow_type'] ?? null),
            'class' => self::stringValue($payload['child_workflow_class'] ?? null),
            'status' => self::stringValue($payload['child_status'] ?? null)
                ?? match ($event->event_type) {
                    HistoryEventType::ChildRunCompleted => 'completed',
                    HistoryEventType::ChildRunFailed => 'failed',
                    HistoryEventType::ChildRunCancelled => 'cancelled',
                    HistoryEventType::ChildRunTerminated => 'terminated',
                    default => null,
                },
            'run_number' => self::intValue($payload['child_run_number'] ?? null),
            'parallel_group_path' => ParallelChildGroup::metadataPathFromPayload($payload),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function legacyChildCallId(WorkflowHistoryEvent $event, array $payload): ?string
    {
        if (! in_array($event->event_type, [
            HistoryEventType::ChildWorkflowScheduled,
            HistoryEventType::ChildRunStarted,
        ], true)) {
            return null;
        }

        return self::stringValue($payload['workflow_link_id'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function commandMetadata(
        WorkflowHistoryEvent $event,
        ?WorkflowCommand $command,
        array $payload,
        ?string $commandId,
    ): ?array
    {
        $snapshot = self::arrayValue($payload['command'] ?? null);
        $resolvedCommandId = $command?->id
            ?? $commandId
            ?? self::stringValue($snapshot['id'] ?? null);

        if (
            $command === null
            && $resolvedCommandId === null
            && $snapshot === null
            && ! array_key_exists('outcome', $payload)
            && ! array_key_exists('rejection_reason', $payload)
            && ! array_key_exists('command_type', $payload)
        ) {
            return null;
        }

        return [
            'id' => $resolvedCommandId,
            'sequence' => self::intValue($snapshot['sequence'] ?? null) ?? $command?->command_sequence,
            'type' => self::stringValue($snapshot['type'] ?? null)
                ?? $command?->command_type?->value
                ?? self::stringValue($payload['command_type'] ?? null),
            'target_scope' => self::stringValue($snapshot['target_scope'] ?? null)
                ?? $command?->target_scope,
            'requested_run_id' => self::stringValue($snapshot['requested_run_id'] ?? null)
                ?? $command?->requestedRunId(),
            'resolved_run_id' => self::stringValue($snapshot['resolved_run_id'] ?? null)
                ?? $command?->resolvedRunId(),
            'target_name' => self::stringValue($snapshot['target_name'] ?? null)
                ?? $command?->targetName()
                ?? self::stringValue($payload['signal_name'] ?? null)
                ?? self::stringValue($payload['update_name'] ?? null),
            'payload_codec' => self::stringValue($snapshot['payload_codec'] ?? null)
                ?? (is_string($command?->payload_codec ?? null) ? $command->payload_codec : null),
            'payload_available' => CommandPayloadPreview::available(
                self::stringValue($snapshot['payload'] ?? null)
                ?? (is_string($command?->payload ?? null) ? $command->payload : null)
            ),
            'payload' => CommandPayloadPreview::preview(
                self::stringValue($snapshot['payload'] ?? null)
                ?? (is_string($command?->payload ?? null) ? $command->payload : null)
            ),
            'source' => self::stringValue($snapshot['source'] ?? null) ?? $command?->source,
            'caller_label' => self::stringValue($snapshot['caller_label'] ?? null) ?? $command?->callerLabel(),
            'auth_status' => self::stringValue($snapshot['auth_status'] ?? null) ?? $command?->authStatus(),
            'auth_method' => self::stringValue($snapshot['auth_method'] ?? null) ?? $command?->authMethod(),
            'request_method' => self::stringValue($snapshot['request_method'] ?? null) ?? $command?->requestMethod(),
            'request_path' => self::stringValue($snapshot['request_path'] ?? null) ?? $command?->requestPath(),
            'request_route_name' => self::stringValue($snapshot['request_route_name'] ?? null)
                ?? $command?->requestRouteName(),
            'request_fingerprint' => self::stringValue($snapshot['request_fingerprint'] ?? null)
                ?? $command?->requestFingerprint(),
            'status' => self::historicalCommandStatus($event, $command),
            'outcome' => self::historicalCommandOutcome($event, $command, $payload),
            'rejection_reason' => self::stringValue($snapshot['rejection_reason'] ?? null)
                ?? $command?->rejection_reason
                ?? self::stringValue($payload['rejection_reason'] ?? null),
            'accepted_at' => self::timestamp($snapshot['accepted_at'] ?? null) ?? self::timestamp($command?->accepted_at),
            'applied_at' => self::timestamp($snapshot['applied_at'] ?? null) ?? self::timestamp($command?->applied_at),
            'rejected_at' => self::timestamp($snapshot['rejected_at'] ?? null) ?? self::timestamp($command?->rejected_at),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function taskMetadata(
        WorkflowHistoryEvent $event,
        ?WorkflowTask $task,
        array $payload,
        ?string $taskId,
    ): ?array
    {
        $snapshot = self::arrayValue($payload['task'] ?? null);
        $resolvedTaskId = $task?->id
            ?? $taskId
            ?? self::stringValue($snapshot['id'] ?? null);

        if ($task === null && $resolvedTaskId === null && $snapshot === null) {
            return null;
        }

        if ($event->event_type === HistoryEventType::RepairRequested) {
            return [
                'id' => $resolvedTaskId,
                'type' => self::stringValue($payload['task_type'] ?? null)
                    ?? self::stringValue($snapshot['type'] ?? null)
                    ?? $task?->task_type?->value,
                'status' => $resolvedTaskId === null ? null : TaskStatus::Ready->value,
                'available_at' => self::timestamp($snapshot['available_at'] ?? null) ?? self::timestamp($task?->available_at),
                'leased_at' => self::timestamp($snapshot['leased_at'] ?? null) ?? self::timestamp($task?->leased_at),
                'lease_expires_at' => self::timestamp($snapshot['lease_expires_at'] ?? null)
                    ?? self::timestamp($task?->lease_expires_at),
                'attempt_count' => null,
            ];
        }

        $historicalType = self::historicalTaskType($event, $resolvedTaskId);
        $historicalStatus = self::historicalTaskStatus($event, $resolvedTaskId);

        return [
            'id' => $resolvedTaskId,
            'type' => $historicalType
                ?? self::stringValue($snapshot['type'] ?? null)
                ?? $task?->task_type?->value,
            'status' => $historicalStatus
                ?? self::stringValue($snapshot['status'] ?? null)
                ?? $task?->status?->value,
            'available_at' => self::timestamp($snapshot['available_at'] ?? null) ?? self::timestamp($task?->available_at),
            'leased_at' => self::timestamp($snapshot['leased_at'] ?? null) ?? self::timestamp($task?->leased_at),
            'lease_expires_at' => self::timestamp($snapshot['lease_expires_at'] ?? null)
                ?? self::timestamp($task?->lease_expires_at),
            'attempt_count' => array_key_exists('attempt_count', $snapshot ?? [])
                ? self::intValue($snapshot['attempt_count'])
                : $task?->attempt_count,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function activityMetadata(
        WorkflowHistoryEvent $event,
        ?ActivityExecution $activity,
        array $payload,
        ?string $activityId,
        ?WorkflowFailure $failure,
    ): ?array
    {
        $snapshot = ActivitySnapshot::fromEvent($event);

        if (
            $snapshot === null
            && $activity === null
            && $activityId === null
            && ! array_key_exists('activity_type', $payload)
            && ! array_key_exists('activity_class', $payload)
        ) {
            return null;
        }

        $resolvedActivityId = $activity?->id
            ?? $activityId
            ?? (
                $failure?->source_kind === 'activity_execution'
                    ? self::stringValue($failure->source_id)
                    : null
            );

        return [
            'id' => self::stringValue($snapshot['id'] ?? null)
                ?? $resolvedActivityId,
            'sequence' => self::intValue($snapshot['sequence'] ?? null)
                ?? $activity?->sequence
                ?? self::intValue($payload['sequence'] ?? null),
            'type' => self::stringValue($snapshot['type'] ?? null)
                ?? $activity?->activity_type
                ?? self::stringValue($payload['activity_type'] ?? null),
            'class' => self::stringValue($snapshot['class'] ?? null)
                ?? $activity?->activity_class
                ?? self::stringValue($payload['activity_class'] ?? null),
            'parallel_group_path' => ParallelChildGroup::metadataPathFromPayload($payload),
            'attempt_id' => self::stringValue($snapshot['attempt_id'] ?? null)
                ?? ($event->event_type === HistoryEventType::ActivityScheduled
                    ? null
                    : self::stringValue($activity?->current_attempt_id)),
            'status' => self::stringValue($snapshot['status'] ?? null)
                ?? match ($event->event_type) {
                    HistoryEventType::ActivityScheduled => 'pending',
                    HistoryEventType::ActivityStarted => 'running',
                    HistoryEventType::ActivityCompleted => 'completed',
                    HistoryEventType::ActivityFailed => 'failed',
                    default => $activity?->status?->value,
                },
            'attempt_count' => self::intValue($snapshot['attempt_count'] ?? null)
                ?? ($event->event_type === HistoryEventType::ActivityScheduled
                    ? (($activity?->status?->value === 'pending' && $activity?->started_at === null)
                        ? 0
                        : ($activity?->attempt_count ?? 1))
                    : $activity?->attempt_count),
            'connection' => self::stringValue($snapshot['connection'] ?? null)
                ?? $activity?->connection,
            'queue' => self::stringValue($snapshot['queue'] ?? null)
                ?? $activity?->queue,
            'started_at' => $event->event_type === HistoryEventType::ActivityScheduled
                ? null
                : self::timestamp(
                    $activity?->started_at
                    ?? self::stringValue($snapshot['started_at'] ?? null)
                    ?? ($event->event_type === HistoryEventType::ActivityStarted ? $event->recorded_at : null)
                ),
            'closed_at' => in_array($event->event_type, [
                HistoryEventType::ActivityCompleted,
                HistoryEventType::ActivityFailed,
            ], true)
                ? self::timestamp($activity?->closed_at ?? self::stringValue($snapshot['closed_at'] ?? null))
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function timerMetadata(
        WorkflowHistoryEvent $event,
        ?WorkflowTimer $timer,
        array $payload,
        ?string $timerId,
    ): ?array
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
            'status' => match ($event->event_type) {
                HistoryEventType::TimerScheduled => 'pending',
                HistoryEventType::TimerFired => 'fired',
                default => $timer?->status?->value,
            },
            'delay_seconds' => $timer?->delay_seconds ?? self::intValue($payload['delay_seconds'] ?? null),
            'fire_at' => self::timestamp($timer?->fire_at ?? self::stringValue($payload['fire_at'] ?? null)),
            'fired_at' => $event->event_type === HistoryEventType::TimerScheduled
                ? null
                : self::timestamp($timer?->fired_at ?? self::stringValue($payload['fired_at'] ?? null)),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function failureMetadata(
        WorkflowHistoryEvent $event,
        ?WorkflowFailure $failure,
        array $payload,
        ?string $failureId,
    ): ?array
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
            'source_kind' => self::stringValue($payload['source_kind'] ?? null) ?? $failure?->source_kind,
            'source_id' => self::stringValue($payload['source_id'] ?? null) ?? $failure?->source_id,
            'propagation_kind' => match ($event->event_type) {
                HistoryEventType::ActivityFailed => 'activity',
                HistoryEventType::WorkflowFailed => 'terminal',
                HistoryEventType::UpdateCompleted => ($failure?->id ?? $failureId) === null ? null : 'update',
                default => $failure?->propagation_kind,
            },
            'handled' => match ($event->event_type) {
                HistoryEventType::ActivityFailed,
                HistoryEventType::WorkflowFailed => false,
                HistoryEventType::UpdateCompleted => ($failure?->id ?? $failureId) === null ? null : false,
                default => $failure?->handled,
            },
            'exception_class' => self::stringValue($payload['exception_class'] ?? null) ?? $failure?->exception_class,
            'message' => self::stringValue($payload['message'] ?? null) ?? $failure?->message,
            'file' => $failure?->file,
            'line' => $failure?->line,
        ];
    }

    private static function sourceKindFor(WorkflowHistoryEvent $event): string
    {
        return match ($event->event_type) {
            HistoryEventType::StartAccepted,
            HistoryEventType::StartRejected,
            HistoryEventType::SignalReceived,
            HistoryEventType::UpdateAccepted,
            HistoryEventType::UpdateRejected,
            HistoryEventType::UpdateApplied,
            HistoryEventType::UpdateCompleted,
            HistoryEventType::RepairRequested,
            HistoryEventType::CancelRequested,
            HistoryEventType::TerminateRequested => 'workflow_command',
            HistoryEventType::SignalWaitOpened,
            HistoryEventType::SignalApplied => 'signal_wait',
            HistoryEventType::ChildWorkflowScheduled,
            HistoryEventType::ChildRunStarted,
            HistoryEventType::ChildRunCompleted,
            HistoryEventType::ChildRunFailed,
            HistoryEventType::ChildRunCancelled,
            HistoryEventType::ChildRunTerminated => 'child_workflow_run',
            HistoryEventType::ConditionWaitOpened,
            HistoryEventType::ConditionWaitSatisfied,
            HistoryEventType::ConditionWaitTimedOut => 'condition_wait',
            HistoryEventType::ActivityScheduled,
            HistoryEventType::ActivityStarted,
            HistoryEventType::ActivityCompleted,
            HistoryEventType::ActivityFailed => 'activity_execution',
            HistoryEventType::VersionMarkerRecorded => 'version_marker',
            HistoryEventType::TimerScheduled,
            HistoryEventType::TimerFired => 'timer',
            default => 'workflow_run',
        };
    }

    /**
     * @param array<string, mixed>|null $command
     * @param array<string, mixed>|null $task
     * @param array<string, mixed>|null $activity
     * @param array<string, mixed>|null $timer
     * @param array<string, mixed>|null $child
     * @param array<string, mixed>|null $failure
     */
    private static function sourceIdFor(
        WorkflowHistoryEvent $event,
        ?array $command,
        ?array $task,
        ?array $activity,
        ?array $timer,
        ?array $child,
        ?array $failure,
    ): ?string {
        return match (self::sourceKindFor($event)) {
            'workflow_command' => self::stringValue($command['id'] ?? null),
            'signal_wait' => self::stringValue($event->payload['signal_wait_id'] ?? null),
            'condition_wait' => self::stringValue($event->payload['condition_wait_id'] ?? null),
            'version_marker' => self::stringValue($event->payload['change_id'] ?? null),
            'child_workflow_run' => self::stringValue($child['run_id'] ?? null)
                ?? self::stringValue($child['instance_id'] ?? null),
            'activity_execution' => self::stringValue($activity['id'] ?? null)
                ?? (
                    self::stringValue($failure['source_kind'] ?? null) === 'activity_execution'
                        ? self::stringValue($failure['source_id'] ?? null)
                        : null
                ),
            'timer' => self::stringValue($timer['id'] ?? null),
            'workflow_run' => self::stringValue($event->workflow_run_id),
            default => self::stringValue($task['id'] ?? null),
        };
    }

    private static function historicalCommandStatus(
        WorkflowHistoryEvent $event,
        ?WorkflowCommand $command,
    ): ?string {
        return match ($event->event_type) {
            HistoryEventType::StartRejected => 'rejected',
            HistoryEventType::UpdateRejected => 'rejected',
            HistoryEventType::StartAccepted,
            HistoryEventType::SignalReceived,
            HistoryEventType::UpdateAccepted,
            HistoryEventType::UpdateApplied,
            HistoryEventType::UpdateCompleted,
            HistoryEventType::RepairRequested,
            HistoryEventType::CancelRequested,
            HistoryEventType::TerminateRequested => 'accepted',
            default => $command?->status?->value,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function historicalCommandOutcome(
        WorkflowHistoryEvent $event,
        ?WorkflowCommand $command,
        array $payload,
    ): ?string {
        $payloadOutcome = self::stringValue($payload['outcome'] ?? null);

        if ($payloadOutcome !== null) {
            return $payloadOutcome;
        }

        return match ($event->event_type) {
            HistoryEventType::SignalReceived => 'signal_received',
            HistoryEventType::UpdateAccepted,
            HistoryEventType::UpdateApplied => null,
            HistoryEventType::UpdateCompleted => array_key_exists('failure_id', $payload)
                ? 'update_failed'
                : 'update_completed',
            HistoryEventType::CancelRequested => 'cancelled',
            HistoryEventType::TerminateRequested => 'terminated',
            default => $command?->outcome?->value,
        };
    }

    private static function historicalTaskType(WorkflowHistoryEvent $event, ?string $taskId): ?string
    {
        if ($taskId === null) {
            return null;
        }

        return match ($event->event_type) {
            HistoryEventType::ActivityStarted,
            HistoryEventType::ActivityCompleted,
            HistoryEventType::ActivityFailed => 'activity',
            HistoryEventType::TimerFired => 'timer',
            default => 'workflow',
        };
    }

    private static function historicalTaskStatus(WorkflowHistoryEvent $event, ?string $taskId): ?string
    {
        if ($taskId === null) {
            return null;
        }

        return match ($event->event_type) {
            HistoryEventType::ActivityStarted => 'leased',
            HistoryEventType::WorkflowFailed => 'failed',
            default => 'completed',
        };
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

    /**
     * @return array<string, mixed>|null
     */
    private static function arrayValue(mixed $value): ?array
    {
        return is_array($value)
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
