<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Collection;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class RunTaskView
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing(['tasks', 'activityExecutions', 'timers', 'historyEvents']);

        /** @var Collection<string, array<string, mixed>> $activities */
        $activities = collect(RunActivityView::activitiesForRun($run))
            ->filter(static fn (array $activity): bool => is_string($activity['id'] ?? null))
            ->keyBy(static fn (array $activity): string => $activity['id']);
        /** @var Collection<string, array<string, mixed>> $timers */
        $timers = collect(RunTimerView::timersForRun($run))
            ->filter(static fn (array $timer): bool => is_string($timer['id'] ?? null))
            ->keyBy(static fn (array $timer): string => $timer['id']);

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
                    $conditionWaitId = self::stringValue($task->payload['condition_wait_id'] ?? null);
                    $workflowWaitKind = self::stringValue($task->payload['workflow_wait_kind'] ?? null);
                    $workflowResumeSourceKind = self::stringValue($task->payload['resume_source_kind'] ?? null);
                    $workflowResumeSourceId = self::stringValue($task->payload['resume_source_id'] ?? null);
                    $compatibility = TaskCompatibility::resolve($task, $run);

                    /** @var array<string, mixed>|null $activity */
                    $activity = $activityExecutionId === null ? null : $activities->get($activityExecutionId);
                    /** @var array<string, mixed>|null $timer */
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
                        'claim_failed' => TaskRepairPolicy::claimFailed($task),
                        'is_open' => in_array($task->status, [TaskStatus::Ready, TaskStatus::Leased], true),
                        'available_at' => $task->available_at,
                        'last_dispatch_attempt_at' => $task->last_dispatch_attempt_at,
                        'leased_at' => $task->leased_at,
                        'last_dispatched_at' => $task->last_dispatched_at,
                        'last_dispatch_error' => $task->last_dispatch_error,
                        'last_claim_failed_at' => $task->last_claim_failed_at,
                        'last_claim_error' => $task->last_claim_error,
                        'repair_available_at' => $task->repair_available_at,
                        'repair_backoff_seconds' => TaskRepairPolicy::failureBackoffSeconds($task),
                        'lease_expired' => TaskRepairPolicy::leaseExpired($task),
                        'lease_owner' => $task->lease_owner,
                        'lease_expires_at' => $task->lease_expires_at,
                        'attempt_count' => $task->attempt_count,
                        'repair_count' => $task->repair_count,
                        'last_error' => $task->last_error,
                        'connection' => $task->connection,
                        'queue' => $task->queue,
                        'activity_execution_id' => $activityExecutionId,
                        'activity_type' => self::stringValue($activity['type'] ?? null),
                        'activity_class' => self::stringValue($activity['class'] ?? null),
                        'retry_of_task_id' => self::stringValue($task->payload['retry_of_task_id'] ?? null),
                        'retry_after_attempt_id' => self::stringValue($task->payload['retry_after_attempt_id'] ?? null),
                        'retry_after_attempt' => self::intValue($task->payload['retry_after_attempt'] ?? null),
                        'retry_backoff_seconds' => self::intValue($task->payload['retry_backoff_seconds'] ?? null),
                        'retry_max_attempts' => self::intValue($task->payload['max_attempts'] ?? null),
                        'retry_policy' => is_array($task->payload['retry_policy'] ?? null) ? $task->payload['retry_policy'] : null,
                        'timer_id' => $timerId,
                        'timer_sequence' => self::intValue($timer['sequence'] ?? null),
                        'timer_fire_at' => $timer['fire_at'] ?? null,
                        'condition_wait_id' => $conditionWaitId,
                        'workflow_wait_kind' => $workflowWaitKind,
                        'workflow_open_wait_id' => self::stringValue($task->payload['open_wait_id'] ?? null),
                        'workflow_resume_source_kind' => $workflowResumeSourceKind,
                        'workflow_resume_source_id' => $workflowResumeSourceId,
                        'workflow_update_id' => self::stringValue($task->payload['workflow_update_id'] ?? null),
                        'workflow_signal_id' => self::stringValue($task->payload['workflow_signal_id'] ?? null),
                        'workflow_command_id' => self::stringValue($task->payload['workflow_command_id'] ?? null),
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
        ?array $activity,
        ?array $timer,
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
                    self::activityLabel($activity),
                ),
                TaskType::Timer => sprintf(
                    TaskRepairPolicy::leaseExpired($task)
                        ? '%s lease expired and is waiting for a compatible worker.'
                        : (TaskRepairPolicy::dispatchFailed($task)
                            ? '%s dispatch failed and is waiting for a compatible worker.'
                            : (TaskRepairPolicy::dispatchOverdue($task)
                            ? '%s is waiting for a compatible worker; dispatch is overdue.'
                            : '%s is waiting for a compatible worker.')),
                    ucfirst(self::timerLabel($task, $timer) . ' task'),
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
                    ucfirst(self::timerLabel($task, $timer) . ' task'),
                ),
            };
        }

        if (TaskRepairPolicy::dispatchFailed($task)) {
            if ($task->repair_available_at !== null && $task->repair_available_at->isFuture()) {
                return match ($task->task_type) {
                    TaskType::Workflow => sprintf(
                        'Workflow task dispatch failed; next repair is available at %s.',
                        $task->repair_available_at->toJSON(),
                    ),
                    TaskType::Activity => sprintf(
                        'Activity task dispatch failed for %s; next repair is available at %s.',
                        $activity?->activity_type ?? $activity?->activity_class ?? 'activity',
                        $task->repair_available_at->toJSON(),
                    ),
                    TaskType::Timer => sprintf(
                        '%s dispatch failed; next repair is available at %s.',
                        ucfirst(self::timerLabel($task, $timer) . ' task'),
                        $task->repair_available_at->toJSON(),
                    ),
                };
            }

            return match ($task->task_type) {
                TaskType::Workflow => 'Workflow task dispatch failed; waiting for recovery.',
                TaskType::Activity => sprintf(
                    'Activity task dispatch failed for %s; waiting for recovery.',
                    $activity?->activity_type ?? $activity?->activity_class ?? 'activity',
                ),
                TaskType::Timer => sprintf(
                    '%s dispatch failed; waiting for recovery.',
                    ucfirst(self::timerLabel($task, $timer) . ' task'),
                ),
            };
        }

        if (TaskRepairPolicy::claimFailed($task)) {
            if ($task->repair_available_at !== null && $task->repair_available_at->isFuture()) {
                return match ($task->task_type) {
                    TaskType::Workflow => sprintf(
                        'Workflow task claim failed; next repair is available at %s.',
                        $task->repair_available_at->toJSON(),
                    ),
                    TaskType::Activity => sprintf(
                        'Activity task claim failed for %s; next repair is available at %s.',
                        $activity?->activity_type ?? $activity?->activity_class ?? 'activity',
                        $task->repair_available_at->toJSON(),
                    ),
                    TaskType::Timer => sprintf(
                        '%s claim failed; next repair is available at %s.',
                        ucfirst(self::timerLabel($task, $timer) . ' task'),
                        $task->repair_available_at->toJSON(),
                    ),
                };
            }

            return match ($task->task_type) {
                TaskType::Workflow => 'Workflow task claim failed; worker backend capability is unsupported.',
                TaskType::Activity => sprintf(
                    'Activity task claim failed for %s; worker backend capability is unsupported.',
                    $activity?->activity_type ?? $activity?->activity_class ?? 'activity',
                ),
                TaskType::Timer => sprintf(
                    '%s claim failed; worker backend capability is unsupported.',
                    ucfirst(self::timerLabel($task, $timer) . ' task'),
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
                    ucfirst(self::timerLabel($task, $timer) . ' task'),
                ),
            };
        }

        return match ($task->task_type) {
            TaskType::Workflow => match ($task->status) {
                TaskStatus::Ready => match (self::stringValue($task->payload['workflow_wait_kind'] ?? null)) {
                    'update' => 'Workflow task ready to apply accepted update.',
                    'signal' => 'Workflow task ready to apply accepted signal.',
                    default => 'Workflow task ready to resume the selected run.',
                },
                TaskStatus::Leased => match (self::stringValue($task->payload['workflow_wait_kind'] ?? null)) {
                    'update' => 'Workflow task leased to apply accepted update.',
                    'signal' => 'Workflow task leased to apply accepted signal.',
                    default => 'Workflow task leased to a worker.',
                },
                TaskStatus::Completed => 'Workflow task completed.',
                TaskStatus::Cancelled => 'Workflow task cancelled.',
                TaskStatus::Failed => 'Workflow task failed.',
            },
            TaskType::Activity => self::activitySummary($task, $activity),
            TaskType::Timer => self::timerSummary($task, $timer),
        };
    }

    private static function activitySummary(WorkflowTask $task, ?array $activity): string
    {
        $label = self::activityLabel($activity);
        $retryAfterAttempt = self::intValue($task->payload['retry_after_attempt'] ?? null);

        if ($retryAfterAttempt !== null) {
            $retryNumber = $retryAfterAttempt + 1;

            return match ($task->status) {
                TaskStatus::Ready => $task->available_at !== null && $task->available_at->isFuture()
                    ? sprintf('Activity retry %d for %s scheduled for %s.', $retryNumber, $label, $task->available_at->toJSON())
                    : sprintf('Activity retry %d ready for %s.', $retryNumber, $label),
                TaskStatus::Leased => sprintf('Activity retry %d leased for %s.', $retryNumber, $label),
                TaskStatus::Completed => sprintf('Activity retry %d completed for %s.', $retryNumber, $label),
                TaskStatus::Cancelled => sprintf('Activity retry %d cancelled for %s.', $retryNumber, $label),
                TaskStatus::Failed => sprintf('Activity retry %d failed for %s.', $retryNumber, $label),
            };
        }

        return match ($task->status) {
            TaskStatus::Ready => sprintf('Activity task ready for %s.', $label),
            TaskStatus::Leased => sprintf('Activity task leased for %s.', $label),
            TaskStatus::Completed => sprintf('Activity task completed for %s.', $label),
            TaskStatus::Cancelled => sprintf('Activity task cancelled for %s.', $label),
            TaskStatus::Failed => sprintf('Activity task failed for %s.', $label),
        };
    }

    private static function timerSummary(WorkflowTask $task, ?array $timer): string
    {
        $label = self::timerLabel($task, $timer);

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
            if (TaskRepairPolicy::dispatchFailed($task) && ! TaskRepairPolicy::dispatchFailedNeedsRedispatch($task)) {
                return 'repair_backoff';
            }

            return TaskRepairPolicy::dispatchFailed($task)
                ? 'dispatch_failed'
                : 'scheduled';
        }

        if (TaskRepairPolicy::dispatchFailed($task)) {
            if (! TaskRepairPolicy::dispatchFailedNeedsRedispatch($task)) {
                return 'repair_backoff';
            }

            return 'dispatch_failed';
        }

        if (TaskRepairPolicy::claimFailed($task)) {
            if (! TaskRepairPolicy::claimFailedNeedsRedispatch($task)) {
                return 'repair_backoff';
            }

            return 'claim_failed';
        }

        if (TaskRepairPolicy::dispatchOverdue($task)) {
            return 'dispatch_overdue';
        }

        return 'ready';
    }

    private static function activityLabel(?array $activity): string
    {
        return self::stringValue($activity['type'] ?? null)
            ?? self::stringValue($activity['class'] ?? null)
            ?? 'activity';
    }

    private static function timerLabel(WorkflowTask $task, ?array $timer): string
    {
        $delaySeconds = self::intValue($timer['delay_seconds'] ?? null);

        if (self::stringValue($task->payload['condition_wait_id'] ?? null) !== null) {
            return $delaySeconds === null
                ? 'condition timeout'
                : sprintf(
                    'condition timeout for %s second%s',
                    $delaySeconds,
                    $delaySeconds === 1 ? '' : 's',
                );
        }

        return $delaySeconds === null
            ? 'timer'
            : sprintf('timer for %s second%s', $delaySeconds, $delaySeconds === 1 ? '' : 's');
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
}
