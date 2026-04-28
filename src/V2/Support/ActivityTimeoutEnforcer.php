<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * Sweeps activity executions whose schedule-to-start or start-to-close
 * deadline has passed and fails them with a deterministic timeout record.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public static method signatures on this class are covered by
 *      the workflow package's semver guarantee. See docs/api-stability.md.
 */
final class ActivityTimeoutEnforcer
{
    /**
     * Find activity executions that have exceeded their schedule-to-start
     * or start-to-close deadline. Returns execution IDs.
     *
     * @return list<string>
     */
    public static function expiredExecutionIds(
        int $limit = 100,
        ?string $connection = null,
        ?string $queue = null,
    ): array {
        $now = now();

        $query = ActivityExecution::query()
            ->whereIn('status', [ActivityStatus::Pending->value, ActivityStatus::Running->value])
            ->where(static function ($query) use ($now): void {
                $query->where(static function ($schedule) use ($now): void {
                    $schedule->where('status', ActivityStatus::Pending->value)
                        ->whereNotNull('schedule_deadline_at')
                        ->where('schedule_deadline_at', '<=', $now);
                })->orWhere(static function ($close) use ($now): void {
                    $close->where('status', ActivityStatus::Running->value)
                        ->whereNotNull('close_deadline_at')
                        ->where('close_deadline_at', '<=', $now);
                })->orWhere(static function ($scheduleToClose) use ($now): void {
                    $scheduleToClose->whereNotNull('schedule_to_close_deadline_at')
                        ->where('schedule_to_close_deadline_at', '<=', $now);
                })->orWhere(static function ($heartbeat) use ($now): void {
                    $heartbeat->where('status', ActivityStatus::Running->value)
                        ->whereNotNull('heartbeat_deadline_at')
                        ->where('heartbeat_deadline_at', '<=', $now);
                });
            })
            ->limit($limit);

        RequestedTaskScope::apply($query, $connection, $queue);

        return $query->pluck('id')
            ->all();
    }

    /**
     * Enforce the timeout for a single activity execution. If the activity
     * has exhausted retries, records a terminal ActivityTimedOut event and
     * wakes the parent workflow. Otherwise, schedules a retry.
     *
     * @return array{enforced: bool, reason: string|null, next_task: WorkflowTask|null}
     */
    public static function enforce(string $executionId): array
    {
        try {
            return DB::transaction(static function () use ($executionId): array {
                /** @var ActivityExecution|null $execution */
                $execution = ActivityExecution::query()
                    ->lockForUpdate()
                    ->find($executionId);

                if (! $execution instanceof ActivityExecution) {
                    return self::skipped('execution_not_found');
                }

                $now = now();
                $timeoutKind = self::resolveTimeoutKind($execution, $now);

                if ($timeoutKind === null) {
                    return self::skipped('no_deadline_expired');
                }

                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()
                    ->lockForUpdate()
                    ->findOrFail($execution->workflow_run_id);

                if ($run->status->isTerminal()) {
                    return self::skipped('run_already_terminal');
                }

                // Close the current attempt if one exists.
                $attempt = self::currentAttempt($execution);

                if ($attempt instanceof ActivityAttempt && $attempt->status === ActivityAttemptStatus::Running) {
                    $attempt->forceFill([
                        'status' => ActivityAttemptStatus::Failed,
                        'lease_expires_at' => null,
                        'closed_at' => $attempt->closed_at ?? $now,
                    ])->save();
                }

                // Cancel the existing activity task.
                $existingTask = self::openActivityTask($execution);

                if ($existingTask instanceof WorkflowTask) {
                    $existingTask->forceFill([
                        'status' => TaskStatus::Cancelled,
                        'lease_expires_at' => null,
                        'last_error' => null,
                    ])->save();
                }

                // Determine whether to retry.
                $attemptCount = max(1, (int) $execution->attempt_count);
                $maxAttempts = ActivityRetryPolicy::maxAttemptsFromSnapshot($execution);
                $canRetry = $attemptCount < $maxAttempts;

                // Schedule-to-close covers the entire execution lifecycle across
                // all retries — retrying would not help.
                if ($canRetry && $timeoutKind !== 'schedule_to_close') {
                    return self::scheduleRetry(
                        $run,
                        $execution,
                        $existingTask,
                        $attempt,
                        $timeoutKind,
                        $attemptCount,
                        $maxAttempts
                    );
                }

                return self::recordTerminalTimeout(
                    $run,
                    $execution,
                    $existingTask,
                    $attempt,
                    $timeoutKind,
                    $attemptCount
                );
            });
        } catch (Throwable $throwable) {
            report($throwable);

            return [
                'enforced' => false,
                'reason' => $throwable->getMessage(),
                'next_task' => null,
            ];
        }
    }

    private static function resolveTimeoutKind(ActivityExecution $execution, $now): ?string
    {
        // Schedule-to-close applies to both Pending and Running — it covers the
        // entire lifetime from scheduling through completion across all retries.
        if (
            $execution->schedule_to_close_deadline_at !== null
            && $now->gte($execution->schedule_to_close_deadline_at)
        ) {
            return 'schedule_to_close';
        }

        if (
            $execution->status === ActivityStatus::Pending
            && $execution->schedule_deadline_at !== null
            && $now->gte($execution->schedule_deadline_at)
        ) {
            return 'schedule_to_start';
        }

        if (
            $execution->status === ActivityStatus::Running
            && $execution->heartbeat_deadline_at !== null
            && $now->gte($execution->heartbeat_deadline_at)
        ) {
            return 'heartbeat';
        }

        if (
            $execution->status === ActivityStatus::Running
            && $execution->close_deadline_at !== null
            && $now->gte($execution->close_deadline_at)
        ) {
            return 'start_to_close';
        }

        return null;
    }

    /**
     * @return array{enforced: bool, reason: string|null, next_task: WorkflowTask|null}
     */
    private static function scheduleRetry(
        WorkflowRun $run,
        ActivityExecution $execution,
        ?WorkflowTask $existingTask,
        ?ActivityAttempt $attempt,
        string $timeoutKind,
        int $attemptCount,
        int $maxAttempts,
    ): array {
        $now = now();
        $message = self::timeoutMessage($execution, $timeoutKind);
        $backoffSeconds = ActivityRetryPolicy::backoffSecondsFromSnapshot($execution, $attemptCount);
        $retryAvailableAt = $now->copy()
            ->addSeconds($backoffSeconds);

        $scheduleToStartTimeout = self::scheduleToStartTimeoutFromPolicy($execution);
        $newScheduleDeadline = $scheduleToStartTimeout !== null
            ? $retryAvailableAt->copy()
                ->addSeconds($scheduleToStartTimeout)
            : null;

        $execution->forceFill([
            'status' => ActivityStatus::Pending,
            'last_heartbeat_at' => null,
            'close_deadline_at' => null,
            'heartbeat_deadline_at' => null,
            'schedule_deadline_at' => $newScheduleDeadline,
        ])->save();

        /** @var WorkflowTask $retryTask */
        $retryTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $retryAvailableAt,
            'payload' => [
                'activity_execution_id' => $execution->id,
                'retry_of_task_id' => $existingTask?->id,
                'retry_after_attempt_id' => $attempt?->id ?? $execution->current_attempt_id,
                'retry_after_attempt' => $attemptCount,
                'retry_backoff_seconds' => $backoffSeconds,
                'max_attempts' => $maxAttempts === PHP_INT_MAX ? null : $maxAttempts,
                'retry_policy' => $execution->retry_policy,
                'timeout_kind' => $timeoutKind,
            ],
            'connection' => $execution->connection,
            'queue' => $execution->queue,
            'compatibility' => $run->compatibility,
            'attempt_count' => $attemptCount,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityRetryScheduled, array_merge([
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attempt?->id ?? $execution->current_attempt_id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'retry_task_id' => $retryTask->id,
            'retry_of_task_id' => $existingTask?->id,
            'retry_available_at' => $retryAvailableAt->toJSON(),
            'retry_backoff_seconds' => $backoffSeconds,
            'retry_after_attempt_id' => $attempt?->id ?? $execution->current_attempt_id,
            'retry_after_attempt' => $attemptCount,
            'max_attempts' => $maxAttempts === PHP_INT_MAX ? null : $maxAttempts,
            'retry_policy' => $execution->retry_policy,
            'timeout_kind' => $timeoutKind,
            'message' => $message,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ]), $existingTask);

        self::projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

        return [
            'enforced' => true,
            'reason' => null,
            'next_task' => $retryTask,
        ];
    }

    /**
     * @return array{enforced: bool, reason: string|null, next_task: WorkflowTask|null}
     */
    private static function recordTerminalTimeout(
        WorkflowRun $run,
        ActivityExecution $execution,
        ?WorkflowTask $existingTask,
        ?ActivityAttempt $attempt,
        string $timeoutKind,
        int $attemptCount,
    ): array {
        $now = now();
        $message = self::timeoutMessage($execution, $timeoutKind);
        $exceptionClass = 'Workflow\\V2\\Exceptions\\ActivityTimeoutException';
        $failureCategory = FailureCategory::Timeout;

        $execution->forceFill([
            'status' => ActivityStatus::Failed,
            'closed_at' => $now,
        ])->save();

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()->create([
            'workflow_run_id' => $run->id,
            'source_kind' => 'activity_execution',
            'source_id' => $execution->id,
            'propagation_kind' => 'timeout',
            'failure_category' => $failureCategory->value,
            'handled' => false,
            'exception_class' => $exceptionClass,
            'message' => $message,
            'file' => '',
            'line' => 0,
            'trace_preview' => '',
        ]);

        $parallelMetadataPath = ParallelChildGroup::metadataPathForSequence($run, (int) $execution->sequence);
        $parallelMetadata = ParallelChildGroup::payloadForPath($parallelMetadataPath);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityTimedOut, array_merge([
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attempt?->id ?? $execution->current_attempt_id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'attempt_number' => $attemptCount,
            'failure_id' => $failure->id,
            'failure_category' => $failureCategory->value,
            'timeout_kind' => $timeoutKind,
            'message' => $message,
            'exception_class' => $exceptionClass,
            'schedule_deadline_at' => $execution->schedule_deadline_at?->toIso8601String(),
            'close_deadline_at' => $execution->close_deadline_at?->toIso8601String(),
            'schedule_to_close_deadline_at' => $execution->schedule_to_close_deadline_at?->toIso8601String(),
            'heartbeat_deadline_at' => $execution->heartbeat_deadline_at?->toIso8601String(),
            'activity' => ActivitySnapshot::fromExecution($execution),
        ], $parallelMetadata ?? []), $existingTask);

        LifecycleEventDispatcher::activityFailed(
            $run,
            (string) $execution->id,
            (string) ($execution->activity_type ?? $execution->activity_class),
            (string) $execution->activity_class,
            (int) $execution->sequence,
            $attemptCount,
            $exceptionClass,
            $message,
        );
        LifecycleEventDispatcher::failureRecorded(
            $run,
            (string) $failure->id,
            'activity_execution',
            (string) $execution->id,
            $exceptionClass,
            $message,
        );

        // Check if parallel group needs to wake the workflow.
        if (
            $parallelMetadataPath !== []
            && ! ParallelChildGroup::shouldWakeParentOnActivityClosure(
                $run,
                $parallelMetadataPath,
                ActivityStatus::Failed,
            )
        ) {
            self::projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return [
                'enforced' => true,
                'reason' => null,
                'next_task' => null,
            ];
        }

        /** @var WorkflowTask $resumeTask */
        $resumeTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $now,
            'payload' => [],
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
        ]);

        self::projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

        return [
            'enforced' => true,
            'reason' => null,
            'next_task' => $resumeTask,
        ];
    }

    private static function timeoutMessage(ActivityExecution $execution, string $timeoutKind): string
    {
        $label = $execution->activity_type ?? $execution->activity_class;

        return match ($timeoutKind) {
            'schedule_to_start' => sprintf(
                'Activity %s schedule-to-start deadline expired at %s.',
                $label,
                $execution->schedule_deadline_at->toIso8601String(),
            ),
            'schedule_to_close' => sprintf(
                'Activity %s schedule-to-close deadline expired at %s.',
                $label,
                $execution->schedule_to_close_deadline_at->toIso8601String(),
            ),
            'heartbeat' => sprintf(
                'Activity %s heartbeat deadline expired at %s (last heartbeat: %s).',
                $label,
                $execution->heartbeat_deadline_at->toIso8601String(),
                $execution->last_heartbeat_at?->toIso8601String() ?? 'never',
            ),
            default => sprintf(
                'Activity %s start-to-close deadline expired at %s.',
                $label,
                $execution->close_deadline_at->toIso8601String(),
            ),
        };
    }

    private static function currentAttempt(ActivityExecution $execution): ?ActivityAttempt
    {
        $attemptId = $execution->current_attempt_id;

        if (! is_string($attemptId) || $attemptId === '') {
            return null;
        }

        return ActivityAttempt::query()
            ->where('activity_execution_id', $execution->id)
            ->whereKey($attemptId)
            ->first();
    }

    private static function openActivityTask(ActivityExecution $execution): ?WorkflowTask
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $execution->workflow_run_id)
            ->where('task_type', TaskType::Activity->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->where('payload->activity_execution_id', $execution->id)
            ->first();
    }

    private static function scheduleToStartTimeoutFromPolicy(ActivityExecution $execution): ?int
    {
        $policy = is_array($execution->retry_policy) ? $execution->retry_policy : [];
        $value = $policy['schedule_to_start_timeout'] ?? null;

        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * @return array{enforced: bool, reason: string|null, next_task: WorkflowTask|null}
     */
    private static function skipped(string $reason): array
    {
        return [
            'enforced' => false,
            'reason' => $reason,
            'next_task' => null,
        ];
    }

    private static function projectRun(WorkflowRun $run): void
    {
        self::historyProjectionRole()->projectRun($run);
    }

    private static function historyProjectionRole(): HistoryProjectionRole
    {
        /** @var HistoryProjectionRole $role */
        $role = app(HistoryProjectionRole::class);

        return $role;
    }
}
