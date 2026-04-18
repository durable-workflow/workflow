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
    ) {
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
     *     non_retryable_error_types: list<string>
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
        ];
    }

    public function hasRoutingOverrides(): bool
    {
        return $this->connection !== null || $this->queue !== null;
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
}
