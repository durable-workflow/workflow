<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class ActivityAttemptAuthority
{
    public static function expiredReason(
        WorkflowTask $task,
        ActivityExecution $execution,
        CarbonInterface $at,
        ?WorkflowRun $run = null,
    ): ?string {
        if ($task->lease_expires_at === null || $at->gte($task->lease_expires_at)) {
            return 'lease_expired';
        }

        return self::expiredBusinessReason($execution, $at, $run);
    }

    private static function expiredBusinessReason(
        ActivityExecution $execution,
        CarbonInterface $at,
        ?WorkflowRun $run,
    ): ?string {
        $kind = ActivityTimeoutEnforcer::expiredDeadlineKind($execution, $at);

        if ($kind !== null) {
            return $kind . '_timeout';
        }

        foreach ([
            'run_timeout' => $run?->run_deadline_at,
            'execution_timeout' => $run?->execution_deadline_at,
        ] as $reason => $deadline) {
            if ($deadline !== null && $at->gte($deadline)) {
                return $reason;
            }
        }

        return null;
    }
}
