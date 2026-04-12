<?php

declare(strict_types=1);

namespace Workflow\V2\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after a workflow failure is durably committed.
 *
 * Timestamp semantics: `committedAt` is the wall-clock time at which the
 * durable WorkflowFailed history event was recorded (commit time).
 */
class WorkflowFailed
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $instanceId,
        public readonly string $runId,
        public readonly string $workflowType,
        public readonly string $workflowClass,
        public readonly string $exceptionClass,
        public readonly string $message,
        public readonly string $committedAt,
    ) {
    }
}
