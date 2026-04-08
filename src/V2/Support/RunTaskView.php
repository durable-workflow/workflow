<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;

final class RunTaskView
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing(['tasks', 'activityExecutions', 'timers']);

        $activities = $run->activityExecutions->keyBy('id');
        $timers = $run->timers->keyBy('id');

        return $run->tasks
            ->sort(static function (WorkflowTask $left, WorkflowTask $right): int {
                $leftOpen = in_array($left->status, [TaskStatus::Ready, TaskStatus::Leased], true) ? 0 : 1;
                $rightOpen = in_array($right->status, [TaskStatus::Ready, TaskStatus::Leased], true) ? 0 : 1;

                if ($leftOpen !== $rightOpen) {
                    return $leftOpen <=> $rightOpen;
                }

                $leftAvailableAt = $left->available_at?->getTimestampMs() ?? PHP_INT_MAX;
                $rightAvailableAt = $right->available_at?->getTimestampMs() ?? PHP_INT_MAX;

                if ($leftAvailableAt !== $rightAvailableAt) {
                    return $leftAvailableAt <=> $rightAvailableAt;
                }

                $leftCreatedAt = $left->created_at?->getTimestampMs() ?? PHP_INT_MAX;
                $rightCreatedAt = $right->created_at?->getTimestampMs() ?? PHP_INT_MAX;

                if ($leftCreatedAt !== $rightCreatedAt) {
                    return $leftCreatedAt <=> $rightCreatedAt;
                }

                return $left->id <=> $right->id;
            })
            ->map(
                static function (WorkflowTask $task) use ($activities, $run, $timers): array {
                    $activityExecutionId = self::stringValue($task->payload['activity_execution_id'] ?? null);
                    $timerId = self::stringValue($task->payload['timer_id'] ?? null);
                    $compatibility = TaskCompatibility::resolve($task, $run);

                    /** @var ActivityExecution|null $activity */
                    $activity = $activityExecutionId === null ? null : $activities->get($activityExecutionId);
                    /** @var WorkflowTimer|null $timer */
                    $timer = $timerId === null ? null : $timers->get($timerId);

                    return [
                        'id' => $task->id,
                        'type' => $task->task_type->value,
                        'status' => $task->status->value,
                        'transport_state' => self::transportState($task),
                        'summary' => self::summaryFor($task, $activity, $timer, $compatibility),
                        'compatibility' => $compatibility,
                        'compatibility_supported' => WorkerCompatibility::supports($compatibility),
                        'compatibility_reason' => WorkerCompatibility::mismatchReason($compatibility),
                        'compatibility_supported_in_fleet' => TaskCompatibility::supportedInFleet($task, $run),
                        'compatibility_fleet_reason' => TaskCompatibility::fleetMismatchReason($task, $run),
                        'dispatch_failed' => TaskRepairPolicy::dispatchFailed($task),
                        'dispatch_overdue' => TaskRepairPolicy::dispatchOverdue($task),
                        'is_open' => in_array($task->status, [TaskStatus::Ready, TaskStatus::Leased], true),
                        'available_at' => $task->available_at,
                        'last_dispatch_attempt_at' => $task->last_dispatch_attempt_at,
                        'leased_at' => $task->leased_at,
                        'last_dispatched_at' => $task->last_dispatched_at,
                        'last_dispatch_error' => $task->last_dispatch_error,
                        'lease_expired' => TaskRepairPolicy::leaseExpired($task),
                        'lease_owner' => $task->lease_owner,
                        'lease_expires_at' => $task->lease_expires_at,
                        'attempt_count' => $task->attempt_count,
                        'repair_count' => $task->repair_count,
                        'last_error' => $task->last_error,
                        'connection' => $task->connection,
                        'queue' => $task->queue,
                        'activity_execution_id' => $activityExecutionId,
                        'activity_type' => $activity?->activity_type,
                        'activity_class' => $activity?->activity_class,
                        'timer_id' => $timerId,
                        'timer_sequence' => $timer?->sequence,
                        'timer_fire_at' => $timer?->fire_at,
                        'created_at' => $task->created_at,
                        'updated_at' => $task->updated_at,
                    ];
                }
            )
            ->values()
            ->all();
    }

    private static function summaryFor(
        WorkflowTask $task,
        ?ActivityExecution $activity,
        ?WorkflowTimer $timer,
        ?string $compatibility,
    ): string {
        if (self::taskWaitingForCompatibleWorker($task, $compatibility)) {
            return match ($task->task_type) {
                TaskType::Workflow => match (true) {
                    TaskRepairPolicy::leaseExpired(
                        $task
                    ) => 'Workflow task lease expired and is waiting for a compatible worker.',
                    TaskRepairPolicy::dispatchFailed(
                        $task
                    ) => 'Workflow task dispatch failed and is waiting for a compatible worker.',
                    TaskRepairPolicy::dispatchOverdue(
                        $task
                    ) => 'Workflow task is waiting for a compatible worker; dispatch is overdue.',
                    default => 'Workflow task is waiting for a compatible worker.',
                },
                TaskType::Activity => sprintf(
                    TaskRepairPolicy::leaseExpired($task)
                        ? 'Activity task lease expired and is waiting for a compatible worker for %s.'
                        : (TaskRepairPolicy::dispatchFailed($task)
                            ? 'Activity task dispatch failed and is waiting for a compatible worker for %s.'
                            : (TaskRepairPolicy::dispatchOverdue($task)
                            ? 'Activity task is waiting for a compatible worker for %s; dispatch is overdue.'
                            : 'Activity task is waiting for a compatible worker for %s.')),
                    $activity?->activity_type ?? $activity?->activity_class ?? 'activity',
                ),
                TaskType::Timer => sprintf(
                    TaskRepairPolicy::leaseExpired($task)
                        ? '%s lease expired and is waiting for a compatible worker.'
                        : (TaskRepairPolicy::dispatchFailed($task)
                            ? '%s dispatch failed and is waiting for a compatible worker.'
                            : (TaskRepairPolicy::dispatchOverdue($task)
                            ? '%s is waiting for a compatible worker; dispatch is overdue.'
                            : '%s is waiting for a compatible worker.')),
                    ucfirst($timer?->delay_seconds === null
                        ? 'timer task'
                        : sprintf(
                            'timer for %s second%s task',
                            $timer->delay_seconds,
                            $timer->delay_seconds === 1 ? '' : 's'
                        )),
                ),
            };
        }

        if (TaskRepairPolicy::leaseExpired($task)) {
            return match ($task->task_type) {
                TaskType::Workflow => 'Workflow task lease expired; waiting for recovery.',
                TaskType::Activity => sprintf(
                    'Activity task lease expired for %s; waiting for recovery.',
                    $activity?->activity_type ?? $activity?->activity_class ?? 'activity',
                ),
                TaskType::Timer => sprintf(
                    '%s lease expired; waiting for recovery.',
                    ucfirst($timer?->delay_seconds === null
                        ? 'timer task'
                        : sprintf(
                            'timer for %s second%s task',
                            $timer->delay_seconds,
                            $timer->delay_seconds === 1 ? '' : 's'
                        )),
                ),
            };
        }

        if (TaskRepairPolicy::dispatchFailed($task)) {
            return match ($task->task_type) {
                TaskType::Workflow => 'Workflow task dispatch failed; waiting for recovery.',
                TaskType::Activity => sprintf(
                    'Activity task dispatch failed for %s; waiting for recovery.',
                    $activity?->activity_type ?? $activity?->activity_class ?? 'activity',
                ),
                TaskType::Timer => sprintf(
                    '%s dispatch failed; waiting for recovery.',
                    ucfirst($timer?->delay_seconds === null
                        ? 'timer task'
                        : sprintf(
                            'timer for %s second%s task',
                            $timer->delay_seconds,
                            $timer->delay_seconds === 1 ? '' : 's'
                        )),
                ),
            };
        }

        if (TaskRepairPolicy::dispatchOverdue($task)) {
            return match ($task->task_type) {
                TaskType::Workflow => 'Workflow task is ready but dispatch is overdue.',
                TaskType::Activity => sprintf(
                    'Activity task is ready but dispatch is overdue for %s.',
                    $activity?->activity_type ?? $activity?->activity_class ?? 'activity',
                ),
                TaskType::Timer => sprintf(
                    '%s is ready but dispatch is overdue.',
                    ucfirst($timer?->delay_seconds === null
                        ? 'timer task'
                        : sprintf(
                            'timer for %s second%s task',
                            $timer->delay_seconds,
                            $timer->delay_seconds === 1 ? '' : 's'
                        )),
                ),
            };
        }

        return match ($task->task_type) {
            TaskType::Workflow => match ($task->status) {
                TaskStatus::Ready => 'Workflow task ready to resume the selected run.',
                TaskStatus::Leased => 'Workflow task leased to a worker.',
                TaskStatus::Completed => 'Workflow task completed.',
                TaskStatus::Cancelled => 'Workflow task cancelled.',
                TaskStatus::Failed => 'Workflow task failed.',
            },
            TaskType::Activity => self::activitySummary($task, $activity),
            TaskType::Timer => self::timerSummary($task, $timer),
        };
    }

    private static function activitySummary(WorkflowTask $task, ?ActivityExecution $activity): string
    {
        $label = $activity?->activity_type ?? $activity?->activity_class ?? 'activity';

        return match ($task->status) {
            TaskStatus::Ready => sprintf('Activity task ready for %s.', $label),
            TaskStatus::Leased => sprintf('Activity task leased for %s.', $label),
            TaskStatus::Completed => sprintf('Activity task completed for %s.', $label),
            TaskStatus::Cancelled => sprintf('Activity task cancelled for %s.', $label),
            TaskStatus::Failed => sprintf('Activity task failed for %s.', $label),
        };
    }

    private static function timerSummary(WorkflowTask $task, ?WorkflowTimer $timer): string
    {
        $label = $timer?->delay_seconds === null
            ? 'timer'
            : sprintf('timer for %s second%s', $timer->delay_seconds, $timer->delay_seconds === 1 ? '' : 's');

        return match ($task->status) {
            TaskStatus::Ready => sprintf('%s task ready.', ucfirst($label)),
            TaskStatus::Leased => sprintf('%s task leased to a worker.', ucfirst($label)),
            TaskStatus::Completed => sprintf('%s task completed.', ucfirst($label)),
            TaskStatus::Cancelled => sprintf('%s task cancelled.', ucfirst($label)),
            TaskStatus::Failed => sprintf('%s task failed.', ucfirst($label)),
        };
    }

    private static function taskWaitingForCompatibleWorker(WorkflowTask $task, ?string $compatibility): bool
    {
        if (
            WorkerCompatibility::supports($compatibility)
            || WorkerCompatibilityFleet::supports($compatibility, $task->connection, $task->queue)
        ) {
            return false;
        }

        if (TaskRepairPolicy::leaseExpired($task)) {
            return true;
        }

        return $task->status === TaskStatus::Ready
            && ($task->available_at === null || ! $task->available_at->isFuture());
    }

    private static function transportState(WorkflowTask $task): string
    {
        if ($task->status === TaskStatus::Leased) {
            return TaskRepairPolicy::leaseExpired($task)
                ? 'lease_expired'
                : 'leased';
        }

        if ($task->status !== TaskStatus::Ready) {
            return $task->status->value;
        }

        if ($task->available_at !== null && $task->available_at->isFuture()) {
            return TaskRepairPolicy::dispatchFailed($task)
                ? 'dispatch_failed'
                : 'scheduled';
        }

        if (TaskRepairPolicy::dispatchFailed($task)) {
            return 'dispatch_failed';
        }

        if (TaskRepairPolicy::dispatchOverdue($task)) {
            return 'dispatch_overdue';
        }

        return 'ready';
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
