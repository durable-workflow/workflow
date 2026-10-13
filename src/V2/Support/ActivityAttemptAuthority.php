<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowTask;

final class ActivityAttemptAuthority
{
    public static function expiredReason(
        WorkflowTask $task,
        ActivityExecution $execution,
        CarbonInterface $at,
    ): ?string {
        if ($task->lease_expires_at === null || $at->gte($task->lease_expires_at)) {
            return 'lease_expired';
        }

        $kind = ActivityTimeoutEnforcer::expiredDeadlineKind($execution, $at);

        return $kind === null ? null : $kind . '_timeout';
    }
}
