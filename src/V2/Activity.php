<?php

declare(strict_types=1);

namespace Workflow\V2;

use Illuminate\Container\Container;
use LogicException;
use Workflow\Traits\ResolvesMethodDependencies;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\ActivityHeartbeatResumeUnavailable;
use Workflow\V2\Exceptions\ActivityLeaseExpiredException;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityAttemptAuthority;
use Workflow\V2\Support\ActivityAttemptNormalizer;
use Workflow\V2\Support\ActivityHeartbeatHistory;
use Workflow\V2\Support\ActivityLease;
use Workflow\V2\Support\ActivityRowLockOrder;
use Workflow\V2\Support\ActivityTaskClaim;
use Workflow\V2\Support\ActivityTimeoutEnforcer;
use Workflow\V2\Support\HeartbeatProgress;
use Workflow\V2\Support\LocalActivityRuntime;
use Workflow\V2\Support\SqliteTransaction;
use Workflow\V2\Support\StructuralLimits;

abstract class Activity
{
    use ResolvesMethodDependencies;

    public ?string $connection = null;

    public ?string $queue = null;

    public int $tries = 1;

    public ActivityExecution $execution;

    public WorkflowRun $run;

    public ?string $taskId;

    private Container $container;

    private ?ActivityTaskClaim $heartbeatClaim = null;

    /**
     * Attach engine-owned context after Laravel constructs the activity.
     *
     * @internal
     */
    final public function bindRuntime(
        ActivityExecution $execution,
        WorkflowRun $run,
        ?string $taskId = null,
        ?ActivityTaskClaim $claim = null,
    ): void {
        if (isset($this->execution) || isset($this->run)) {
            throw new \LogicException(sprintf(
                'Activity runtime context is already bound for [%s]. Configure activity classes as transient Laravel services.',
                static::class,
            ));
        }

        $this->execution = $execution;
        $this->run = $run;
        $this->taskId = $taskId;
        $this->heartbeatClaim = $claim;
        $this->container = Container::getInstance();
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

    /**
     * @param array<string, mixed> $progress
     */
    public function heartbeat(array $progress = []): void
    {
        $this->writeHeartbeat(HeartbeatProgress::normalizeForWrite($progress));
    }

    /**
     * The checkpoint contract version for a claimed embedded activity, or null when unavailable.
     */
    public function heartbeatResumeVersion(): ?int
    {
        return $this->heartbeatClaim?->supportsHeartbeatResume() === true
            ? ActivityHeartbeatCheckpoint::VERSION : null;
    }

    /**
     * Read the immutable checkpoint received with this attempt. A supported
     * attempt without a prior checkpoint returns null.
     */
    public function lastHeartbeatCheckpoint(): ?ActivityHeartbeatCheckpoint
    {
        return $this->checkpointClaim()
            ->receivedHeartbeatCheckpoint();
    }

    /**
     * Renew the heartbeat and durably checkpoint application details.
     * A successful return acknowledges the storage commit.
     *
     * @param array<array-key, mixed> $details JSON-compatible values.
     */
    public function heartbeatCheckpoint(array $details): void
    {
        $this->checkpointClaim();
        $encoded = json_encode($details, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        StructuralLimits::guardPayloadSize($encoded);
        $connection = $this->execution->getConnection();
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new LogicException(
                'An activity checkpoint must commit outside an existing workflow storage transaction.'
            );
        }

        if (! $this->writeHeartbeat(null, $encoded)) {
            throw new ActivityLeaseExpiredException('The activity attempt no longer owns its heartbeat checkpoint.');
        }
    }

    private function checkpointClaim(): ActivityTaskClaim
    {
        if ($this->heartbeatClaim?->supportsHeartbeatResume() !== true) {
            throw new ActivityHeartbeatResumeUnavailable(
                'Activity heartbeat checkpoint recovery is unavailable in this context.'
            );
        }

        return $this->heartbeatClaim;
    }

    /**
     * @param array<string, mixed>|null $normalizedProgress
     */
    private function writeHeartbeat(?array $normalizedProgress, ?string $checkpoint = null): bool
    {
        return SqliteTransaction::run(function () use ($normalizedProgress, $checkpoint): bool {
            $lockedRows = ActivityRowLockOrder::lockForExecution($this->execution->id);
            $execution = $lockedRows['execution'];

            if (! $execution instanceof ActivityExecution) {
                ActivityExecution::query()->findOrFail($this->execution->id);

                return false;
            }

            if ($execution->status !== ActivityStatus::Running) {
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
            $task = $this->taskId === null
                ? null
                : WorkflowTask::query()
                    ->lockForUpdate()
                    ->find($this->taskId);

            if (! $this->ownsHeartbeatAttempt($execution, $task)) {
                return false;
            }

            $expired = $task instanceof WorkflowTask
                ? ActivityAttemptAuthority::expiredReason($task, $execution, now(), $run) !== null
                : ActivityTimeoutEnforcer::hasExpiredDeadline($execution, now());
            if ($expired) {
                return false;
            }

            $attempt = $checkpoint === null
                ? ActivityAttemptNormalizer::ensureCurrentAttempt($execution, $task)
                : $lockedRows['attempt'];
            if (! $attempt instanceof ActivityAttempt || ! $this->ownsNormalizedAttempt($execution, $attempt, $task)) {
                return false;
            }

            if ($checkpoint !== null && ! $this->ownsCheckpointClaim($run, $execution, $attempt, $task)) {
                return false;
            }

            $heartbeatAt = now();
            $leaseExpiresAt = ActivityLease::expiresAt();

            $retryPolicy = is_array($execution->retry_policy) ? $execution->retry_policy : [];
            $heartbeatTimeout = is_int($retryPolicy['heartbeat_timeout'] ?? null)
                ? $retryPolicy['heartbeat_timeout']
                : null;
            $heartbeatDeadlineAt = $heartbeatTimeout !== null
                ? $heartbeatAt->copy()
                    ->addSeconds($heartbeatTimeout)
                : null;

            $executionUpdate = [
                'last_heartbeat_at' => $heartbeatAt,
            ];

            if ($heartbeatDeadlineAt !== null) {
                $executionUpdate['heartbeat_deadline_at'] = $heartbeatDeadlineAt;
            }

            if ($checkpoint !== null) {
                $executionUpdate['last_heartbeat_checkpoint'] = json_encode([
                    'version' => ActivityHeartbeatCheckpoint::VERSION,
                    'acknowledged_at' => $heartbeatAt->toJSON(),
                    'details_json' => $checkpoint,
                ], JSON_THROW_ON_ERROR);
            }

            $executionSaved = $execution->forceFill($executionUpdate)
                ->save();
            if ($checkpoint !== null && ! $executionSaved) {
                throw new LogicException('The activity checkpoint update was refused by the storage model.');
            }

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
                $this->recordHeartbeat($run, $execution, $attempt, null, $heartbeatAt, null, $normalizedProgress);

                return true;
            }

            if ($this->ownsLocalWorkflowTask($execution, $attempt, $task)) {
                $leaseExpiresAt = LocalActivityRuntime::renewWorkflowTask($task, run: $run);

                if ($attempt->status === ActivityAttemptStatus::Running) {
                    $attempt->forceFill([
                        'last_heartbeat_at' => $heartbeatAt,
                        'lease_expires_at' => $leaseExpiresAt,
                    ])->save();
                }

                $this->recordHeartbeat(
                    $run,
                    $execution,
                    $attempt,
                    $task,
                    $heartbeatAt,
                    $leaseExpiresAt,
                    $normalizedProgress
                );

                return true;
            }

            if (
                $task->workflow_run_id !== $execution->workflow_run_id
                || $task->status !== TaskStatus::Leased
                || ($task->payload['activity_execution_id'] ?? null) !== $execution->id
                || $task->attempt_count !== $this->attemptCount()
            ) {
                return false;
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

            $this->recordHeartbeat(
                $run,
                $execution,
                $attempt,
                $task,
                $heartbeatAt,
                $leaseExpiresAt,
                $normalizedProgress
            );

            return true;
        }, connection: $checkpoint === null ? null : $this->execution->getConnection());
    }

    private function ownsCheckpointClaim(
        WorkflowRun $run,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        ?WorkflowTask $task,
    ): bool {
        $identity = $this->checkpointClaim()
            ->identity();

        return $task instanceof WorkflowTask
            && $task->task_type === TaskType::Activity
            && $task->id === $identity['workflow_task_id']
            && $run->id === $identity['workflow_run_id']
            && $execution->id === $identity['activity_execution_id']
            && array_key_exists('last_heartbeat_checkpoint', $execution->getAttributes())
            && $attempt->id === $identity['activity_attempt_id']
            && $attempt->workflow_run_id === $run->id
            && $attempt->lease_owner === $identity['lease_owner']
            && $task->lease_owner === $identity['lease_owner'];
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

        if (LocalActivityRuntime::isExecution($execution)) {
            return $task->task_type === TaskType::Workflow
                && $task->workflow_run_id === $execution->workflow_run_id
                && $task->status === TaskStatus::Leased;
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

        if (LocalActivityRuntime::isExecution($execution)) {
            return $task->task_type === TaskType::Workflow
                && $task->workflow_run_id === $execution->workflow_run_id
                && $attempt->workflow_task_id === $task->id
                && $attempt->attempt_number === $execution->attempt_count;
        }

        return $attempt->workflow_task_id === $task->id
            && $attempt->attempt_number === $task->attempt_count;
    }

    private function ownsLocalWorkflowTask(
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        WorkflowTask $task,
    ): bool {
        return LocalActivityRuntime::isExecution($execution)
            && $task->task_type === TaskType::Workflow
            && $task->workflow_run_id === $execution->workflow_run_id
            && $task->status === TaskStatus::Leased
            && $attempt->workflow_task_id === $task->id
            && $attempt->attempt_number === $execution->attempt_count;
    }

    /**
     * @param array<string, mixed>|null $progress
     */
    private function recordHeartbeat(
        WorkflowRun $run,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        ?WorkflowTask $task,
        mixed $heartbeatAt,
        mixed $leaseExpiresAt,
        ?array $progress,
    ): void {
        // The attempt row always keeps the latest progress; history records
        // it only when it changed and the record interval has passed (#870).
        if (
            ! $heartbeatAt instanceof \Carbon\CarbonInterface
            || ! ActivityHeartbeatHistory::observe($attempt, $execution, $progress, $heartbeatAt)
        ) {
            return;
        }

        ActivityHeartbeatHistory::record($run, $execution, $attempt, $task, $heartbeatAt, $leaseExpiresAt);
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
