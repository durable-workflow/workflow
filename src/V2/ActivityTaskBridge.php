<?php

declare(strict_types=1);

namespace Workflow\V2;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityLease;
use Workflow\V2\Support\ActivityOutcomeRecorder;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\ActivityTaskClaimer;
use Workflow\V2\Support\FailureFactory;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\TaskDispatcher;

final class ActivityTaskBridge
{
    /**
     * @return array{
     *     task_id: string,
     *     workflow_instance_id: string,
     *     workflow_run_id: string,
     *     activity_execution_id: string,
     *     activity_attempt_id: string,
     *     attempt_number: int,
     *     activity_type: string|null,
     *     activity_class: string|null,
     *     idempotency_key: string,
     *     payload_codec: string,
     *     arguments: string|null,
     *     retry_policy: array<string, mixed>|null,
     *     connection: string|null,
     *     queue: string|null,
     *     lease_owner: string|null,
     *     lease_expires_at: string|null
     * }|null
     */
    public static function claim(string $taskId, ?string $leaseOwner = null): ?array
    {
        [$claim] = ActivityTaskClaimer::claim($taskId, $leaseOwner);

        if ($claim === null) {
            return null;
        }

        $task = $claim->task;
        $run = $claim->run;
        $execution = $claim->execution;

        return [
            'task_id' => $task->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $claim->attemptId(),
            'attempt_number' => $claim->attemptNumber(),
            'activity_type' => self::nonEmptyString($execution->activity_type),
            'activity_class' => self::nonEmptyString($execution->activity_class),
            'idempotency_key' => $execution->id,
            'payload_codec' => $run->payload_codec ?? config('workflows.serializer'),
            'arguments' => self::nonEmptyString($execution->arguments),
            'retry_policy' => is_array($execution->retry_policy) ? $execution->retry_policy : null,
            'connection' => self::nonEmptyString($execution->connection),
            'queue' => self::nonEmptyString($execution->queue),
            'lease_owner' => self::nonEmptyString($task->lease_owner),
            'lease_expires_at' => $task->lease_expires_at?->toJSON(),
        ];
    }

    /**
     * @return array{recorded: bool, reason: string|null, next_task_id: string|null}
     */
    public static function complete(string $attemptId, mixed $result): array
    {
        return self::dispatchingOutcome(ActivityOutcomeRecorder::recordForAttempt($attemptId, $result, null));
    }

    /**
     * @param Throwable|array<string, mixed>|string $failure
     * @return array{recorded: bool, reason: string|null, next_task_id: string|null}
     */
    public static function fail(string $attemptId, Throwable|array|string $failure): array
    {
        return self::dispatchingOutcome(
            ActivityOutcomeRecorder::recordForAttempt($attemptId, null, self::throwable($failure))
        );
    }

    /**
     * @return array{
     *     can_continue: bool,
     *     cancel_requested: bool,
     *     reason: string|null,
     *     heartbeat_recorded: bool,
     *     workflow_instance_id: string|null,
     *     workflow_run_id: string|null,
     *     workflow_task_id: string|null,
     *     activity_execution_id: string|null,
     *     activity_attempt_id: string,
     *     attempt_number: int|null,
     *     run_status: string|null,
     *     activity_status: string|null,
     *     attempt_status: string|null,
     *     task_status: string|null,
     *     lease_owner: string|null,
     *     lease_expires_at: string|null,
     *     last_heartbeat_at: string|null
     * }
     */
    public static function status(string $attemptId): array
    {
        return self::observeAttempt($attemptId, false);
    }

    /**
     * @return array{
     *     can_continue: bool,
     *     cancel_requested: bool,
     *     reason: string|null,
     *     heartbeat_recorded: bool,
     *     workflow_instance_id: string|null,
     *     workflow_run_id: string|null,
     *     workflow_task_id: string|null,
     *     activity_execution_id: string|null,
     *     activity_attempt_id: string,
     *     attempt_number: int|null,
     *     run_status: string|null,
     *     activity_status: string|null,
     *     attempt_status: string|null,
     *     task_status: string|null,
     *     lease_owner: string|null,
     *     lease_expires_at: string|null,
     *     last_heartbeat_at: string|null
     * }
     */
    public static function heartbeatStatus(string $attemptId): array
    {
        return self::observeAttempt($attemptId, true);
    }

    public static function heartbeat(string $attemptId): bool
    {
        return self::heartbeatStatus($attemptId)['can_continue'];
    }

    /**
     * @return array{
     *     can_continue: bool,
     *     cancel_requested: bool,
     *     reason: string|null,
     *     heartbeat_recorded: bool,
     *     workflow_instance_id: string|null,
     *     workflow_run_id: string|null,
     *     workflow_task_id: string|null,
     *     activity_execution_id: string|null,
     *     activity_attempt_id: string,
     *     attempt_number: int|null,
     *     run_status: string|null,
     *     activity_status: string|null,
     *     attempt_status: string|null,
     *     task_status: string|null,
     *     lease_owner: string|null,
     *     lease_expires_at: string|null,
     *     last_heartbeat_at: string|null
     * }
     */
    private static function observeAttempt(string $attemptId, bool $renewLease): array
    {
        return DB::transaction(static function () use ($attemptId, $renewLease): array {
            /** @var ActivityAttempt|null $attempt */
            $attempt = ActivityAttempt::query()
                ->lockForUpdate()
                ->find($attemptId);

            if (! $attempt instanceof ActivityAttempt) {
                return self::attemptStatus(
                    $attemptId,
                    null,
                    null,
                    null,
                    null,
                    false,
                    false,
                    'attempt_not_found',
                    false,
                );
            }

            /** @var ActivityExecution|null $execution */
            $execution = ActivityExecution::query()
                ->lockForUpdate()
                ->find($attempt->activity_execution_id);

            $run = null;

            if ($execution instanceof ActivityExecution) {
                /** @var WorkflowRun|null $run */
                $run = WorkflowRun::query()
                    ->lockForUpdate()
                    ->find($execution->workflow_run_id);
            }

            $task = null;

            if (is_string($attempt->workflow_task_id) && $attempt->workflow_task_id !== '') {
                /** @var WorkflowTask|null $task */
                $task = WorkflowTask::query()
                    ->lockForUpdate()
                    ->find($attempt->workflow_task_id);
            }

            [$canContinue, $cancelRequested, $reason] = self::attemptContinuationState(
                $attempt,
                $execution,
                $run,
                $task,
            );

            if (
                ! $canContinue
                && $cancelRequested
                && $renewLease
                && $run instanceof WorkflowRun
                && $execution instanceof ActivityExecution
            ) {
                self::closeCancelledAttempt($attempt, $execution, $run, $task);

                return self::attemptStatus(
                    $attemptId,
                    $attempt->fresh(),
                    $execution->fresh(),
                    $run,
                    $task?->fresh(),
                    false,
                    true,
                    $reason,
                    false,
                );
            }

            if (! $canContinue) {
                return self::attemptStatus(
                    $attemptId,
                    $attempt,
                    $execution,
                    $run,
                    $task,
                    false,
                    $cancelRequested,
                    $reason,
                    false,
                );
            }

            /** @var ActivityExecution $execution */
            /** @var WorkflowRun $run */
            /** @var WorkflowTask $task */
            if (! $renewLease) {
                return self::attemptStatus(
                    $attemptId,
                    $attempt,
                    $execution,
                    $run,
                    $task,
                    true,
                    false,
                    null,
                    false,
                );
            }

            $heartbeatAt = now();
            $leaseExpiresAt = ActivityLease::expiresAt();

            $execution->forceFill([
                'last_heartbeat_at' => $heartbeatAt,
            ])->save();

            $attempt->forceFill([
                'last_heartbeat_at' => $heartbeatAt,
                'lease_expires_at' => $leaseExpiresAt,
            ])->save();

            $task->forceFill([
                'lease_expires_at' => $leaseExpiresAt,
            ])->save();

            WorkflowRunSummary::query()
                ->whereKey($execution->workflow_run_id)
                ->where('next_task_id', $task->id)
                ->update([
                    'next_task_lease_expires_at' => $leaseExpiresAt,
                ]);

            WorkflowHistoryEvent::record($run, HistoryEventType::ActivityHeartbeatRecorded, [
                'activity_execution_id' => $execution->id,
                'activity_attempt_id' => $attempt->id,
                'activity_class' => $execution->activity_class,
                'activity_type' => $execution->activity_type,
                'sequence' => $execution->sequence,
                'attempt_number' => $attempt->attempt_number,
                'heartbeat_at' => $heartbeatAt->toJSON(),
                'lease_expires_at' => $leaseExpiresAt->toJSON(),
                'activity' => ActivitySnapshot::fromExecution($execution),
                'activity_attempt' => [
                    'id' => $attempt->id,
                    'attempt_number' => $attempt->attempt_number,
                    'status' => $attempt->status->value,
                    'task_id' => $attempt->workflow_task_id,
                    'lease_owner' => $attempt->lease_owner,
                    'last_heartbeat_at' => $heartbeatAt->toJSON(),
                    'lease_expires_at' => $leaseExpiresAt->toJSON(),
                    'started_at' => $attempt->started_at?->toJSON(),
                ],
            ], $task);

            return self::attemptStatus(
                $attemptId,
                $attempt,
                $execution,
                $run,
                $task,
                true,
                false,
                null,
                true,
            );
        });
    }

    /**
     * @return array{0: bool, 1: bool, 2: string|null}
     */
    private static function attemptContinuationState(
        ActivityAttempt $attempt,
        ?ActivityExecution $execution,
        ?WorkflowRun $run,
        ?WorkflowTask $task,
    ): array {
        if ($attempt->status !== ActivityAttemptStatus::Running) {
            return [
                false,
                $attempt->status === ActivityAttemptStatus::Cancelled,
                'attempt_closed',
            ];
        }

        if (! $execution instanceof ActivityExecution) {
            return [false, false, 'activity_execution_missing'];
        }

        if (! $run instanceof WorkflowRun) {
            return [false, false, 'workflow_run_missing'];
        }

        if ($run->status === RunStatus::Cancelled) {
            return [false, true, 'run_cancelled'];
        }

        if ($run->status === RunStatus::Terminated) {
            return [false, true, 'run_terminated'];
        }

        if ($execution->status === ActivityStatus::Cancelled) {
            return [false, true, 'activity_cancelled'];
        }

        if ($task instanceof WorkflowTask && $task->status === TaskStatus::Cancelled) {
            return [false, true, 'task_cancelled'];
        }

        if ($run->status->isTerminal()) {
            return [false, false, 'run_closed'];
        }

        if ($execution->status !== ActivityStatus::Running) {
            return [false, false, 'activity_not_running'];
        }

        if (
            $execution->current_attempt_id !== $attempt->id
            || $execution->attempt_count !== $attempt->attempt_number
        ) {
            return [false, false, 'stale_attempt'];
        }

        if (! $task instanceof WorkflowTask) {
            return [false, false, 'task_not_found'];
        }

        if (
            $task->workflow_run_id !== $execution->workflow_run_id
            || ($task->payload['activity_execution_id'] ?? null) !== $execution->id
            || $task->attempt_count !== $attempt->attempt_number
        ) {
            return [false, false, 'stale_task'];
        }

        if ($task->status !== TaskStatus::Leased) {
            return [false, false, 'task_not_leased'];
        }

        return [true, false, null];
    }

    private static function closeCancelledAttempt(
        ActivityAttempt $attempt,
        ActivityExecution $execution,
        WorkflowRun $run,
        ?WorkflowTask $task,
    ): void {
        $closedAt = now();

        if (in_array($execution->status, [ActivityStatus::Pending, ActivityStatus::Running], true)) {
            $execution->forceFill([
                'status' => ActivityStatus::Cancelled,
                'closed_at' => $execution->closed_at ?? $closedAt,
            ])->save();
        }

        if ($attempt->status === ActivityAttemptStatus::Running) {
            $attempt->forceFill([
                'status' => ActivityAttemptStatus::Cancelled,
                'lease_expires_at' => null,
                'closed_at' => $attempt->closed_at ?? $closedAt,
            ])->save();
        }

        if ($task instanceof WorkflowTask && $task->status === TaskStatus::Leased) {
            $task->forceFill([
                'status' => TaskStatus::Cancelled,
                'lease_expires_at' => null,
            ])->save();
        }

        RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));
    }

    /**
     * @return array{
     *     can_continue: bool,
     *     cancel_requested: bool,
     *     reason: string|null,
     *     heartbeat_recorded: bool,
     *     workflow_instance_id: string|null,
     *     workflow_run_id: string|null,
     *     workflow_task_id: string|null,
     *     activity_execution_id: string|null,
     *     activity_attempt_id: string,
     *     attempt_number: int|null,
     *     run_status: string|null,
     *     activity_status: string|null,
     *     attempt_status: string|null,
     *     task_status: string|null,
     *     lease_owner: string|null,
     *     lease_expires_at: string|null,
     *     last_heartbeat_at: string|null
     * }
     */
    private static function attemptStatus(
        string $attemptId,
        ?ActivityAttempt $attempt,
        ?ActivityExecution $execution,
        ?WorkflowRun $run,
        ?WorkflowTask $task,
        bool $canContinue,
        bool $cancelRequested,
        ?string $reason,
        bool $heartbeatRecorded,
    ): array {
        return [
            'can_continue' => $canContinue,
            'cancel_requested' => $cancelRequested,
            'reason' => $reason,
            'heartbeat_recorded' => $heartbeatRecorded,
            'workflow_instance_id' => self::nonEmptyString($run?->workflow_instance_id),
            'workflow_run_id' => self::nonEmptyString($run?->id ?? $execution?->workflow_run_id ?? $attempt?->workflow_run_id),
            'workflow_task_id' => self::nonEmptyString($task?->id ?? $attempt?->workflow_task_id),
            'activity_execution_id' => self::nonEmptyString($execution?->id ?? $attempt?->activity_execution_id),
            'activity_attempt_id' => $attemptId,
            'attempt_number' => is_int($attempt?->attempt_number) ? $attempt->attempt_number : null,
            'run_status' => $run?->status?->value,
            'activity_status' => $execution?->status?->value,
            'attempt_status' => $attempt?->status?->value,
            'task_status' => $task?->status?->value,
            'lease_owner' => self::nonEmptyString($attempt?->lease_owner ?? $task?->lease_owner),
            'lease_expires_at' => $attempt?->lease_expires_at?->toJSON() ?? $task?->lease_expires_at?->toJSON(),
            'last_heartbeat_at' => $attempt?->last_heartbeat_at?->toJSON() ?? $execution?->last_heartbeat_at?->toJSON(),
        ];
    }

    /**
     * @param array{recorded: bool, reason: string|null, next_task: WorkflowTask|null} $outcome
     * @return array{recorded: bool, reason: string|null, next_task_id: string|null}
     */
    private static function dispatchingOutcome(array $outcome): array
    {
        $nextTask = $outcome['next_task'];

        if ($nextTask instanceof WorkflowTask) {
            TaskDispatcher::dispatch($nextTask);
        }

        return [
            'recorded' => $outcome['recorded'],
            'reason' => $outcome['reason'],
            'next_task_id' => $nextTask?->id,
        ];
    }

    /**
     * @param Throwable|array<string, mixed>|string $failure
     */
    private static function throwable(Throwable|array|string $failure): Throwable
    {
        if ($failure instanceof Throwable) {
            return $failure;
        }

        if (is_string($failure)) {
            return new RuntimeException($failure);
        }

        $message = is_string($failure['message'] ?? null)
            ? $failure['message']
            : 'External activity failed';
        $code = is_int($failure['code'] ?? null) ? $failure['code'] : 0;
        $class = is_string($failure['class'] ?? null) ? $failure['class'] : RuntimeException::class;

        return FailureFactory::restore($failure, $class, $message, $code);
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
