<?php

declare(strict_types=1);

namespace Workflow\V2\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after a workflow start is durably committed.
 *
 * Timestamp semantics: `committedAt` is the wall-clock time at which the
 * durable WorkflowStarted history event was recorded (commit time).
 */
class WorkflowStarted
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $instanceId,
        public readonly string $runId,
        public readonly string $workflowType,
        public readonly string $workflowClass,
        public readonly string $committedAt,
    ) {
    }
}
