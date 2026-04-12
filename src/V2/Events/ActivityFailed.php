<?php

declare(strict_types=1);

namespace Workflow\V2\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after a terminal activity failure is durably committed.
 *
 * This event is only dispatched for terminal failures (all retries exhausted
 * or non-retryable exception). Retryable failures do not trigger this event.
 *
 * Timestamp semantics: `committedAt` is the wall-clock time at which the
 * durable ActivityFailed history event was recorded (commit time).
 */
class ActivityFailed
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $instanceId,
        public readonly string $runId,
        public readonly string $activityExecutionId,
        public readonly string $activityType,
        public readonly string $activityClass,
        public readonly int $sequence,
        public readonly int $attemptNumber,
        public readonly string $exceptionClass,
        public readonly string $message,
        public readonly string $committedAt,
    ) {
    }
}
