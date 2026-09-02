<?php

declare(strict_types=1);

namespace Workflow\V2\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after an activity start is durably committed.
 *
 * Timestamp semantics: `committedAt` is the wall-clock time at which the
 * durable ActivityStarted history event was recorded (commit time).
 */
class ActivityStarted
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
        public readonly string $committedAt,
    ) {
    }
}
