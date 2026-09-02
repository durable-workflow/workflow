<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Per-call activity configuration overrides.
 *
 * When provided, these values take precedence over the activity class
 * defaults and the parent workflow run's routing. This allows workflow
 * code to route individual activity calls to specific queues, override
 * retry policy, or set timeout constraints without changing the activity
 * class itself.
 */
final class ActivityOptions
{
    /**
     * @param int|null $maxAttempts Override the activity class $tries value.
     * @param list<int>|int|null $backoff Override the activity class backoff() return.
     * @param int|null $scheduleToCloseTimeout Maximum wall-clock seconds from scheduling to completion across all retries.
     * @param int|null $heartbeatTimeout Maximum seconds between heartbeats before the activity is considered unresponsive.
     * @param list<string> $nonRetryableErrorTypes Error type or class names that should bypass retries.
     */
    public readonly ?int $priority;

    public readonly ?string $fairnessKey;

    public readonly ?int $fairnessWeight;

    public function __construct(
        public readonly ?string $connection = null,
        public readonly ?string $queue = null,
        public readonly ?int $maxAttempts = null,
        public readonly array|int|null $backoff = null,
        public readonly ?int $startToCloseTimeout = null,
        public readonly ?int $scheduleToStartTimeout = null,
        public readonly ?int $scheduleToCloseTimeout = null,
        public readonly ?int $heartbeatTimeout = null,
        public readonly array $nonRetryableErrorTypes = [],
        public readonly ?WorkerSessionOptions $workerSession = null,
        ?int $priority = null,
        ?string $fairnessKey = null,
        ?int $fairnessWeight = null,
    ) {
        $this->priority = $priority === null ? null : TaskPriority::normalize($priority);
        $this->fairnessKey = TaskFairnessKey::normalize($fairnessKey);
        $this->fairnessWeight = $fairnessWeight === null ? null : TaskFairnessKey::normalizeWeight($fairnessWeight);
    }

    /**
     * @return array{
     *     connection: string|null,
     *     queue: string|null,
     *     max_attempts: int|null,
     *     backoff: list<int>|int|null,
     *     start_to_close_timeout: int|null,
     *     schedule_to_start_timeout: int|null,
     *     schedule_to_close_timeout: int|null,
     *     heartbeat_timeout: int|null,
     *     non_retryable_error_types: list<string>,
     *     worker_session: array<string, mixed>|null,
     *     priority: int|null,
     *     fairness_key: string|null,
     *     fairness_weight: int|null
     * }
     */
    public function toSnapshot(): array
    {
        return [
            'connection' => $this->connection,
            'queue' => $this->queue,
            'max_attempts' => $this->maxAttempts,
            'backoff' => $this->backoff,
            'start_to_close_timeout' => $this->startToCloseTimeout,
            'schedule_to_start_timeout' => $this->scheduleToStartTimeout,
            'schedule_to_close_timeout' => $this->scheduleToCloseTimeout,
            'heartbeat_timeout' => $this->heartbeatTimeout,
            'non_retryable_error_types' => $this->nonRetryableErrorTypes,
            'worker_session' => $this->workerSession?->toSnapshot(),
            'priority' => $this->priority,
            'fairness_key' => $this->fairnessKey,
            'fairness_weight' => $this->fairnessWeight,
        ];
    }

    public function hasRoutingOverrides(): bool
    {
        return $this->connection !== null
            || $this->queue !== null
            || $this->workerSession?->connection !== null
            || $this->workerSession?->queue !== null;
    }

    public function hasRetryOverrides(): bool
    {
        return $this->maxAttempts !== null || $this->backoff !== null || $this->nonRetryableErrorTypes !== [];
    }

    public function hasTimeoutOverrides(): bool
    {
        return $this->startToCloseTimeout !== null
            || $this->scheduleToStartTimeout !== null
            || $this->scheduleToCloseTimeout !== null
            || $this->heartbeatTimeout !== null;
    }

    public function withWorkerSession(WorkerSessionOptions $workerSession): self
    {
        return new self(
            connection: $this->connection,
            queue: $this->queue,
            maxAttempts: $this->maxAttempts,
            backoff: $this->backoff,
            startToCloseTimeout: $this->startToCloseTimeout,
            scheduleToStartTimeout: $this->scheduleToStartTimeout,
            scheduleToCloseTimeout: $this->scheduleToCloseTimeout,
            heartbeatTimeout: $this->heartbeatTimeout,
            nonRetryableErrorTypes: $this->nonRetryableErrorTypes,
            workerSession: $workerSession,
            priority: $this->priority,
            fairnessKey: $this->fairnessKey,
            fairnessWeight: $this->fairnessWeight,
        );
    }

    public function withPriority(?int $priority): self
    {
        return new self(
            connection: $this->connection,
            queue: $this->queue,
            maxAttempts: $this->maxAttempts,
            backoff: $this->backoff,
            startToCloseTimeout: $this->startToCloseTimeout,
            scheduleToStartTimeout: $this->scheduleToStartTimeout,
            scheduleToCloseTimeout: $this->scheduleToCloseTimeout,
            heartbeatTimeout: $this->heartbeatTimeout,
            nonRetryableErrorTypes: $this->nonRetryableErrorTypes,
            workerSession: $this->workerSession,
            priority: $priority,
            fairnessKey: $this->fairnessKey,
            fairnessWeight: $this->fairnessWeight,
        );
    }

    public function withFairness(?string $fairnessKey, ?int $fairnessWeight = null): self
    {
        return new self(
            connection: $this->connection,
            queue: $this->queue,
            maxAttempts: $this->maxAttempts,
            backoff: $this->backoff,
            startToCloseTimeout: $this->startToCloseTimeout,
            scheduleToStartTimeout: $this->scheduleToStartTimeout,
            scheduleToCloseTimeout: $this->scheduleToCloseTimeout,
            heartbeatTimeout: $this->heartbeatTimeout,
            nonRetryableErrorTypes: $this->nonRetryableErrorTypes,
            workerSession: $this->workerSession,
            priority: $this->priority,
            fairnessKey: $fairnessKey,
            fairnessWeight: $fairnessWeight,
        );
    }

    public function hasSchedulingOverrides(): bool
    {
        return $this->priority !== null
            || $this->fairnessKey !== null
            || $this->fairnessWeight !== null;
    }
}
