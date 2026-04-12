<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class ActivityWorkerBridgeReason
{
    public static function claim(?string $reason): ?string
    {
        return match ($reason) {
            'backend_unsupported' => 'backend_unavailable',
            'compatibility_unsupported' => 'compatibility_blocked',
            'task_not_activity',
            'activity_execution_missing',
            'workflow_run_missing' => 'task_not_claimable',
            default => $reason,
        };
    }

    public static function claimDetail(?string $reason): ?string
    {
        return match ($reason) {
            'task_not_activity',
            'activity_execution_missing',
            'workflow_run_missing' => $reason,
            default => null,
        };
    }
}
