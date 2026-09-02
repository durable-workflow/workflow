<?php

declare(strict_types=1);

namespace Workflow\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class ActivityCompleted
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public int|string $workflowId,
        public string $activityId,
        public string $output,
        public string $timestamp,
        public string $class,
        public int $index
    ) {
    }
}
