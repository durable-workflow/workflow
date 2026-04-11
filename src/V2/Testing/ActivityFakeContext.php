<?php

declare(strict_types=1);

namespace Workflow\V2\Testing;

use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;

final class ActivityFakeContext
{
    public function __construct(
        public readonly WorkflowRun $run,
        public readonly ActivityExecution $execution,
        public readonly ?string $taskId,
        public readonly int $sequence,
        public readonly string $activity,
    ) {
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
}
