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

    public static function redispatchAfterSeconds(): int
    {
        return self::configuredPositiveInt(
            'workflows.v2.task_repair.redispatch_after_seconds',
            self::REDISPATCH_AFTER_SECONDS,
        );
    }

    public static function loopThrottleSeconds(): int
    {
        return self::configuredPositiveInt(
            'workflows.v2.task_repair.loop_throttle_seconds',
            self::LOOP_THROTTLE_SECONDS,
        );
    }

    public static function scanLimit(): int
    {
        return self::configuredPositiveInt(
            'workflows.v2.task_repair.scan_limit',
            self::SCAN_LIMIT,
        );
    }

    /**
     * @return array{redispatch_after_seconds: int, loop_throttle_seconds: int, scan_limit: int}
     */
    public static function snapshot(): array
    {
        return [
            'redispatch_after_seconds' => self::redispatchAfterSeconds(),
            'loop_throttle_seconds' => self::loopThrottleSeconds(),
            'scan_limit' => self::scanLimit(),
        ];
    }

    public static function leaseExpired(WorkflowTask $task, ?CarbonInterface $now = null): bool
    {
        return $task->status === TaskStatus::Leased
            && $task->lease_expires_at !== null
            && $task->lease_expires_at->lte($now ?? now());
    }

    public static function dispatchFailed(WorkflowTask $task): bool
    {
        if ($task->status !== TaskStatus::Ready) {
            return false;
        }

        if ($task->last_dispatch_attempt_at === null) {
            return false;
        }

        if (! is_string($task->last_dispatch_error) || trim($task->last_dispatch_error) === '') {
            return false;
        }

        return $task->last_dispatched_at === null
            || $task->last_dispatch_attempt_at->gt($task->last_dispatched_at);
    }

    public static function dispatchOverdue(WorkflowTask $task, ?CarbonInterface $now = null): bool
    {
        if ($task->status !== TaskStatus::Ready) {
            return false;
        }

        if ($task->available_at !== null && $task->available_at->isFuture()) {
            return false;
        }

        if (self::dispatchFailed($task)) {
            return false;
        }

        $reference = $task->last_dispatched_at ?? $task->created_at;

        if ($reference === null) {
            return false;
        }

        return $reference->lte(($now ?? now())->copy()->subSeconds(self::redispatchAfterSeconds()));
    }

    public static function readyTaskNeedsRedispatch(WorkflowTask $task, ?CarbonInterface $now = null): bool
    {
        return self::dispatchFailed($task) || self::dispatchOverdue($task, $now);
    }

    private static function configuredPositiveInt(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_numeric($value)) {
            return $default;
        }

        return max(1, (int) $value);
    }
}
