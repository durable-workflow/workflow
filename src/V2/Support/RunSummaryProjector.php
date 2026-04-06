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

        $openSignalWait = $isTerminal || $openActivity !== null || $openTimer !== null || $nextTask !== null
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
            $waitReason = $nextTask->status === TaskStatus::Leased
                ? 'Workflow task leased to worker'
                : 'Workflow task ready';
            $waitStartedAt = $nextTask->leased_at ?? $nextTask->available_at;
            $waitDeadlineAt = $nextTask->lease_expires_at;
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

        $sortTimestamp = RunSummarySortKey::timestamp(
            $run->started_at,
            $run->created_at,
            $run->updated_at,
        );

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
        ?array $openSignalWait,
    ): array {
        if ($isTerminal) {
            return ['closed', sprintf('Run closed as %s.', $run->closed_reason ?? $run->status->value)];
        }

        if ($openActivity !== null) {
            if ($nextTask !== null) {
                return self::taskLiveness($nextTask, 'Activity');
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

        if ($openSignalWait !== null) {
            return [
                'waiting_for_signal',
                sprintf('Waiting for signal %s.', $openSignalWait['name']),
            ];
        }

        return ['repair_needed', 'Run is non-terminal but has no durable next-resume source.'];
    }

    /**
     * @return array{name: string, opened_at: \Carbon\CarbonInterface}|null
     */
    private static function openSignalWait(WorkflowRun $run): ?array
    {
        $openSignals = [];

        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            $sequence = $event->payload['sequence'] ?? null;

            if (! is_int($sequence)) {
                continue;
            }

            if ($event->event_type === HistoryEventType::SignalWaitOpened) {
                $signalName = $event->payload['signal_name'] ?? null;

                if (! is_string($signalName) || $signalName === '') {
                    continue;
                }

                $openSignals[$sequence] = [
                    'name' => $signalName,
                    'opened_at' => $event->recorded_at ?? $event->created_at,
                ];

                continue;
            }

            if ($event->event_type === HistoryEventType::SignalApplied) {
                unset($openSignals[$sequence]);
            }
        }

        if ($openSignals === []) {
            return null;
        }

        ksort($openSignals);

        /** @var array{name: string, opened_at: \Carbon\CarbonInterface} $signal */
        $signal = end($openSignals);

        return $signal;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function taskLiveness(WorkflowTask $task, string $label): array
    {
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
}
