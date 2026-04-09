<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Carbon;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
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
        $currentRun = $run->instance === null
            ? null
            : CurrentRunResolver::forInstance($run->instance);

        $isTerminal = $run->status->isTerminal();
        $activities = RunActivityView::activitiesForRun($run);

        $openActivity = $isTerminal
            ? null
            : collect($activities)
                ->first(static fn (array $activity): bool => in_array(
                    $activity['status'] ?? null,
                    [ActivityStatus::Pending->value, ActivityStatus::Running->value],
                    true,
                ));

        $nextTask = $isTerminal
            ? null
            : self::nextOpenTask($run);

        $openConditionWait = $isTerminal || $openActivity !== null || ($nextTask?->task_type === TaskType::Workflow)
            ? null
            : self::openConditionWait($run);

        $openTimer = $isTerminal
            ? null
            : $run->timers
                ->first(
                    static fn (WorkflowTimer $timer): bool => $timer->status === TimerStatus::Pending
                        && $timer->id !== ($openConditionWait['timer_id'] ?? null)
                );

        $openChildWait = $isTerminal || $openActivity !== null || $openConditionWait !== null || $openTimer !== null || $nextTask !== null
            ? null
            : self::openChildWait($run);

        $openSignalWait = $isTerminal
            || $openActivity !== null
            || $openConditionWait !== null
            || $openTimer !== null
            || $nextTask !== null
            || $openChildWait !== null
            ? null
            : self::openSignalWait($run);

        $waitKind = null;
        $waitReason = null;
        $waitStartedAt = null;
        $waitDeadlineAt = null;
        $openWaitId = null;
        $resumeSourceKind = null;
        $resumeSourceId = null;

        if ($openActivity !== null) {
            $waitKind = 'activity';
            $waitReason = sprintf('Waiting for activity %s', self::activityType($openActivity));
            $waitStartedAt = self::timestamp($openActivity['started_at'] ?? null)
                ?? self::timestamp($openActivity['created_at'] ?? null);
            $openWaitId = sprintf('activity:%s', $openActivity['id']);
            $resumeSourceKind = 'activity_execution';
            $resumeSourceId = $openActivity['id'];
        } elseif ($openTimer !== null) {
            $waitKind = 'timer';
            $waitReason = 'Waiting for timer';
            $waitStartedAt = $openTimer->created_at;
            $waitDeadlineAt = $openTimer->fire_at;
            $openWaitId = sprintf('timer:%s', $openTimer->id);
            $resumeSourceKind = 'timer';
            $resumeSourceId = $openTimer->id;
        } elseif ($nextTask !== null && $nextTask->task_type === TaskType::Workflow) {
            $waitKind = 'workflow-task';
            $waitReason = match (true) {
                self::taskWaitingForCompatibleWorker(
                    $nextTask,
                    $run
                ) => 'Workflow task waiting for a compatible worker',
                TaskRepairPolicy::dispatchFailed($nextTask) => 'Workflow task dispatch failed',
                TaskRepairPolicy::claimFailed($nextTask) => 'Workflow task claim failed',
                TaskRepairPolicy::leaseExpired($nextTask) => 'Workflow task lease expired',
                TaskRepairPolicy::dispatchOverdue($nextTask) => 'Workflow task ready but dispatch is overdue',
                $nextTask->status === TaskStatus::Leased => 'Workflow task leased to worker',
                default => 'Workflow task ready',
            };
            $waitStartedAt = $nextTask->leased_at ?? $nextTask->available_at;
            $waitDeadlineAt = $nextTask->lease_expires_at;
            $openWaitId = sprintf('workflow-task:%s', $nextTask->id);
            $resumeSourceKind = 'workflow_task';
            $resumeSourceId = $nextTask->id;
        } elseif ($openConditionWait !== null) {
            $waitKind = 'condition';
            $waitReason = $openConditionWait['timer_id'] === null
                ? 'Waiting for condition'
                : 'Waiting for condition or timeout';
            $waitStartedAt = $openConditionWait['opened_at'];
            $waitDeadlineAt = $openConditionWait['deadline_at'];
            $openWaitId = $openConditionWait['id'];
            $resumeSourceKind = $openConditionWait['resume_source_kind'];
            $resumeSourceId = $openConditionWait['resume_source_id'];
        } elseif ($openChildWait !== null) {
            $waitKind = 'child';
            $waitReason = sprintf('Waiting for child workflow %s', $openChildWait['label']);
            $waitStartedAt = $openChildWait['opened_at'];
            $openWaitId = $openChildWait['id'];
            $resumeSourceKind = $openChildWait['resume_source_kind'];
            $resumeSourceId = $openChildWait['resume_source_id'];
        } elseif ($openSignalWait !== null) {
            $waitKind = 'signal';
            $waitReason = sprintf('Waiting for signal %s', $openSignalWait['name']);
            $waitStartedAt = $openSignalWait['opened_at'];
            $openWaitId = $openSignalWait['id'];
            $resumeSourceKind = $openSignalWait['resume_source_kind'];
            $resumeSourceId = $openSignalWait['resume_source_id'];
        }

        [$livenessState, $livenessReason] = self::liveness(
            $run,
            $isTerminal,
            $openActivity,
            $openConditionWait,
            $openTimer,
            $nextTask,
            $openChildWait,
            $openSignalWait,
        );

        $statusBucket = $run->status->statusBucket();
        $historyBudget = HistoryBudget::forRun($run);

        $durationMs = null;

        if ($run->started_at !== null && $run->closed_at !== null) {
            $durationMs = $run->closed_at->diffInMilliseconds($run->started_at);
        }

        $sortTimestamp = RunSummarySortKey::timestamp($run->started_at, $run->created_at, $run->updated_at);
        $summaryModel = self::summaryModel();

        /** @var WorkflowRunSummary $summary */
        $summary = $summaryModel::query()->updateOrCreate(
            [
                'id' => $run->id,
            ],
            [
                'workflow_instance_id' => $run->workflow_instance_id,
                'run_number' => $run->run_number,
                'is_current_run' => $currentRun?->id === $run->id,
                'engine_source' => 'v2',
                'class' => $run->workflow_class,
                'workflow_type' => $run->workflow_type,
                'business_key' => $run->business_key ?? $run->instance?->business_key,
                'visibility_labels' => $run->visibility_labels ?? $run->instance?->visibility_labels,
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
                'archived_at' => $run->archived_at,
                'archive_command_id' => $run->archive_command_id,
                'archive_reason' => $run->archive_reason,
                'duration_ms' => $durationMs,
                'wait_kind' => $waitKind,
                'wait_reason' => $waitReason,
                'wait_started_at' => $waitStartedAt,
                'wait_deadline_at' => $waitDeadlineAt,
                'open_wait_id' => $openWaitId,
                'resume_source_kind' => $resumeSourceKind,
                'resume_source_id' => $resumeSourceId,
                'next_task_at' => $nextTask?->available_at,
                'liveness_state' => $livenessState,
                'liveness_reason' => $livenessReason,
                'next_task_id' => $nextTask?->id,
                'next_task_type' => $nextTask?->task_type->value,
                'next_task_status' => $nextTask?->status->value,
                'next_task_lease_expires_at' => $nextTask?->lease_expires_at,
                'exception_count' => $run->failures->count(),
                'history_event_count' => $historyBudget['history_event_count'],
                'history_size_bytes' => $historyBudget['history_size_bytes'],
                'continue_as_new_recommended' => $historyBudget['continue_as_new_recommended'],
                'created_at' => $run->created_at,
                'updated_at' => $run->closed_at ?? $run->last_progress_at ?? $run->updated_at,
            ],
        );

        return $summary;
    }

    /**
     * @return class-string<WorkflowRunSummary>
     */
    private static function summaryModel(): string
    {
        /** @var class-string<WorkflowRunSummary> $model */
        $model = config('workflows.v2.run_summary_model', WorkflowRunSummary::class);

        return $model;
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
        ?array $openActivity,
        ?array $openConditionWait,
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
                return self::taskLiveness($nextTask, $run, 'Activity');
            }

            if (($openActivity['status'] ?? null) === ActivityStatus::Running->value) {
                return [
                    'activity_running_without_task',
                    sprintf(
                        'Activity %s is already running without an open activity task. Repair is deferred to avoid duplicating in-flight work.',
                        $openActivity['id'],
                    ),
                ];
            }

            return [
                'repair_needed',
                sprintf(
                    'Activity %s is %s without an open activity task.',
                    $openActivity['id'],
                    $openActivity['status'] ?? ActivityStatus::Pending->value,
                ),
            ];
        }

        if ($openConditionWait !== null) {
            if ($openConditionWait['timer_id'] !== null) {
                if ($nextTask !== null) {
                    if (
                        TaskRepairPolicy::leaseExpired($nextTask)
                        || TaskRepairPolicy::readyTaskNeedsRedispatch($nextTask)
                        || TaskRepairPolicy::claimFailed($nextTask)
                    ) {
                        return self::taskLiveness($nextTask, $run, 'Condition timeout');
                    }

                    return [
                        'waiting_for_condition',
                        sprintf(
                            'Waiting for condition-changing input or timeout at %s.',
                            $openConditionWait['deadline_at']?->toJSON() ?? 'an unknown time',
                        ),
                    ];
                }

                return [
                    'repair_needed',
                    sprintf('Condition wait %s is open without an open timeout task.', $openConditionWait['id']),
                ];
            }

            return ['waiting_for_condition', 'Waiting for a condition-changing durable input.'];
        }

        if ($openTimer !== null) {
            if ($nextTask !== null) {
                if (
                    TaskRepairPolicy::leaseExpired($nextTask)
                    || TaskRepairPolicy::readyTaskNeedsRedispatch($nextTask)
                    || TaskRepairPolicy::claimFailed($nextTask)
                ) {
                    return self::taskLiveness($nextTask, $run, 'Timer');
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
            return self::taskLiveness($nextTask, $run, 'Workflow');
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
     * @return array{
     *     id: string,
     *     opened_at: \Carbon\CarbonInterface|null,
     *     deadline_at: \Carbon\CarbonInterface|null,
     *     timer_id: string|null,
     *     timeout_seconds: int|null,
     *     resume_source_kind: string,
     *     resume_source_id: string|null
     * }|null
     */
    private static function openConditionWait(WorkflowRun $run): ?array
    {
        $openConditions = array_values(array_filter(
            ConditionWaits::forRun($run),
            static fn (array $wait): bool => $wait['status'] === 'open',
        ));

        if ($openConditions === []) {
            return null;
        }

        usort($openConditions, static function (array $left, array $right): int {
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

            return $left['condition_wait_id'] <=> $right['condition_wait_id'];
        });

        /** @var array{id: string, condition_wait_id: string, sequence: int|null, opened_at: \Carbon\CarbonInterface|null, deadline_at: \Carbon\CarbonInterface|null, timer_id: string|null, timeout_seconds: int|null, resume_source_kind: string, resume_source_id: string|null} $condition */
        $condition = end($openConditions);

        return [
            'id' => $condition['condition_wait_id'],
            'opened_at' => $condition['opened_at'],
            'deadline_at' => $condition['deadline_at'],
            'timer_id' => $condition['timer_id'],
            'timeout_seconds' => $condition['timeout_seconds'],
            'resume_source_kind' => $condition['resume_source_kind'],
            'resume_source_id' => $condition['resume_source_id'],
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     opened_at: \Carbon\CarbonInterface,
     *     resume_source_kind: string,
     *     resume_source_id: string|null
     * }|null
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
            'resume_source_kind' => 'signal',
            'resume_source_id' => null,
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     opened_at: \Carbon\CarbonInterface,
     *     resume_source_kind: string,
     *     resume_source_id: string|null
     * }|null
     */
    private static function openChildWait(WorkflowRun $run): ?array
    {
        foreach (ChildRunHistory::knownSequences($run) as $sequence) {
            $snapshot = ChildRunHistory::waitSnapshotForSequence($run, $sequence);

            if ($snapshot === null || $snapshot['status'] !== 'open') {
                continue;
            }

            return [
                'id' => sprintf('child:%s', $snapshot['child_call_id'] ?? $sequence),
                'label' => $snapshot['label'],
                'opened_at' => $snapshot['opened_at'],
                'resume_source_kind' => 'child_workflow_run',
                'resume_source_id' => $snapshot['resume_source_id'],
            ];
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function taskLiveness(WorkflowTask $task, WorkflowRun $run, string $label): array
    {
        if (self::taskWaitingForCompatibleWorker($task, $run)) {
            return [
                sprintf('%s_task_waiting_for_compatible_worker', $task->task_type->value),
                self::compatibleWorkerReason($task, $run, $label),
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

        if (TaskRepairPolicy::claimFailed($task)) {
            return [
                sprintf('%s_task_claim_failed', $task->task_type->value),
                sprintf(
                    '%s task %s could not be claimed by a worker at %s. %s',
                    $label,
                    $task->id,
                    $task->last_claim_failed_at?->toJSON() ?? 'an unknown time',
                    trim($task->last_claim_error ?? 'The worker backend capability check rejected the task.'),
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

    private static function taskWaitingForCompatibleWorker(WorkflowTask $task, WorkflowRun $run): bool
    {
        if (TaskCompatibility::supported($task, $run) || TaskCompatibility::supportedInFleet($task, $run)) {
            return false;
        }

        if (TaskRepairPolicy::leaseExpired($task)) {
            return true;
        }

        return $task->status === TaskStatus::Ready
            && ($task->available_at === null || ! $task->available_at->isFuture());
    }

    private static function compatibleWorkerReason(WorkflowTask $task, WorkflowRun $run, string $label): string
    {
        $reasons = array_values(array_unique(array_filter([
            TaskCompatibility::mismatchReason($task, $run),
            TaskCompatibility::fleetMismatchReason($task, $run),
        ])));
        $reason = $reasons === []
            ? 'Requires a compatible worker.'
            : implode(' ', $reasons);

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

    /**
     * @param array<string, mixed> $activity
     */
    private static function activityType(array $activity): string
    {
        return is_string($activity['type'] ?? null) && $activity['type'] !== ''
            ? $activity['type']
            : (is_string($activity['class'] ?? null) && $activity['class'] !== ''
                ? $activity['class']
                : 'activity');
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
