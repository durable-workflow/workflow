<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;

final class TaskRepair
{
    public static function repairRun(WorkflowRun $run, WorkflowRunSummary $summary): ?WorkflowTask
    {
        $task = self::recoverableTask($run);

        if ($task !== null) {
            return self::recoverExistingTask($task, $run);
        }

        return self::createMissingTask($run, $summary);
    }

    public static function recoverExistingTask(WorkflowTask $task, WorkflowRun $run): ?WorkflowTask
    {
        if (in_array($run->status, [
            RunStatus::Completed,
            RunStatus::Failed,
            RunStatus::Cancelled,
            RunStatus::Terminated,
        ], true)) {
            self::settleTerminalTask($task, $run);

            return null;
        }

        if ($task->status === TaskStatus::Ready) {
            if (! TaskRepairPolicy::readyTaskNeedsRedispatch($task)) {
                return null;
            }

            $task->forceFill([
                'repair_count' => $task->repair_count + 1,
                'repair_available_at' => null,
                'last_error' => null,
            ])->save();

            return $task;
        }

        if ($task->status === TaskStatus::Leased) {
            if (! TaskRepairPolicy::leaseExpired($task)) {
                return null;
            }

            self::closeExpiredActivityAttempt($task);

            $task->forceFill([
                'status' => TaskStatus::Ready,
                'leased_at' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'repair_count' => $task->repair_count + 1,
                'repair_available_at' => null,
                'last_error' => null,
            ])->save();

            return $task;
        }

        if ($task->status === TaskStatus::Failed && self::replayBlocked($task)) {
            $payload = self::clearReplayBlockedPayload($task);

            $task->forceFill([
                'status' => TaskStatus::Ready,
                'payload' => $payload,
                'leased_at' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'repair_count' => $task->repair_count + 1,
                'repair_available_at' => null,
                'last_error' => null,
            ])->save();

            return $task;
        }

        return null;
    }

    private static function recoverableTask(WorkflowRun $run): ?WorkflowTask
    {
        /** @var WorkflowTask|null $task */
        $task = $run->tasks
            ->filter(static fn (WorkflowTask $task): bool => TaskRepairPolicy::readyTaskNeedsRedispatch($task)
                || TaskRepairPolicy::leaseExpired($task)
                || self::replayBlocked($task))
            ->sort(static function (WorkflowTask $left, WorkflowTask $right): int {
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

    private static function replayBlocked(WorkflowTask $task): bool
    {
        return $task->task_type === TaskType::Workflow
            && $task->status === TaskStatus::Failed
            && ($task->payload['replay_blocked'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function clearReplayBlockedPayload(WorkflowTask $task): array
    {
        $payload = is_array($task->payload) ? $task->payload : [];

        foreach (array_keys($payload) as $key) {
            if (is_string($key) && str_starts_with($key, 'replay_blocked')) {
                unset($payload[$key]);
            }
        }

        return $payload;
    }

    private static function createMissingTask(WorkflowRun $run, WorkflowRunSummary $summary): ?WorkflowTask
    {
        if ($summary->wait_kind === 'activity') {
            $execution = ActivityRecovery::pendingExecutionForSummary($run, $summary);

            if ($execution instanceof ActivityExecution) {
                $taskAttributes = self::missingActivityTaskAttributes($run, $execution);

                /** @var WorkflowTask $task */
                $task = WorkflowTask::query()->create([
                    'workflow_run_id' => $run->id,
                    'task_type' => TaskType::Activity->value,
                    'status' => TaskStatus::Ready->value,
                    'available_at' => $taskAttributes['available_at'],
                    'payload' => $taskAttributes['payload'],
                    'connection' => $execution->connection ?? $run->connection,
                    'queue' => $execution->queue ?? $run->queue,
                    'compatibility' => $run->compatibility,
                    'attempt_count' => max(0, (int) $execution->attempt_count),
                    'repair_count' => 1,
                ]);

                return $task;
            }
        }

        if ($summary->wait_kind === 'timer') {
            $timer = self::restorePendingTimerFromSummary($run, $summary);

            if ($timer instanceof WorkflowTimer) {
                /** @var WorkflowTask $task */
                $task = WorkflowTask::query()->create([
                    'workflow_run_id' => $run->id,
                    'task_type' => TaskType::Timer->value,
                    'status' => TaskStatus::Ready->value,
                    'available_at' => $timer->fire_at !== null && $timer->fire_at->isFuture()
                        ? $timer->fire_at
                        : now(),
                    'payload' => [
                        'timer_id' => $timer->id,
                    ],
                    'connection' => $run->connection,
                    'queue' => $run->queue,
                    'compatibility' => $run->compatibility,
                    'repair_count' => 1,
                ]);

                return $task;
            }
        }

        if ($summary->wait_kind === 'condition' && $summary->resume_source_kind === 'timer') {
            $conditionWait = self::openConditionWait($run, $summary);
            $timerId = self::nonEmptyString($conditionWait['timer_id'] ?? null)
                ?? self::nonEmptyString($summary->resume_source_id);
            $timer = $timerId === null ? null : TimerRecovery::restore($run, $timerId);
            $availableAt = self::timestamp($conditionWait['deadline_at'] ?? null)
                ?? $timer?->fire_at
                ?? now();

            if ($timer instanceof WorkflowTimer && $timerId !== null) {
                /** @var WorkflowTask $task */
                $task = WorkflowTask::query()->create([
                    'workflow_run_id' => $run->id,
                    'task_type' => TaskType::Timer->value,
                    'status' => TaskStatus::Ready->value,
                    'available_at' => $availableAt->isFuture() ? $availableAt : now(),
                    'payload' => array_filter([
                        'timer_id' => $timerId,
                        'condition_wait_id' => self::nonEmptyString($conditionWait['condition_wait_id'] ?? null),
                        'condition_key' => self::nonEmptyString($conditionWait['condition_key'] ?? null),
                    ], static fn (mixed $value): bool => $value !== null),
                    'connection' => $run->connection,
                    'queue' => $run->queue,
                    'compatibility' => $run->compatibility,
                    'repair_count' => 1,
                ]);

                return $task;
            }
        }

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => WorkflowTaskPayload::forMissingWorkflowTask($summary),
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
            'repair_count' => 1,
        ]);

        return $task;
    }

    /**
     * @return array{available_at: CarbonInterface, payload: array<string, mixed>}
     */
    private static function missingActivityTaskAttributes(WorkflowRun $run, ActivityExecution $execution): array
    {
        $payload = [
            'activity_execution_id' => $execution->id,
        ];

        $retryEvent = self::latestRetryScheduledEvent($run, $execution);

        if (! $retryEvent instanceof WorkflowHistoryEvent) {
            return [
                'available_at' => now(),
                'payload' => $payload,
            ];
        }

        $retryPayload = is_array($retryEvent->payload) ? $retryEvent->payload : [];

        foreach ([
            'retry_of_task_id',
            'retry_after_attempt_id',
            'retry_after_attempt',
            'retry_backoff_seconds',
            'max_attempts',
            'retry_policy',
        ] as $key) {
            if (array_key_exists($key, $retryPayload)) {
                $payload[$key] = $retryPayload[$key];
            }
        }

        if (! array_key_exists('retry_policy', $payload) && is_array($execution->retry_policy)) {
            $payload['retry_policy'] = $execution->retry_policy;
        }

        return [
            'available_at' => self::timestamp($retryPayload['retry_available_at'] ?? null) ?? now(),
            'payload' => $payload,
        ];
    }

    private static function restorePendingTimerFromSummary(
        WorkflowRun $run,
        WorkflowRunSummary $summary,
    ): ?WorkflowTimer {
        $timerId = self::nonEmptyString($summary->resume_source_id);

        if ($timerId !== null) {
            $timer = TimerRecovery::restore($run, $timerId);

            if ($timer instanceof WorkflowTimer && $timer->status === TimerStatus::Pending) {
                return $timer;
            }
        }

        foreach (RunTimerView::timersForRun($run) as $snapshot) {
            if (($snapshot['timer_kind'] ?? null) === 'condition_timeout') {
                continue;
            }

            if (($snapshot['status'] ?? null) !== TimerStatus::Pending->value) {
                continue;
            }

            $snapshotTimerId = self::nonEmptyString($snapshot['id'] ?? null);

            if ($snapshotTimerId === null) {
                continue;
            }

            $timer = TimerRecovery::restore($run, $snapshotTimerId);

            if ($timer instanceof WorkflowTimer && $timer->status === TimerStatus::Pending) {
                return $timer;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function openConditionWait(WorkflowRun $run, WorkflowRunSummary $summary): ?array
    {
        foreach (ConditionWaits::forRun($run) as $wait) {
            if (($wait['status'] ?? null) !== 'open') {
                continue;
            }

            if (($wait['condition_wait_id'] ?? null) === $summary->open_wait_id) {
                return $wait;
            }
        }

        $timerId = self::nonEmptyString($summary->resume_source_id);

        if ($timerId === null) {
            return null;
        }

        foreach (ConditionWaits::forRun($run) as $wait) {
            if (($wait['status'] ?? null) !== 'open') {
                continue;
            }

            if (($wait['timer_id'] ?? null) === $timerId) {
                return $wait;
            }
        }

        return null;
    }

    private static function latestRetryScheduledEvent(
        WorkflowRun $run,
        ActivityExecution $execution,
    ): ?WorkflowHistoryEvent {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents
            ->filter(static function (WorkflowHistoryEvent $event) use ($execution): bool {
                if ($event->event_type !== HistoryEventType::ActivityRetryScheduled) {
                    return false;
                }

                $payload = is_array($event->payload) ? $event->payload : [];

                return ($payload['activity_execution_id'] ?? null) === $execution->id;
            })
            ->sortByDesc('sequence')
            ->first();

        return $event;
    }

    private static function timestamp(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function settleTerminalTask(WorkflowTask $task, WorkflowRun $run): void
    {
        self::closeTerminalActivityAttempt($task, $run);

        $task->forceFill([
            'status' => $task->status === TaskStatus::Cancelled
                ? TaskStatus::Cancelled
                : match ($run->status) {
                    RunStatus::Failed => TaskStatus::Failed,
                    default => TaskStatus::Completed,
                },
            'leased_at' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
        ])->save();
    }

    private static function closeExpiredActivityAttempt(WorkflowTask $task): void
    {
        if ($task->task_type !== TaskType::Activity) {
            return;
        }

        $execution = self::activityExecutionForTask($task);

        if (! $execution instanceof ActivityExecution) {
            return;
        }

        $attempt = ActivityAttemptNormalizer::ensureCurrentAttempt($execution, $task);

        if (! $attempt instanceof ActivityAttempt) {
            return;
        }

        self::closeActivityAttempt($attempt->id, ActivityAttemptStatus::Expired);
    }

    private static function closeTerminalActivityAttempt(WorkflowTask $task, WorkflowRun $run): void
    {
        if ($task->task_type !== TaskType::Activity || ! in_array($run->status, [
            RunStatus::Cancelled,
            RunStatus::Terminated,
        ], true)) {
            return;
        }

        $execution = self::activityExecutionForTask($task);

        if (! $execution instanceof ActivityExecution) {
            return;
        }

        $attempt = ActivityAttemptNormalizer::ensureCurrentAttempt($execution, $task);

        if (! $attempt instanceof ActivityAttempt) {
            return;
        }

        self::closeActivityAttempt($attempt->id, ActivityAttemptStatus::Cancelled);
    }

    private static function activityExecutionForTask(WorkflowTask $task): ?ActivityExecution
    {
        $executionId = $task->payload['activity_execution_id'] ?? null;

        if (! is_string($executionId)) {
            return null;
        }

        /** @var ActivityExecution|null $execution */
        $execution = ActivityExecution::query()
            ->lockForUpdate()
            ->find($executionId);

        return $execution;
    }

    private static function closeActivityAttempt(string $attemptId, ActivityAttemptStatus $status): void
    {
        /** @var ActivityAttempt|null $attempt */
        $attempt = ActivityAttempt::query()
            ->lockForUpdate()
            ->find($attemptId);

        if (! $attempt instanceof ActivityAttempt || $attempt->status !== ActivityAttemptStatus::Running) {
            return;
        }

        $attempt->forceFill([
            'status' => $status,
            'lease_expires_at' => null,
            'closed_at' => $attempt->closed_at ?? now(),
        ])->save();
    }
}
