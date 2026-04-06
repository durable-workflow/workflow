<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowTask;

final class TaskRepairPolicy
{
    public const REDISPATCH_AFTER_SECONDS = 3;

    public const LOOP_THROTTLE_SECONDS = 5;

    public const SCAN_LIMIT = 25;

    public static function leaseExpired(WorkflowTask $task, ?CarbonInterface $now = null): bool
    {
        return $task->status === TaskStatus::Leased
            && $task->lease_expires_at !== null
            && $task->lease_expires_at->lte($now ?? now());
    }

    public static function readyTaskNeedsRedispatch(WorkflowTask $task, ?CarbonInterface $now = null): bool
    {
        if ($task->status !== TaskStatus::Ready) {
            return false;
        }

        if ($task->available_at !== null && $task->available_at->isFuture()) {
            return false;
        }

        $reference = $task->last_dispatched_at ?? $task->created_at;

        if ($reference === null) {
            return false;
        }

        return $reference->lte(($now ?? now())->copy()->subSeconds(self::REDISPATCH_AFTER_SECONDS));
    }
}
