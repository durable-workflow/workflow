<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;

final class DefaultActivityTaskBridge implements ActivityTaskBridge
{
    public function poll(?string $connection, ?string $queue, int $limit = 1, ?string $compatibility = null, ?string $namespace = null): array
    {
        $query = ConfiguredV2Models::query('task_model', WorkflowTask::class)
            ->where('task_type', TaskType::Activity->value)
            ->where('status', TaskStatus::Ready->value)
            ->where(static function ($q) {
                // Use a 1-second ceiling on the availability cutoff so that tasks created
                // in the same request tick are reliably surfaced across all backends,
                // including SQLite where timestamp precision can vary.
                $availabilityCutoff = now()->addSecond();
                $q->whereNull('available_at')
                    ->orWhere('available_at', '<=', $availabilityCutoff);
            })
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, 100)));

        if ($connection !== null) {
            $query->where('connection', $connection);
        }

        if ($queue !== null) {
            $query->where('queue', $queue);
        }

        if ($compatibility !== null) {
            $query->where('compatibility', $compatibility);
        }

        if ($namespace !== null) {
            $query->where('namespace', $namespace);
        }

        $tasks = $query->get();

        return $tasks->map(static function (WorkflowTask $task) {
            /** @var WorkflowRun|null $run */
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->find($task->workflow_run_id);

            $executionId = $task->payload['activity_execution_id'] ?? null;

            /** @var ActivityExecution|null $execution */
            $execution = is_string($executionId)
                ? ConfiguredV2Models::query('activity_execution_model', ActivityExecution::class)->find($executionId)
                : null;

            return [
                'task_id' => $task->id,
                'workflow_run_id' => $task->workflow_run_id,
                'workflow_instance_id' => $run?->workflow_instance_id ?? '',
                'activity_execution_id' => $execution?->id,
                'activity_type' => self::nonEmptyString($execution?->activity_type),
                'activity_class' => self::nonEmptyString($execution?->activity_class),
                'connection' => self::nonEmptyString($task->connection),
                'queue' => self::nonEmptyString($task->queue),
                'compatibility' => self::nonEmptyString($task->compatibility),
                'available_at' => $task->available_at?->toJSON(),
            ];
        })->values()
            ->all();
    }

    public function claimStatus(string $taskId, ?string $leaseOwner = null): array
    {
        $result = ActivityTaskClaimer::claimDetailed($taskId, $leaseOwner, true);
        $claim = $result['claim'];

        if (! $claim instanceof ActivityTaskClaim) {
            $reason = ActivityWorkerBridgeReason::claim($result['reason']);

            return [
                'claimed' => false,
                'task_id' => $taskId,
                'workflow_instance_id' => null,
                'workflow_run_id' => null,
                'activity_execution_id' => null,
                'activity_attempt_id' => null,
                'attempt_number' => null,
                'activity_type' => null,
                'activity_class' => null,
                'idempotency_key' => null,
                'payload_codec' => null,
                'arguments' => null,
                'retry_policy' => null,
                'connection' => null,
                'queue' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'reason' => $reason,
                'reason_detail' => ActivityWorkerBridgeReason::claimDetail($result['reason']),
                'retry_after_seconds' => $result['retry_after_seconds'],
                'backend_error' => $result['backend_error'],
                'compatibility_reason' => $result['compatibility_reason'],
            ];
        }

        $task = $claim->task;
        $run = $claim->run;
        $execution = $claim->execution;

        return [
            'claimed' => true,
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
            'reason' => null,
            'reason_detail' => null,
            'retry_after_seconds' => null,
            'backend_error' => null,
            'compatibility_reason' => null,
        ];
    }

    public function claim(string $taskId, ?string $leaseOwner = null): ?array
    {
        $claim = $this->claimStatus($taskId, $leaseOwner);

        if ($claim['claimed'] !== true) {
            return null;
        }

        return [
            'task_id' => $claim['task_id'],
            'workflow_instance_id' => $claim['workflow_instance_id'],
            'workflow_run_id' => $claim['workflow_run_id'],
            'activity_execution_id' => $claim['activity_execution_id'],
            'activity_attempt_id' => $claim['activity_attempt_id'],
            'attempt_number' => $claim['attempt_number'],
            'activity_type' => $claim['activity_type'],
            'activity_class' => $claim['activity_class'],
            'idempotency_key' => $claim['idempotency_key'],
            'payload_codec' => $claim['payload_codec'],
            'arguments' => $claim['arguments'],
            'retry_policy' => $claim['retry_policy'],
            'connection' => $claim['connection'],
            'queue' => $claim['queue'],
            'lease_owner' => $claim['lease_owner'],
            'lease_expires_at' => $claim['lease_expires_at'],
        ];
    }

    public function complete(string $attemptId, mixed $result, ?string $codec = null): array
    {
        $outcome = $this->dispatchingOutcome(ActivityOutcomeRecorder::recordForAttempt($attemptId, $result, null, $codec));

        return [
            'recorded' => $outcome['recorded'],
            'task_id' => $attemptId,
            'reason' => $outcome['reason'],
            'next_task_id' => $outcome['next_task_id'],
        ];
    }

    public function fail(string $attemptId, Throwable|array|string $failure): array
    {
        $outcome = $this->dispatchingOutcome(
            ActivityOutcomeRecorder::recordForAttempt($attemptId, null, self::throwable($failure))
        );

        return [
            'recorded' => $outcome['recorded'],
            'task_id' => $attemptId,
            'reason' => $outcome['reason'],
            'next_task_id' => $outcome['next_task_id'],
        ];
    }

    public function status(string $attemptId): array
    {
        return $this->observeAttempt($attemptId, false);
    }

    public function heartbeat(string $attemptId, array $progress = []): array
    {
        return $this->observeAttempt($attemptId, true, HeartbeatProgress::normalizeForWrite($progress));
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
     *     last_heartbeat_at: string|null,
     * }
     */
    private function observeAttempt(string $attemptId, bool $renewLease, ?array $progress = null): array
    {
        return DB::transaction(static function () use ($attemptId, $renewLease, $progress): array {
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

            $payload = [
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
            ];

            if ($progress !== null) {
                $payload['progress'] = $progress;
            }

            WorkflowHistoryEvent::record($run, HistoryEventType::ActivityHeartbeatRecorded, $payload, $task);

            return self::attemptStatus($attemptId, $attempt, $execution, $run, $task, true, false, null, true);
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
            if ($run instanceof WorkflowRun && $run->status === RunStatus::Cancelled) {
                return [false, true, 'run_cancelled'];
            }

            if ($run instanceof WorkflowRun && $run->status === RunStatus::Terminated) {
                return [false, true, 'run_terminated'];
            }

            return [false, $attempt->status === ActivityAttemptStatus::Cancelled, 'attempt_closed'];
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

        ActivityCancellation::record($run, $execution, $task);

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
     *     last_heartbeat_at: string|null,
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
            'workflow_run_id' => self::nonEmptyString(
                $run?->id ?? $execution?->workflow_run_id ?? $attempt?->workflow_run_id
            ),
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
