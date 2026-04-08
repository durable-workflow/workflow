<?php

declare(strict_types=1);

namespace Workflow\V2;

use Illuminate\Support\Facades\DB;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\Traits\ResolvesMethodDependencies;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityLease;

abstract class Activity
{
    use ResolvesMethodDependencies;

    public ?string $connection = null;

    public ?string $queue = null;

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

    public function heartbeat(): void
    {
        $attemptId = $this->attemptId();

        if ($attemptId === null) {
            return;
        }

        DB::transaction(function () use ($attemptId): void {
            /** @var ActivityExecution $execution */
            $execution = ActivityExecution::query()
                ->lockForUpdate()
                ->findOrFail($this->execution->id);

            if (
                $execution->status !== ActivityStatus::Running
                || $execution->current_attempt_id !== $attemptId
            ) {
                return;
            }

            $heartbeatAt = now();
            $leaseExpiresAt = ActivityLease::expiresAt();

            $execution->forceFill([
                'last_heartbeat_at' => $heartbeatAt,
            ])->save();

            /** @var ActivityAttempt|null $attempt */
            $attempt = ActivityAttempt::query()
                ->lockForUpdate()
                ->find($attemptId);

            if (
                $attempt instanceof ActivityAttempt
                && $attempt->activity_execution_id === $execution->id
                && $attempt->status === ActivityAttemptStatus::Running
            ) {
                $attempt->forceFill([
                    'last_heartbeat_at' => $heartbeatAt,
                ])->save();
            }

            $this->execution->forceFill([
                'last_heartbeat_at' => $execution->last_heartbeat_at,
            ]);

            if ($this->taskId === null) {
                return;
            }

            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->lockForUpdate()
                ->find($this->taskId);

            if (
                ! $task instanceof WorkflowTask
                || $task->workflow_run_id !== $execution->workflow_run_id
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
        });
    }
}
