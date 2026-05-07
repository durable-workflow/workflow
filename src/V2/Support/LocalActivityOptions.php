<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;

/**
 * Per-call options for local activities.
 *
 * Local activities run in the workflow worker process and do not create an
 * ordinary queued activity task, so routing options such as connection,
 * queue, worker_session, and schedule-to-start timeout are intentionally not
 * accepted on this options object.
 */
final class LocalActivityOptions
{
    /**
     * @param int|null $maxAttempts Override the activity class $tries value.
     * @param list<int>|int|null $backoff Override the activity class backoff() return.
     * @param int|null $startToCloseTimeout Maximum wall-clock seconds for one local attempt.
     * @param int|null $scheduleToCloseTimeout Maximum wall-clock seconds for the full local execution.
     * @param int|null $heartbeatTimeout Maximum seconds between local activity heartbeats.
     * @param list<string> $nonRetryableErrorTypes Error type or class names that should bypass retries.
     */
    public function __construct(
        public readonly ?int $maxAttempts = null,
        public readonly array|int|null $backoff = null,
        public readonly ?int $startToCloseTimeout = null,
        public readonly ?int $scheduleToCloseTimeout = null,
        public readonly ?int $heartbeatTimeout = null,
        public readonly array $nonRetryableErrorTypes = [],
    ) {
    }

    public static function fromActivityOptions(ActivityOptions $options): self
    {
        if (
            $options->connection !== null
            || $options->queue !== null
            || $options->workerSession !== null
            || $options->scheduleToStartTimeout !== null
        ) {
            throw new InvalidArgumentException(
                'Local activities do not accept connection, queue, worker session, or schedule-to-start routing options.'
            );
        }

        return new self(
            maxAttempts: $options->maxAttempts,
            backoff: $options->backoff,
            startToCloseTimeout: $options->startToCloseTimeout,
            scheduleToCloseTimeout: $options->scheduleToCloseTimeout,
            heartbeatTimeout: $options->heartbeatTimeout,
            nonRetryableErrorTypes: $options->nonRetryableErrorTypes,
        );
    }

    public function toActivityOptions(): ActivityOptions
    {
        return new ActivityOptions(
            maxAttempts: $this->maxAttempts,
            backoff: $this->backoff,
            startToCloseTimeout: $this->startToCloseTimeout,
            scheduleToCloseTimeout: $this->scheduleToCloseTimeout,
            heartbeatTimeout: $this->heartbeatTimeout,
            nonRetryableErrorTypes: $this->nonRetryableErrorTypes,
        );
    }

    /**
     * @return array{
     *     execution_mode: string,
     *     queue_bypassed: bool,
     *     routing: string,
     *     max_attempts: int|null,
     *     backoff: list<int>|int|null,
     *     start_to_close_timeout: int|null,
     *     schedule_to_close_timeout: int|null,
     *     heartbeat_timeout: int|null,
     *     non_retryable_error_types: list<string>
     * }
     */
    public function toSnapshot(): array
    {
        return [
            'execution_mode' => LocalActivityRuntime::EXECUTION_MODE,
            'queue_bypassed' => true,
            'routing' => 'same_process_workflow_task',
            'max_attempts' => $this->maxAttempts,
            'backoff' => $this->backoff,
            'start_to_close_timeout' => $this->startToCloseTimeout,
            'schedule_to_close_timeout' => $this->scheduleToCloseTimeout,
            'heartbeat_timeout' => $this->heartbeatTimeout,
            'non_retryable_error_types' => $this->nonRetryableErrorTypes,
        ];
    }
}
