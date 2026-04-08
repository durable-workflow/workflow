<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityExecution;
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

        $waits = [];

        foreach ($run->activityExecutions as $execution) {
            if (! $execution instanceof ActivityExecution) {
                continue;
            }

            $waits[] = self::activityWait(
                $execution,
                $taskByActivityExecutionId[$execution->id] ?? null,
                $run->historyEvents->firstWhere('payload.activity_execution_id', $execution->id),
            );
        }

        foreach ($run->timers as $timer) {
            if (! $timer instanceof WorkflowTimer) {
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
    private static function activityWait(
        ActivityExecution $execution,
        ?WorkflowTask $task,
        ?WorkflowHistoryEvent $scheduledEvent,
    ): array {
        $status = match ($execution->status) {
            ActivityStatus::Pending, ActivityStatus::Running => 'open',
            ActivityStatus::Cancelled => 'cancelled',
            default => 'resolved',
        };

        return [
            'id' => sprintf('activity:%s', $execution->id),
            'kind' => 'activity',
            'sequence' => $execution->sequence,
            'status' => $status,
            'source_status' => $execution->status->value,
            'summary' => match ($status) {
                'open' => sprintf('Waiting for activity %s.', $execution->activity_type),
                'cancelled' => sprintf('Activity wait for %s was cancelled.', $execution->activity_type),
                default => match ($execution->status) {
                    ActivityStatus::Failed => sprintf('Activity %s failed.', $execution->activity_type),
                    default => sprintf('Activity %s completed.', $execution->activity_type),
                },
            },
            'opened_at' => $scheduledEvent?->recorded_at ?? $execution->started_at ?? $execution->created_at,
            'deadline_at' => null,
            'resolved_at' => $execution->closed_at,
            'target_name' => null,
            'target_type' => $execution->activity_type,
            'task_backed' => self::isOpenTask($task),
            'external_only' => false,
            'resume_source_kind' => 'activity_execution',
            'resume_source_id' => $execution->id,
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
                'command_sequence' => $command?->command_sequence,
                'command_status' => $command?->status?->value,
                'command_outcome' => $command?->outcome?->value,
            ];
        }, SignalWaits::forRun($run)));
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
                ->filter(static fn (WorkflowLink $link): bool => $link->link_type === 'child_workflow' && $link->sequence !== null)
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

            return [
                'id' => sprintf('child:%s', $link?->id ?? $sequence),
                'kind' => 'child',
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
            ];
        }, $sequences));
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
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
}
