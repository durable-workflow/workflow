<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class ActivityTaskClaim
{
    private readonly int $outcomeAdmissionDeadline;

    public function __construct(
        public readonly WorkflowTask $task,
        public readonly WorkflowRun $run,
        public readonly ActivityExecution $execution,
        public readonly ActivityAttempt $attempt,
    ) {
        $monotonic = hrtime(true);
        $now = now();
        $remaining = $task->lease_expires_at === null
            ? 0
            : max(0, $now->diffInMicroseconds($task->lease_expires_at, false));
        foreach ([
            $execution->close_deadline_at,
            $execution->heartbeat_deadline_at,
            $execution->schedule_to_close_deadline_at,
        ] as $deadline) {
            if ($deadline !== null) {
                $remaining = min($remaining, max(0, $now->diffInMicroseconds($deadline, false)));
            }
        }
        $this->outcomeAdmissionDeadline = $monotonic + (int) ($remaining * 1_000);
    }

    /**
     * Retry admission within the captured claim; fresh stored authority still decides publication.
     */
    public function outcomeAdmissionDeadline(): int
    {
        return $this->outcomeAdmissionDeadline;
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
