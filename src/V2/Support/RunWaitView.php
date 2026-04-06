<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
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
        ]);

        $taskByActivityExecutionId = [];
        $taskByTimerId = [];

        foreach ($run->tasks as $task) {
            if (! $task instanceof WorkflowTask) {
                continue;
            }

            $activityExecutionId = self::stringValue($task->payload['activity_execution_id'] ?? null);

            if ($activityExecutionId !== null && ! array_key_exists($activityExecutionId, $taskByActivityExecutionId)) {
                $taskByActivityExecutionId[$activityExecutionId] = $task;
            }

            $timerId = self::stringValue($task->payload['timer_id'] ?? null);

            if ($timerId !== null && ! array_key_exists($timerId, $taskByTimerId)) {
                $taskByTimerId[$timerId] = $task;
            }
        }

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
            'task_backed' => $task !== null,
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
            'task_backed' => $task !== null,
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
        $waits = [];
        $openWaitKeysByName = [];
        $commands = $run->commands->keyBy('id');

        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            $signalName = self::stringValue($event->payload['signal_name'] ?? null);

            if ($event->event_type === HistoryEventType::SignalWaitOpened) {
                if ($signalName === null) {
                    continue;
                }

                $sequence = self::intValue($event->payload['sequence'] ?? null);
                $key = sprintf('signal:%s:%s', $sequence ?? $event->sequence, $signalName);

                $waits[$key] = [
                    'id' => $key,
                    'kind' => 'signal',
                    'sequence' => $sequence,
                    'status' => 'open',
                    'source_status' => 'waiting',
                    'summary' => sprintf('Waiting for signal %s.', $signalName),
                    'opened_at' => $event->recorded_at ?? $event->created_at,
                    'deadline_at' => null,
                    'resolved_at' => null,
                    'target_name' => $signalName,
                    'target_type' => null,
                    'task_backed' => false,
                    'external_only' => true,
                    'resume_source_kind' => 'signal',
                    'resume_source_id' => null,
                    'task_id' => null,
                    'task_type' => null,
                    'task_status' => null,
                    'command_id' => null,
                    'command_sequence' => null,
                    'command_status' => null,
                    'command_outcome' => null,
                ];

                $openWaitKeysByName[$signalName] ??= [];
                $openWaitKeysByName[$signalName][] = $key;

                continue;
            }

            if ($signalName === null || ! isset($openWaitKeysByName[$signalName][0])) {
                if (in_array($event->event_type, [
                    HistoryEventType::WorkflowCompleted,
                    HistoryEventType::WorkflowFailed,
                    HistoryEventType::WorkflowCancelled,
                    HistoryEventType::WorkflowTerminated,
                    HistoryEventType::WorkflowContinuedAsNew,
                ], true)) {
                    self::closeOpenSignalWaits($waits, $openWaitKeysByName, $event);
                }

                continue;
            }

            if (in_array($event->event_type, [
                HistoryEventType::SignalReceived,
                HistoryEventType::SignalApplied,
            ], true)) {
                $key = array_shift($openWaitKeysByName[$signalName]);

                if ($key === null || ! isset($waits[$key])) {
                    continue;
                }

                /** @var WorkflowCommand|null $command */
                $command = $event->workflow_command_id === null
                    ? null
                    : $commands->get($event->workflow_command_id);

                $waits[$key]['status'] = 'resolved';
                $waits[$key]['source_status'] = $event->event_type === HistoryEventType::SignalApplied
                    ? 'applied'
                    : 'received';
                $waits[$key]['summary'] = sprintf('Signal %s received.', $signalName);
                $waits[$key]['resolved_at'] = $event->recorded_at ?? $event->created_at;
                $waits[$key]['resume_source_id'] = $event->workflow_command_id;
                $waits[$key]['command_id'] = $event->workflow_command_id;
                $waits[$key]['command_sequence'] = $command?->command_sequence;
                $waits[$key]['command_status'] = $command?->status?->value;
                $waits[$key]['command_outcome'] = $command?->outcome?->value;

                continue;
            }

            if (in_array($event->event_type, [
                HistoryEventType::WorkflowCompleted,
                HistoryEventType::WorkflowFailed,
                HistoryEventType::WorkflowCancelled,
                HistoryEventType::WorkflowTerminated,
                HistoryEventType::WorkflowContinuedAsNew,
            ], true)) {
                self::closeOpenSignalWaits($waits, $openWaitKeysByName, $event);
            }
        }

        return array_values($waits);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function childWaits(WorkflowRun $run): array
    {
        $latestLinks = $run->childLinks
            ->filter(static fn (WorkflowLink $link): bool => $link->link_type === 'child_workflow')
            ->sort(static function (WorkflowLink $left, WorkflowLink $right): int {
                $leftRunNumber = $left->childRun?->run_number ?? 0;
                $rightRunNumber = $right->childRun?->run_number ?? 0;

                if ($leftRunNumber !== $rightRunNumber) {
                    return $rightRunNumber <=> $leftRunNumber;
                }

                $leftCreatedAt = $left->created_at?->getTimestampMs() ?? 0;
                $rightCreatedAt = $right->created_at?->getTimestampMs() ?? 0;

                if ($leftCreatedAt !== $rightCreatedAt) {
                    return $rightCreatedAt <=> $leftCreatedAt;
                }

                return $right->id <=> $left->id;
            })
            ->unique(static fn (WorkflowLink $link): string => (string) ($link->sequence ?? $link->id));

        return $latestLinks
            ->map(static function (WorkflowLink $link) use ($run): array {
                $childRun = $link->childRun;

                /** @var WorkflowHistoryEvent|null $scheduledEvent */
                $scheduledEvent = $run->historyEvents->first(
                    static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::ChildWorkflowScheduled
                        && ($event->payload['sequence'] ?? null) === $link->sequence
                );

                $status = match ($childRun?->status) {
                    RunStatus::Pending, RunStatus::Running, RunStatus::Waiting => 'open',
                    RunStatus::Cancelled, RunStatus::Terminated => 'cancelled',
                    default => 'resolved',
                };

                $label = $childRun?->workflow_type ?? $childRun?->workflow_class ?? 'child workflow';

                $summary = match ($childRun?->status) {
                    RunStatus::Completed => sprintf('Child workflow %s completed.', $label),
                    RunStatus::Failed => sprintf('Child workflow %s failed.', $label),
                    RunStatus::Cancelled => sprintf('Child workflow %s cancelled.', $label),
                    RunStatus::Terminated => sprintf('Child workflow %s terminated.', $label),
                    default => sprintf('Waiting for child workflow %s.', $label),
                };

                return [
                    'id' => sprintf('child:%s', $link->id),
                    'kind' => 'child',
                    'sequence' => $link->sequence,
                    'status' => $status,
                    'source_status' => $childRun?->status?->value,
                    'summary' => $summary,
                    'opened_at' => $scheduledEvent?->recorded_at ?? $scheduledEvent?->created_at ?? $link->created_at,
                    'deadline_at' => null,
                    'resolved_at' => $childRun?->closed_at,
                    'target_name' => $childRun?->workflow_instance_id,
                    'target_type' => $label,
                    'task_backed' => false,
                    'external_only' => false,
                    'resume_source_kind' => 'child_workflow_run',
                    'resume_source_id' => $childRun?->id,
                    'task_id' => null,
                    'task_type' => null,
                    'task_status' => null,
                    'command_id' => null,
                    'command_sequence' => null,
                    'command_status' => null,
                    'command_outcome' => null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string, array<string, mixed>> $waits
     * @param array<string, list<string>> $openWaitKeysByName
     */
    private static function closeOpenSignalWaits(
        array &$waits,
        array &$openWaitKeysByName,
        WorkflowHistoryEvent $event,
    ): void {
        $sourceStatus = match ($event->event_type) {
            HistoryEventType::WorkflowCancelled => 'cancelled',
            HistoryEventType::WorkflowTerminated => 'terminated',
            HistoryEventType::WorkflowContinuedAsNew => 'continued',
            default => 'closed',
        };

        $summary = match ($event->event_type) {
            HistoryEventType::WorkflowCancelled => 'Signal wait ended when the run was cancelled.',
            HistoryEventType::WorkflowTerminated => 'Signal wait ended when the run was terminated.',
            HistoryEventType::WorkflowContinuedAsNew => 'Signal wait ended when the run continued as new.',
            HistoryEventType::WorkflowFailed => 'Signal wait ended when the run failed.',
            default => 'Signal wait ended when the run closed.',
        };

        foreach ($openWaitKeysByName as $signalName => $keys) {
            while ($keys !== []) {
                $key = array_shift($keys);

                if ($key === null || ! isset($waits[$key])) {
                    continue;
                }

                $waits[$key]['status'] = 'cancelled';
                $waits[$key]['source_status'] = $sourceStatus;
                $waits[$key]['summary'] = $summary;
                $waits[$key]['resolved_at'] = $event->recorded_at ?? $event->created_at;
            }

            $openWaitKeysByName[$signalName] = [];
        }
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function intValue(mixed $value): ?int
    {
        return is_int($value)
            ? $value
            : null;
    }
}
