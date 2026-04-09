<?php

declare(strict_types=1);

namespace Workflow\V2;

use Illuminate\Support\Facades\DB;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\Traits\ResolvesMethodDependencies;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityAttemptNormalizer;
use Workflow\V2\Support\ActivityLease;
use Workflow\V2\Support\ActivitySnapshot;

abstract class Activity
{
    use ResolvesMethodDependencies;

    public ?string $connection = null;

    public ?string $queue = null;

    public int $tries = 1;

    final public function __construct(
        public readonly ActivityExecution $execution,
        public readonly WorkflowRun $run,
        public readonly ?string $taskId = null,
    ) {
    }

    public function workflowId(): string
    {
        return $this->run->workflow_instance_id;
    }

    public function runId(): string
    {
        return $this->run->id;
    }

    public function activityId(): string
    {
        return $this->execution->id;
    }

    public function attemptId(): ?string
    {
        return is_string($this->execution->current_attempt_id) && $this->execution->current_attempt_id !== ''
            ? $this->execution->current_attempt_id
            : null;
    }

    public function attemptCount(): int
    {
        $attemptCount = is_int($this->execution->attempt_count) ? $this->execution->attempt_count : 0;

        return $attemptCount > 0 ? $attemptCount : 1;
    }

    /**
     * @return int|list<int>
     */
    public function backoff(): int|array
    {
        return [1, 2, 5, 10, 15, 30, 60, 120];
    }

    public function heartbeat(): void
    {
        DB::transaction(function (): void {
            /** @var ActivityExecution $execution */
            $execution = ActivityExecution::query()
                ->lockForUpdate()
                ->findOrFail($this->execution->id);

            if ($execution->status !== ActivityStatus::Running) {
                return;
            }

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()
                ->lockForUpdate()
                ->findOrFail($execution->workflow_run_id);

            if ($run->status->isTerminal()) {
                return;
            }

            /** @var WorkflowTask|null $task */
            $task = $this->taskId === null
                ? null
                : WorkflowTask::query()
                    ->lockForUpdate()
                    ->find($this->taskId);

            if (! $this->ownsHeartbeatAttempt($execution, $task)) {
                return;
            }

            $attempt = ActivityAttemptNormalizer::ensureCurrentAttempt($execution, $task);

            if (! $attempt instanceof ActivityAttempt) {
                return;
            }

            if (! $this->ownsNormalizedAttempt($execution, $attempt, $task)) {
                return;
            }

            $heartbeatAt = now();
            $leaseExpiresAt = ActivityLease::expiresAt();

            $execution->forceFill([
                'last_heartbeat_at' => $heartbeatAt,
            ])->save();

            if (
                $attempt->activity_execution_id === $execution->id
                && $attempt->status === ActivityAttemptStatus::Running
            ) {
                $attempt->forceFill([
                    'last_heartbeat_at' => $heartbeatAt,
                ])->save();
            }

            $this->execution->forceFill([
                'current_attempt_id' => $attempt->id,
                'attempt_count' => $execution->attempt_count,
                'last_heartbeat_at' => $execution->last_heartbeat_at,
            ]);

            if (! $task instanceof WorkflowTask) {
                $this->recordHeartbeat($run, $execution, $attempt, null, $heartbeatAt, null);

                return;
            }

            if (
                $task->workflow_run_id !== $execution->workflow_run_id
                || $task->status !== TaskStatus::Leased
                || ($task->payload['activity_execution_id'] ?? null) !== $execution->id
                || $task->attempt_count !== $this->attemptCount()
            ) {
                return;
            }

            $task->forceFill([
                'lease_expires_at' => $leaseExpiresAt,
            ])->save();

            if (
                $attempt instanceof ActivityAttempt
                && $attempt->activity_execution_id === $execution->id
                && $attempt->status === ActivityAttemptStatus::Running
            ) {
                $attempt->forceFill([
                    'lease_expires_at' => $leaseExpiresAt,
                ])->save();
            }

            WorkflowRunSummary::query()
                ->whereKey($execution->workflow_run_id)
                ->where('next_task_id', $task->id)
                ->update([
                    'next_task_lease_expires_at' => $leaseExpiresAt,
                ]);

            $this->recordHeartbeat($run, $execution, $attempt, $task, $heartbeatAt, $leaseExpiresAt);
        });
    }

    private function ownsHeartbeatAttempt(ActivityExecution $execution, ?WorkflowTask $task): bool
    {
        $currentAttemptId = self::stringValue($execution->current_attempt_id);
        $originalAttemptId = $this->attemptId();

        if ($currentAttemptId !== null && $originalAttemptId !== null && $currentAttemptId !== $originalAttemptId) {
            return false;
        }

        if ($currentAttemptId !== null && $originalAttemptId === null) {
            return false;
        }

        if (! $task instanceof WorkflowTask) {
            return true;
        }

        return $task->workflow_run_id === $execution->workflow_run_id
            && $task->status === TaskStatus::Leased
            && ($task->payload['activity_execution_id'] ?? null) === $execution->id
            && $task->attempt_count === $this->attemptCount();
    }

    private function ownsNormalizedAttempt(
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        ?WorkflowTask $task,
    ): bool {
        $originalAttemptId = $this->attemptId();

        if ($originalAttemptId !== null && $attempt->id !== $originalAttemptId) {
            return false;
        }

        if (
            $attempt->activity_execution_id !== $execution->id
            || $attempt->status !== ActivityAttemptStatus::Running
        ) {
            return false;
        }

        if (! $task instanceof WorkflowTask) {
            return true;
        }

        return $attempt->workflow_task_id === $task->id
            && $attempt->attempt_number === $task->attempt_count;
    }

    private function recordHeartbeat(
        WorkflowRun $run,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        ?WorkflowTask $task,
        mixed $heartbeatAt,
        mixed $leaseExpiresAt,
    ): void {
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityHeartbeatRecorded, [
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attempt->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'attempt_number' => $attempt->attempt_number,
            'heartbeat_at' => self::timestamp($heartbeatAt),
            'lease_expires_at' => self::timestamp($leaseExpiresAt),
            'activity' => ActivitySnapshot::fromExecution($execution),
            'activity_attempt' => self::attemptSnapshot($attempt),
        ], $task);
    }

    /**
     * @return array<string, mixed>
     */
    private static function attemptSnapshot(ActivityAttempt $attempt): array
    {
        return array_filter([
            'id' => $attempt->id,
            'attempt_number' => $attempt->attempt_number,
            'status' => $attempt->status?->value,
            'task_id' => $attempt->workflow_task_id,
            'lease_owner' => $attempt->lease_owner,
            'started_at' => self::timestamp($attempt->started_at),
            'last_heartbeat_at' => self::timestamp($attempt->last_heartbeat_at),
            'lease_expires_at' => self::timestamp($attempt->lease_expires_at),
            'closed_at' => self::timestamp($attempt->closed_at),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function timestamp(mixed $value): ?string
    {
        return $value instanceof \Carbon\CarbonInterface
            ? $value->toJSON()
            : self::stringValue($value);
    }
}
