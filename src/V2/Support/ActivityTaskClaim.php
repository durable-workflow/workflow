<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class ActivityTaskClaim
{
    public function __construct(
        public readonly WorkflowTask $task,
        public readonly WorkflowRun $run,
        public readonly ActivityExecution $execution,
        public readonly ActivityAttempt $attempt,
    ) {
    }

    public function activityExecutionId(): string
    {
        return $this->execution->id;
    }

    public function attemptId(): string
    {
        return $this->attempt->id;
    }

    public function attemptNumber(): int
    {
        return max(1, (int) $this->attempt->attempt_number);
    }
}
