<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\StatusBucket;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;

final class RunSummaryProjector
{
    public static function project(WorkflowRun $run): WorkflowRunSummary
    {
        $run->loadMissing(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents']);
        $run->loadMissing(['childLinks.childRun.instance.currentRun', 'childLinks.childRun.failures']);

        $isTerminal = in_array($run->status, [
            RunStatus::Completed,
            RunStatus::Failed,
            RunStatus::Cancelled,
            RunStatus::Terminated,
        ], true);

        $openActivity = $isTerminal
            ? null
            : $run->activityExecutions
                ->first(static fn (ActivityExecution $execution): bool => in_array(
                    $execution->status,
                    [ActivityStatus::Pending, ActivityStatus::Running],
                    true,
                ));

        $nextTask = $isTerminal
            ? null
            : self::nextOpenTask($run);

        $openTimer = $isTerminal
            ? null
            : $run->timers
                ->first(static fn (WorkflowTimer $timer): bool => $timer->status === TimerStatus::Pending);

        $openChildWait = $isTerminal || $openActivity !== null || $openTimer !== null || $nextTask !== null
            ? null
            : self::openChildWait($run);

        $openSignalWait = $isTerminal
            || $openActivity !== null
            || $openTimer !== null
            || $nextTask !== null
            || $openChildWait !== null
            ? null
            : self::openSignalWait($run);

        $waitKind = null;
        $waitReason = null;
        $waitStartedAt = null;
        $waitDeadlineAt = null;

        if ($openActivity !== null) {
            $waitKind = 'activity';
            $waitReason = sprintf('Waiting for activity %s', $openActivity->activity_type);
            $waitStartedAt = $openActivity->started_at ?? $openActivity->created_at;
        } elseif ($openTimer !== null) {
            $waitKind = 'timer';
            $waitReason = 'Waiting for timer';
            $waitStartedAt = $openTimer->created_at;
            $waitDeadlineAt = $openTimer->fire_at;
        } elseif ($nextTask !== null && $nextTask->task_type === TaskType::Workflow) {
            $waitKind = 'workflow-task';
            $waitReason = match (true) {
                self::taskWaitingForCompatibleWorker($nextTask) => 'Workflow task waiting for a compatible worker',
                TaskRepairPolicy::dispatchFailed($nextTask) => 'Workflow task dispatch failed',
                TaskRepairPolicy::leaseExpired($nextTask) => 'Workflow task lease expired',
                TaskRepairPolicy::dispatchOverdue($nextTask) => 'Workflow task ready but dispatch is overdue',
                $nextTask->status === TaskStatus::Leased => 'Workflow task leased to worker',
                default => 'Workflow task ready',
            };
            $waitStartedAt = $nextTask->leased_at ?? $nextTask->available_at;
            $waitDeadlineAt = $nextTask->lease_expires_at;
        } elseif ($openChildWait !== null) {
            $waitKind = 'child';
            $waitReason = sprintf('Waiting for child workflow %s', $openChildWait['label']);
            $waitStartedAt = $openChildWait['opened_at'];
        } elseif ($openSignalWait !== null) {
            $waitKind = 'signal';
            $waitReason = sprintf('Waiting for signal %s', $openSignalWait['name']);
            $waitStartedAt = $openSignalWait['opened_at'];
        }

        [$livenessState, $livenessReason] = self::liveness(
            $run,
            $isTerminal,
            $openActivity,
            $openTimer,
            $nextTask,
            $openChildWait,
            $openSignalWait,
        );

        $statusBucket = match ($run->status) {
            RunStatus::Completed => StatusBucket::Completed,
            RunStatus::Cancelled, RunStatus::Terminated, RunStatus::Failed => StatusBucket::Failed,
            default => StatusBucket::Running,
        };

        $durationMs = null;

        if ($run->started_at !== null && $run->closed_at !== null) {
            $durationMs = $run->closed_at->diffInMilliseconds($run->started_at);
        }

        $sortTimestamp = RunSummarySortKey::timestamp($run->started_at, $run->created_at, $run->updated_at);

        /** @var WorkflowRunSummary $summary */
        $summary = WorkflowRunSummary::query()->updateOrCreate(
            [
                'id' => $run->id,
            ],
            [
                'workflow_instance_id' => $run->workflow_instance_id,
                'run_number' => $run->run_number,
                'is_current_run' => $run->instance !== null && $run->instance->current_run_id === $run->id,
                'engine_source' => 'v2',
                'class' => $run->workflow_class,
                'workflow_type' => $run->workflow_type,
                'compatibility' => $run->compatibility,
                'status' => $run->status->value,
                'status_bucket' => $statusBucket->value,
                'closed_reason' => $run->closed_reason,
                'connection' => $run->connection,
                'queue' => $run->queue,
                'started_at' => $run->started_at,
                'sort_timestamp' => $sortTimestamp,
                'sort_key' => RunSummarySortKey::key(
                    $run->started_at,
                    $run->created_at,
                    $run->updated_at,
                    $run->id,
                ),
                'closed_at' => $run->closed_at,
                'duration_ms' => $durationMs,
                'wait_kind' => $waitKind,
                'wait_reason' => $waitReason,
                'wait_started_at' => $waitStartedAt,
                'wait_deadline_at' => $waitDeadlineAt,
                'next_task_at' => $nextTask?->available_at,
                'liveness_state' => $livenessState,
                'liveness_reason' => $livenessReason,
                'next_task_id' => $nextTask?->id,
                'next_task_type' => $nextTask?->task_type->value,
                'next_task_status' => $nextTask?->status->value,
                'next_task_lease_expires_at' => $nextTask?->lease_expires_at,
                'exception_count' => $run->failures->count(),
                'created_at' => $run->created_at,
                'updated_at' => $run->closed_at ?? $run->last_progress_at ?? $run->updated_at,
            ],
        );

        return $summary;
    }

    private static function nextOpenTask(WorkflowRun $run): ?WorkflowTask
    {
        /** @var WorkflowTask|null $task */
        $task = $run->tasks
            ->filter(static fn (WorkflowTask $task): bool => in_array(
                $task->status,
                [TaskStatus::Ready, TaskStatus::Leased],
                true,
            ))
            ->sort(static function (WorkflowTask $left, WorkflowTask $right): int {
                $leftStatus = $left->status === TaskStatus::Leased ? 0 : 1;
                $rightStatus = $right->status === TaskStatus::Leased ? 0 : 1;

                if ($leftStatus !== $rightStatus) {
                    return $leftStatus <=> $rightStatus;
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
            ->first();

        return $task;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function liveness(
        WorkflowRun $run,
        bool $isTerminal,
        ?ActivityExecution $openActivity,
        ?WorkflowTimer $openTimer,
        ?WorkflowTask $nextTask,
        ?array $openChildWait,
        ?array $openSignalWait,
    ): array {
        if ($isTerminal) {
            return ['closed', sprintf('Run closed as %s.', $run->closed_reason ?? $run->status->value)];
        }

        if ($openActivity !== null) {
            if ($nextTask !== null) {
                return self::taskLiveness($nextTask, 'Activity');
            }

            if ($openActivity->status === ActivityStatus::Running) {
                return [
                    'activity_running_without_task',
                    sprintf(
                        'Activity %s is already running without an open activity task. Repair is deferred to avoid duplicating in-flight work.',
                        $openActivity->id,
                    ),
                ];
            }

            return [
                'repair_needed',
                sprintf(
                    'Activity %s is %s without an open activity task.',
                    $openActivity->id,
                    $openActivity->status->value,
                ),
            ];
        }

        if ($openTimer !== null) {
            if ($nextTask !== null) {
                if (TaskRepairPolicy::leaseExpired($nextTask) || TaskRepairPolicy::readyTaskNeedsRedispatch(
                    $nextTask
                )) {
                    return self::taskLiveness($nextTask, 'Timer');
                }

                return $nextTask->status === TaskStatus::Leased
                    ? ['timer_task_leased', sprintf('Timer task %s is leased to a worker.', $nextTask->id)]
                    : [
                        'timer_scheduled',
                        sprintf(
                            'Timer task %s is scheduled to fire at %s.',
                            $nextTask->id,
                            $openTimer->fire_at?->toJSON()
                        ),
                    ];
            }

            return ['repair_needed', sprintf('Timer %s is pending without an open timer task.', $openTimer->id)];
        }

        if ($nextTask !== null) {
            return self::taskLiveness($nextTask, 'Workflow');
        }

        if ($openChildWait !== null) {
            return ['waiting_for_child', sprintf('Waiting for child workflow %s.', $openChildWait['label'])];
        }

        if ($openSignalWait !== null) {
            return ['waiting_for_signal', sprintf('Waiting for signal %s.', $openSignalWait['name'])];
        }

        return ['repair_needed', 'Run is non-terminal but has no durable next-resume source.'];
    }

    /**
     * @return array{id: string, name: string, opened_at: \Carbon\CarbonInterface}|null
     */
    private static function openSignalWait(WorkflowRun $run): ?array
    {
        $openSignals = array_values(array_filter(
            SignalWaits::forRun($run),
            static fn (array $wait): bool => $wait['status'] === 'open',
        ));

        if ($openSignals === []) {
            return null;
        }

        uasort($openSignals, static function (array $left, array $right): int {
            $leftSequence = $left['sequence'] ?? PHP_INT_MIN;
            $rightSequence = $right['sequence'] ?? PHP_INT_MIN;

            if ($leftSequence !== $rightSequence) {
                return $leftSequence <=> $rightSequence;
            }

            $leftOpenedAt = $left['opened_at']?->getTimestampMs() ?? PHP_INT_MIN;
            $rightOpenedAt = $right['opened_at']?->getTimestampMs() ?? PHP_INT_MIN;

            if ($leftOpenedAt !== $rightOpenedAt) {
                return $leftOpenedAt <=> $rightOpenedAt;
            }

            return $left['signal_wait_id'] <=> $right['signal_wait_id'];
        });

        /** @var array{id: string, name: string, opened_at: \Carbon\CarbonInterface} $signal */
        $signal = end($openSignals);

        return [
            'id' => $signal['signal_wait_id'],
            'name' => $signal['signal_name'],
            'opened_at' => $signal['opened_at'],
        ];
    }

    /**
     * @return array{label: string, opened_at: \Carbon\CarbonInterface}|null
     */
    private static function openChildWait(WorkflowRun $run): ?array
    {
        $sequences = array_values(array_unique(array_merge(
            $run->historyEvents
                ->filter(
                    static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::ChildWorkflowScheduled
                        && is_int($event->payload['sequence'] ?? null)
                )
                ->map(static fn (WorkflowHistoryEvent $event): int => $event->payload['sequence'])
                ->all(),
            $run->childLinks
                ->filter(static fn ($link): bool => $link->link_type === 'child_workflow' && $link->sequence !== null)
                ->map(static fn ($link): int => (int) $link->sequence)
                ->all(),
        )));

        sort($sequences);

        foreach ($sequences as $sequence) {
            $childRun = ChildRunHistory::childRunForSequence($run, $sequence);

            if (! $childRun instanceof WorkflowRun || ! in_array($childRun->status, [
                RunStatus::Pending,
                RunStatus::Running,
                RunStatus::Waiting,
            ], true)) {
                continue;
            }

            $scheduledEvent = ChildRunHistory::scheduledEventForSequence($run, $sequence);
            $link = ChildRunHistory::latestLinkForSequence($run, $sequence);

            return [
                'label' => $childRun->workflow_type,
                'opened_at' => $scheduledEvent?->recorded_at ?? $scheduledEvent?->created_at ?? $link?->created_at,
            ];
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function taskLiveness(WorkflowTask $task, string $label): array
    {
        if (self::taskWaitingForCompatibleWorker($task)) {
            return [
                sprintf('%s_task_waiting_for_compatible_worker', $task->task_type->value),
                self::compatibleWorkerReason($task, $label),
            ];
        }

        if (TaskRepairPolicy::leaseExpired($task)) {
            return [
                'repair_needed',
                sprintf(
                    '%s task %s lease expired at %s.',
                    $label,
                    $task->id,
                    $task->lease_expires_at?->toJSON() ?? 'an unknown time',
                ),
            ];
        }

        if (TaskRepairPolicy::dispatchFailed($task)) {
            return [
                'repair_needed',
                sprintf(
                    '%s task %s could not be dispatched at %s. %s',
                    $label,
                    $task->id,
                    $task->last_dispatch_attempt_at?->toJSON() ?? 'an unknown time',
                    trim($task->last_dispatch_error ?? 'The queue driver rejected the task.'),
                ),
            ];
        }

        if (TaskRepairPolicy::dispatchOverdue($task)) {
            $reference = $task->last_dispatched_at ?? $task->created_at;

            return [
                'repair_needed',
                sprintf(
                    '%s task %s is ready but has not been dispatched since %s.',
                    $label,
                    $task->id,
                    $reference?->toJSON() ?? 'an unknown time',
                ),
            ];
        }

        return $task->status === TaskStatus::Leased
            ? [
                sprintf('%s_task_leased', $task->task_type->value),
                sprintf('%s task %s is leased to a worker.', $label, $task->id),
            ]
            : [
                sprintf('%s_task_ready', $task->task_type->value),
                sprintf('%s task %s is ready to run.', $label, $task->id),
            ];
    }

    private static function taskWaitingForCompatibleWorker(WorkflowTask $task): bool
    {
        if (WorkerCompatibility::supports($task->compatibility)) {
            return false;
        }

        if (TaskRepairPolicy::leaseExpired($task)) {
            return true;
        }

        return $task->status === TaskStatus::Ready
            && ($task->available_at === null || ! $task->available_at->isFuture());
    }

    private static function compatibleWorkerReason(WorkflowTask $task, string $label): string
    {
        $reason = WorkerCompatibility::mismatchReason($task->compatibility) ?? 'Requires a compatible worker.';

        return match (true) {
            TaskRepairPolicy::leaseExpired($task) => sprintf(
                '%s task %s lease expired and is waiting for a compatible worker. %s',
                $label,
                $task->id,
                $reason,
            ),
            TaskRepairPolicy::dispatchFailed($task) => sprintf(
                '%s task %s could not be dispatched and is waiting for a compatible worker. %s',
                $label,
                $task->id,
                $reason,
            ),
            TaskRepairPolicy::dispatchOverdue($task) => sprintf(
                '%s task %s is ready but dispatch is overdue and is waiting for a compatible worker. %s',
                $label,
                $task->id,
                $reason,
            ),
            default => sprintf(
                '%s task %s is ready but waiting for a compatible worker. %s',
                $label,
                $task->id,
                $reason,
            ),
        };
    }
}
