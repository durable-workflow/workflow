<?php

declare(strict_types=1);

namespace Workflow\V2\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/** Application notification after a cancelled run's storage transaction commits. */
class WorkflowCancelled
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $instanceId,
        public readonly string $runId,
        public readonly string $workflowType,
        public readonly string $workflowClass,
        public readonly string $committedAt,
        public readonly ?string $reason,
        public readonly string $closedAt,
    ) {
    }
}
