<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Carbon;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;

final class RunWaitView
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing([
            'historyEvents',
            'commands',
            'tasks',
            'activityExecutions',
            'timers',
            'childLinks.childRun.summary',
            'childLinks.childRun.failures',
            'childLinks.childRun.historyEvents',
        ]);

        $taskByActivityExecutionId = self::preferredTasksByPayloadKey($run, 'activity_execution_id');
        $taskByTimerId = self::preferredTasksByPayloadKey($run, 'timer_id');
        $conditionWaits = ConditionWaits::forRun($run);
        $conditionTimerIds = array_values(array_filter(
            array_map(
                static fn (array $wait): ?string => self::stringValue($wait['timer_id'] ?? null),
                $conditionWaits
            ),
            static fn (?string $timerId): bool => $timerId !== null,
        ));

        $waits = [];

        foreach (RunActivityView::activitiesForRun($run) as $activity) {
            if (! is_string($activity['id'] ?? null)) {
                continue;
            }

            $waits[] = self::activityWait($activity, $taskByActivityExecutionId[$activity['id']] ?? null);
        }

        $waits = array_merge($waits, self::conditionWaits($conditionWaits, $taskByTimerId));

        foreach ($run->timers as $timer) {
            if (! $timer instanceof WorkflowTimer) {
                continue;
            }

            if (in_array($timer->id, $conditionTimerIds, true)) {
                continue;
            }

            $waits[] = self::timerWait($timer, $taskByTimerId[$timer->id] ?? null);
        }

        $waits = array_merge($waits, self::childWaits($run));
        $waits = array_merge($waits, self::signalWaits($run));

        usort($waits, static function (array $left, array $right): int {
            $statusPriority = [
                'open' => 0,
                'resolved' => 1,
                'cancelled' => 2,
            ];

            $leftPriority = $statusPriority[$left['status']] ?? 99;
            $rightPriority = $statusPriority[$right['status']] ?? 99;

            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            $leftOpenedAt = $left['opened_at']?->getTimestampMs() ?? PHP_INT_MAX;
            $rightOpenedAt = $right['opened_at']?->getTimestampMs() ?? PHP_INT_MAX;

            if ($leftOpenedAt !== $rightOpenedAt) {
                return $leftOpenedAt <=> $rightOpenedAt;
            }

            return $left['id'] <=> $right['id'];
        });

        return array_values($waits);
    }

    /**
     * @return array<string, mixed>
     */
    private static function activityWait(array $activity, ?WorkflowTask $task): array
    {
        $activityId = self::stringValue($activity['id'] ?? null);
        $activityType = self::stringValue($activity['type'] ?? null)
            ?? self::stringValue($activity['class'] ?? null)
            ?? 'activity';
        $sourceStatus = self::stringValue($activity['status'] ?? null) ?? ActivityStatus::Pending->value;
        $status = match ($sourceStatus) {
            ActivityStatus::Pending->value, ActivityStatus::Running->value => 'open',
            ActivityStatus::Cancelled->value => 'cancelled',
            default => 'resolved',
        };

        return [
            'id' => sprintf('activity:%s', $activityId),
            'kind' => 'activity',
            'sequence' => $activity['sequence'] ?? null,
            'status' => $status,
            'source_status' => $sourceStatus,
            'summary' => match ($status) {
                'open' => sprintf('Waiting for activity %s.', $activityType),
                'cancelled' => sprintf('Activity wait for %s was cancelled.', $activityType),
                default => $sourceStatus === ActivityStatus::Failed->value
                    ? sprintf('Activity %s failed.', $activityType)
                    : sprintf('Activity %s completed.', $activityType),
            },
            'opened_at' => self::timestamp($activity['started_at'] ?? null)
                ?? self::timestamp($activity['created_at'] ?? null),
            'deadline_at' => null,
            'resolved_at' => self::timestamp($activity['closed_at'] ?? null),
            'target_name' => null,
            'target_type' => $activityType,
            'task_backed' => self::isOpenTask($task),
            'external_only' => false,
            'resume_source_kind' => 'activity_execution',
            'resume_source_id' => $activityId,
            'task_id' => $task?->id,
            'task_type' => $task?->task_type?->value,
            'task_status' => $task?->status?->value,
            'command_id' => null,
            'command_sequence' => null,
            'command_status' => null,
            'command_outcome' => null,
            'parallel_group_kind' => $activity['parallel_group_kind'] ?? null,
            'parallel_group_id' => $activity['parallel_group_id'] ?? null,
            'parallel_group_base_sequence' => $activity['parallel_group_base_sequence'] ?? null,
            'parallel_group_size' => $activity['parallel_group_size'] ?? null,
            'parallel_group_index' => $activity['parallel_group_index'] ?? null,
            'parallel_group_path' => $activity['parallel_group_path'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function timerWait(WorkflowTimer $timer, ?WorkflowTask $task): array
    {
        $status = match ($timer->status) {
            TimerStatus::Pending => 'open',
            TimerStatus::Cancelled => 'cancelled',
            default => 'resolved',
        };

        return [
            'id' => sprintf('timer:%s', $timer->id),
            'kind' => 'timer',
            'sequence' => $timer->sequence,
            'status' => $status,
            'source_status' => $timer->status->value,
            'summary' => match ($status) {
                'open' => 'Waiting for timer.',
                'cancelled' => 'Timer wait was cancelled.',
                default => 'Timer fired.',
            },
            'opened_at' => $timer->created_at,
            'deadline_at' => $timer->fire_at,
            'resolved_at' => $timer->fired_at,
            'target_name' => null,
            'target_type' => 'timer',
            'task_backed' => self::isOpenTask($task),
            'external_only' => false,
            'resume_source_kind' => 'timer',
            'resume_source_id' => $timer->id,
            'task_id' => $task?->id,
            'task_type' => $task?->task_type?->value,
            'task_status' => $task?->status?->value,
            'command_id' => null,
            'command_sequence' => null,
            'command_status' => null,
            'command_outcome' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function signalWaits(WorkflowRun $run): array
    {
        $commands = $run->commands->keyBy('id');

        return array_values(array_map(static function (array $wait) use ($commands): array {
            /** @var WorkflowCommand|null $command */
            $command = $wait['command_id'] === null
                ? null
                : $commands->get($wait['command_id']);

            $summary = match ($wait['status']) {
                'open' => sprintf('Waiting for signal %s.', $wait['signal_name']),
                'cancelled' => match ($wait['source_status']) {
                    'cancelled' => 'Signal wait ended when the run was cancelled.',
                    'terminated' => 'Signal wait ended when the run was terminated.',
                    'continued' => 'Signal wait ended when the run continued as new.',
                    'closed' => 'Signal wait ended when the run closed.',
                    default => 'Signal wait ended when the run failed.',
                },
                default => sprintf('Signal %s received.', $wait['signal_name']),
            };

            return [
                'id' => $wait['signal_wait_id'],
                'signal_wait_id' => $wait['signal_wait_id'],
                'signal_id' => $wait['signal_id'] ?? null,
                'kind' => 'signal',
                'sequence' => $wait['sequence'],
                'status' => $wait['status'],
                'source_status' => $wait['source_status'],
                'summary' => $summary,
                'opened_at' => $wait['opened_at'],
                'deadline_at' => null,
                'resolved_at' => $wait['resolved_at'],
                'target_name' => $wait['signal_name'],
                'target_type' => null,
                'task_backed' => false,
                'external_only' => true,
                'resume_source_kind' => 'signal',
                'resume_source_id' => $wait['command_id'],
                'task_id' => null,
                'task_type' => null,
                'task_status' => null,
                'command_id' => $wait['command_id'],
                'command_sequence' => $wait['command_sequence'] ?? $command?->command_sequence,
                'command_status' => $wait['command_status'] ?? $command?->status?->value,
                'command_outcome' => $wait['command_outcome'] ?? $command?->outcome?->value,
            ];
        }, SignalWaits::forRun($run)));
    }

    /**
     * @param list<array<string, mixed>> $conditionWaits
     * @param array<string, WorkflowTask> $taskByTimerId
     * @return list<array<string, mixed>>
     */
    private static function conditionWaits(array $conditionWaits, array $taskByTimerId): array
    {
        return array_values(array_map(
            static function (array $wait) use ($taskByTimerId): array {
                $sequence = self::intValue($wait['sequence'] ?? null);
                $timerId = self::stringValue($wait['timer_id'] ?? null);
                $task = $timerId === null ? null : ($taskByTimerId[$timerId] ?? null);
                $timeoutSeconds = self::intValue($wait['timeout_seconds'] ?? null);
                $resumeSourceKind = self::stringValue($wait['resume_source_kind'] ?? null) ?? 'external_input';
                $resumeSourceId = self::stringValue($wait['resume_source_id'] ?? null);
                $summary = match ($wait['status']) {
                    'open' => $timeoutSeconds === null
                        ? 'Waiting for condition.'
                        : sprintf(
                            'Waiting for condition or timeout after %s.',
                            self::durationLabel($timeoutSeconds),
                        ),
                    'cancelled' => match ($wait['source_status']) {
                        'cancelled' => 'Condition wait ended when the run was cancelled.',
                        'terminated' => 'Condition wait ended when the run was terminated.',
                        'continued' => 'Condition wait ended when the run continued as new.',
                        'closed' => 'Condition wait ended when the run closed.',
                        default => 'Condition wait ended when the run failed.',
                    },
                    default => $wait['source_status'] === 'timed_out'
                        ? sprintf('Condition wait timed out after %s.', self::durationLabel($timeoutSeconds ?? 0))
                        : 'Condition satisfied.',
                };

                return [
                    'id' => $wait['condition_wait_id'],
                    'condition_wait_id' => $wait['condition_wait_id'],
                    'kind' => 'condition',
                    'sequence' => $sequence,
                    'status' => $wait['status'],
                    'source_status' => $wait['source_status'],
                    'summary' => $summary,
                    'opened_at' => $wait['opened_at'],
                    'deadline_at' => self::timestamp($wait['deadline_at'] ?? null),
                    'resolved_at' => $wait['resolved_at'],
                    'target_name' => null,
                    'target_type' => 'condition',
                    'task_backed' => self::isOpenTask($task),
                    'external_only' => true,
                    'resume_source_kind' => $resumeSourceKind,
                    'resume_source_id' => $resumeSourceId,
                    'task_id' => $task?->id,
                    'task_type' => $task?->task_type?->value,
                    'task_status' => $task?->status?->value,
                    'command_id' => null,
                    'command_sequence' => null,
                    'command_status' => null,
                    'command_outcome' => null,
                    'timeout_seconds' => $timeoutSeconds,
                ];
            },
            $conditionWaits,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function childWaits(WorkflowRun $run): array
    {
        $scheduledEvents = $run->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::ChildWorkflowScheduled
                    && is_int($event->payload['sequence'] ?? null)
            )
            ->keyBy(static fn (WorkflowHistoryEvent $event): string => (string) $event->payload['sequence']);

        $resolutionEvents = $run->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => in_array(
                    $event->event_type,
                    ChildRunHistory::resolutionEventTypes(),
                    true,
                ) && is_int($event->payload['sequence'] ?? null)
            )
            ->keyBy(static fn (WorkflowHistoryEvent $event): string => (string) $event->payload['sequence']);

        $sequences = array_unique(array_merge(
            $scheduledEvents->keys()
                ->all(),
            $resolutionEvents->keys()
                ->all(),
            $run->childLinks
                ->filter(
                    static fn (WorkflowLink $link): bool => $link->link_type === 'child_workflow' && $link->sequence !== null
                )
                ->map(static fn (WorkflowLink $link): string => (string) $link->sequence)
                ->all(),
        ));

        sort($sequences, SORT_NATURAL);

        return array_values(array_map(static function (string $sequence) use (
            $run,
            $scheduledEvents,
            $resolutionEvents,
        ): array {
            $workflowSequence = is_numeric($sequence) ? (int) $sequence : null;
            /** @var WorkflowLink|null $link */
            $link = $workflowSequence === null
                ? null
                : ChildRunHistory::latestLinkForSequence($run, $workflowSequence);
            /** @var WorkflowHistoryEvent|null $scheduledEvent */
            $scheduledEvent = $scheduledEvents->get($sequence);
            /** @var WorkflowHistoryEvent|null $resolutionEvent */
            $resolutionEvent = $resolutionEvents->get($sequence);
            $childRun = $workflowSequence === null
                ? null
                : ChildRunHistory::childRunForSequence($run, $workflowSequence);
            $resolvedStatus = ChildRunHistory::resolvedStatus($resolutionEvent, $childRun);
            $childCallId = $workflowSequence === null
                ? null
                : ChildRunHistory::childCallIdForSequence($run, $workflowSequence);
            $label = self::stringValue($resolutionEvent?->payload['child_workflow_type'] ?? null)
                ?? self::stringValue($scheduledEvent?->payload['child_workflow_type'] ?? null)
                ?? $childRun?->workflow_type
                ?? self::stringValue($resolutionEvent?->payload['child_workflow_class'] ?? null)
                ?? self::stringValue($scheduledEvent?->payload['child_workflow_class'] ?? null)
                ?? $childRun?->workflow_class
                ?? 'child workflow';
            $sourceStatus = $resolvedStatus?->value ?? $childRun?->status?->value;
            $status = match (true) {
                $resolutionEvent !== null => in_array(
                    $resolvedStatus,
                    [RunStatus::Cancelled, RunStatus::Terminated],
                    true
                )
                    ? 'cancelled'
                    : 'resolved',
                in_array(
                    $childRun?->status,
                    [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting],
                    true
                ) => 'open',
                in_array($childRun?->status, [RunStatus::Cancelled, RunStatus::Terminated], true) => 'cancelled',
                default => 'resolved',
            };

            $summary = match ($sourceStatus) {
                RunStatus::Completed->value => sprintf('Child workflow %s completed.', $label),
                RunStatus::Failed->value => sprintf('Child workflow %s failed.', $label),
                RunStatus::Cancelled->value => sprintf('Child workflow %s cancelled.', $label),
                RunStatus::Terminated->value => sprintf('Child workflow %s terminated.', $label),
                default => sprintf('Waiting for child workflow %s.', $label),
            };
            $parallelMetadata = ParallelChildGroup::metadataFromPayload(
                is_array($scheduledEvent?->payload) ? $scheduledEvent->payload : (
                    is_array($resolutionEvent?->payload) ? $resolutionEvent->payload : []
                )
            );
            $parallelMetadataPath = ParallelChildGroup::metadataPathFromPayload(
                is_array($scheduledEvent?->payload) ? $scheduledEvent->payload : (
                    is_array($resolutionEvent?->payload) ? $resolutionEvent->payload : []
                )
            );

            return [
                'id' => sprintf('child:%s', $childCallId ?? $sequence),
                'kind' => 'child',
                'child_call_id' => $childCallId,
                'sequence' => $workflowSequence,
                'status' => $status,
                'source_status' => $sourceStatus,
                'summary' => $summary,
                'opened_at' => $scheduledEvent?->recorded_at ?? $scheduledEvent?->created_at ?? $link?->created_at,
                'deadline_at' => null,
                'resolved_at' => $resolutionEvent?->recorded_at ?? $childRun?->closed_at,
                'target_name' => $childRun?->workflow_instance_id
                    ?? self::stringValue($resolutionEvent?->payload['child_workflow_instance_id'] ?? null)
                    ?? self::stringValue($scheduledEvent?->payload['child_workflow_instance_id'] ?? null)
                    ?? $link?->child_workflow_instance_id
                    ?? null,
                'target_type' => $label,
                'task_backed' => false,
                'external_only' => false,
                'resume_source_kind' => 'child_workflow_run',
                'resume_source_id' => $childRun?->id
                    ?? self::stringValue($resolutionEvent?->payload['child_workflow_run_id'] ?? null)
                    ?? self::stringValue($scheduledEvent?->payload['child_workflow_run_id'] ?? null)
                    ?? $link?->child_workflow_run_id
                    ?? null,
                'task_id' => null,
                'task_type' => null,
                'task_status' => null,
                'command_id' => null,
                'command_sequence' => null,
                'command_status' => null,
                'command_outcome' => null,
                'parallel_group_kind' => $parallelMetadata['parallel_group_kind'] ?? null,
                'parallel_group_id' => $parallelMetadata['parallel_group_id'] ?? null,
                'parallel_group_base_sequence' => $parallelMetadata['parallel_group_base_sequence'] ?? null,
                'parallel_group_size' => $parallelMetadata['parallel_group_size'] ?? null,
                'parallel_group_index' => $parallelMetadata['parallel_group_index'] ?? null,
                'parallel_group_path' => $parallelMetadataPath,
            ];
        }, $sequences));
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

        return is_string($value) && is_numeric($value)
            ? (int) $value
            : null;
    }

    private static function durationLabel(int $seconds): string
    {
        return sprintf('%d second%s', $seconds, $seconds === 1 ? '' : 's');
    }

    /**
     * @return array<string, WorkflowTask>
     */
    private static function preferredTasksByPayloadKey(WorkflowRun $run, string $payloadKey): array
    {
        $tasks = [];

        foreach ($run->tasks as $task) {
            if (! $task instanceof WorkflowTask) {
                continue;
            }

            $payloadId = self::stringValue($task->payload[$payloadKey] ?? null);

            if ($payloadId === null) {
                continue;
            }

            $current = $tasks[$payloadId] ?? null;

            if (! $current instanceof WorkflowTask || self::taskPreference($task, $current) < 0) {
                $tasks[$payloadId] = $task;
            }
        }

        return $tasks;
    }

    private static function taskPreference(WorkflowTask $left, WorkflowTask $right): int
    {
        $leftPriority = self::taskPriority($left);
        $rightPriority = self::taskPriority($right);

        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        $leftUpdatedAt = $left->updated_at?->getTimestampMs() ?? PHP_INT_MIN;
        $rightUpdatedAt = $right->updated_at?->getTimestampMs() ?? PHP_INT_MIN;

        if ($leftUpdatedAt !== $rightUpdatedAt) {
            return $rightUpdatedAt <=> $leftUpdatedAt;
        }

        $leftCreatedAt = $left->created_at?->getTimestampMs() ?? PHP_INT_MIN;
        $rightCreatedAt = $right->created_at?->getTimestampMs() ?? PHP_INT_MIN;

        if ($leftCreatedAt !== $rightCreatedAt) {
            return $rightCreatedAt <=> $leftCreatedAt;
        }

        return $right->id <=> $left->id;
    }

    private static function taskPriority(WorkflowTask $task): int
    {
        return match ($task->status) {
            TaskStatus::Leased => 0,
            TaskStatus::Ready => 1,
            TaskStatus::Completed => 2,
            TaskStatus::Failed => 3,
            TaskStatus::Cancelled => 4,
        };
    }

    private static function isOpenTask(?WorkflowTask $task): bool
    {
        if (! $task instanceof WorkflowTask) {
            return false;
        }

        return in_array($task->status, [TaskStatus::Ready, TaskStatus::Leased], true);
    }

    private static function timestamp(mixed $value): ?\Carbon\CarbonInterface
    {
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value;
        }

        return is_string($value) && $value !== ''
            ? Carbon::parse($value)
            : null;
    }
}
