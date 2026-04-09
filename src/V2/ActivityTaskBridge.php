<?php

declare(strict_types=1);

namespace Workflow\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityLease;
use Workflow\V2\Support\ActivityOutcomeRecorder;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\FailureFactory;
use Workflow\V2\Support\ParallelChildGroup;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\TaskBackendCapabilities;
use Workflow\V2\Support\TaskCompatibility;
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
        return DB::transaction(function () use ($taskId, $leaseOwner): ?array {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->lockForUpdate()
                ->find($taskId);

            if ($task === null || $task->task_type !== TaskType::Activity || $task->status !== TaskStatus::Ready) {
                return null;
            }

            if ($task->available_at !== null && $task->available_at->isFuture()) {
                return null;
            }

            $activityExecutionId = $task->payload['activity_execution_id'] ?? null;

            if (! is_string($activityExecutionId) || $activityExecutionId === '') {
                return null;
            }

            /** @var ActivityExecution $execution */
            $execution = ActivityExecution::query()
                ->lockForUpdate()
                ->findOrFail($activityExecutionId);

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()
                ->lockForUpdate()
                ->findOrFail($execution->workflow_run_id);

            TaskCompatibility::sync($task, $run);

            if (TaskBackendCapabilities::recordClaimFailureIfUnsupported($task) !== null) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return null;
            }

            if (! TaskCompatibility::supported($task, $run)) {
                RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return null;
            }

            $now = now();
            $attemptId = (string) Str::ulid();

            $task->forceFill([
                'status' => TaskStatus::Leased,
                'leased_at' => $now,
                'lease_owner' => self::nonEmptyString($leaseOwner) ?? $taskId,
                'lease_expires_at' => ActivityLease::expiresAt(),
                'attempt_count' => $task->attempt_count + 1,
                'last_claim_failed_at' => null,
                'last_claim_error' => null,
            ])->save();

            $attemptCount = $task->attempt_count;

            $execution->forceFill([
                'status' => ActivityStatus::Running,
                'attempt_count' => $attemptCount,
                'current_attempt_id' => $attemptId,
                'started_at' => $now,
                'last_heartbeat_at' => null,
            ])->save();

            ActivityAttempt::query()->create([
                'id' => $attemptId,
                'workflow_run_id' => $run->id,
                'activity_execution_id' => $execution->id,
                'workflow_task_id' => $task->id,
                'attempt_number' => $attemptCount,
                'status' => ActivityAttemptStatus::Running->value,
                'lease_owner' => $task->lease_owner,
                'started_at' => $execution->started_at ?? $now,
                'lease_expires_at' => $task->lease_expires_at,
            ]);

            $parallelMetadataPath = ParallelChildGroup::metadataPathForSequence(
                $run,
                (int) $execution->sequence,
            );
            $parallelMetadata = ParallelChildGroup::payloadForPath($parallelMetadataPath);

            WorkflowHistoryEvent::record($run, HistoryEventType::ActivityStarted, array_merge([
                'activity_execution_id' => $execution->id,
                'activity_attempt_id' => $attemptId,
                'activity_class' => $execution->activity_class,
                'activity_type' => $execution->activity_type,
                'sequence' => $execution->sequence,
                'attempt_number' => $attemptCount,
                'activity' => ActivitySnapshot::fromExecution($execution),
            ], $parallelMetadata ?? []), $task);

            RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return [
                'task_id' => $task->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_run_id' => $run->id,
                'activity_execution_id' => $execution->id,
                'activity_attempt_id' => $attemptId,
                'attempt_number' => $attemptCount,
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
        });
    }

    /**
     * @return array{recorded: bool, reason: string|null, next_task_id: string|null}
     */
    public static function complete(string $attemptId, mixed $result): array
    {
        return self::dispatchingOutcome(
            ActivityOutcomeRecorder::recordForAttempt($attemptId, $result, null)
        );
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

    public static function heartbeat(string $attemptId): bool
    {
        return DB::transaction(function () use ($attemptId): bool {
            /** @var ActivityAttempt|null $attempt */
            $attempt = ActivityAttempt::query()
                ->lockForUpdate()
                ->find($attemptId);

            if (! $attempt instanceof ActivityAttempt || $attempt->status !== ActivityAttemptStatus::Running) {
                return false;
            }

            /** @var ActivityExecution|null $execution */
            $execution = ActivityExecution::query()
                ->lockForUpdate()
                ->find($attempt->activity_execution_id);

            if (
                ! $execution instanceof ActivityExecution
                || $execution->status !== ActivityStatus::Running
                || $execution->current_attempt_id !== $attempt->id
                || $execution->attempt_count !== $attempt->attempt_number
            ) {
                return false;
            }

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()
                ->lockForUpdate()
                ->findOrFail($execution->workflow_run_id);

            if ($run->status->isTerminal()) {
                return false;
            }

            /** @var WorkflowTask|null $task */
            $task = is_string($attempt->workflow_task_id)
                ? WorkflowTask::query()
                    ->lockForUpdate()
                    ->find($attempt->workflow_task_id)
                : null;

            if (
                ! $task instanceof WorkflowTask
                || $task->workflow_run_id !== $execution->workflow_run_id
                || $task->status !== TaskStatus::Leased
                || ($task->payload['activity_execution_id'] ?? null) !== $execution->id
                || $task->attempt_count !== $attempt->attempt_number
            ) {
                return false;
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

            return true;
        });
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
