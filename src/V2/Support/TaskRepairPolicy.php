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

    public const SCAN_STRATEGY = 'scope_fair_round_robin';

    public const FAILURE_BACKOFF_MAX_SECONDS = 60;

    public const FAILURE_BACKOFF_STRATEGY = 'exponential_by_repair_count';

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
        return self::configuredPositiveInt('workflows.v2.task_repair.scan_limit', self::SCAN_LIMIT);
    }

    public static function failureBackoffMaxSeconds(): int
    {
        return self::configuredPositiveInt(
            'workflows.v2.task_repair.failure_backoff_max_seconds',
            self::FAILURE_BACKOFF_MAX_SECONDS,
        );
    }

    /**
     * @return array{
     *     redispatch_after_seconds: int,
     *     loop_throttle_seconds: int,
     *     scan_limit: int,
     *     scan_strategy: string,
     *     failure_backoff_max_seconds: int,
     *     failure_backoff_strategy: string
     * }
     */
    public static function snapshot(): array
    {
        return [
            'redispatch_after_seconds' => self::redispatchAfterSeconds(),
            'loop_throttle_seconds' => self::loopThrottleSeconds(),
            'scan_limit' => self::scanLimit(),
            'scan_strategy' => self::SCAN_STRATEGY,
            'failure_backoff_max_seconds' => self::failureBackoffMaxSeconds(),
            'failure_backoff_strategy' => self::FAILURE_BACKOFF_STRATEGY,
        ];
    }

    public static function repairAvailableAtAfterFailure(
        WorkflowTask $task,
        CarbonInterface $failedAt,
        bool $immediateFirstFailure = false,
    ): CarbonInterface {
        $seconds = self::failureBackoffSeconds($task, $immediateFirstFailure);

        return $failedAt->copy()
            ->addSeconds($seconds);
    }

    public static function failureBackoffSeconds(WorkflowTask $task, bool $immediateFirstFailure = false): int
    {
        $repairCount = max(0, (int) $task->repair_count);

        if ($immediateFirstFailure && $repairCount === 0) {
            return 0;
        }

        $exponent = max(0, $repairCount - 1);
        $base = self::redispatchAfterSeconds();
        $max = self::failureBackoffMaxSeconds();
        $seconds = $base;

        for ($i = 0; $i < $exponent && $seconds < $max; $i++) {
            $seconds = min($max, $seconds * 2);
        }

        return $seconds;
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

    public static function dispatchFailedNeedsRedispatch(WorkflowTask $task, ?CarbonInterface $now = null): bool
    {
        if (! self::dispatchFailed($task)) {
            return false;
        }

        if ($task->repair_available_at !== null) {
            return $task->repair_available_at->lte($now ?? now());
        }

        return true;
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

        if (self::claimFailed($task)) {
            return false;
        }

        $reference = $task->last_dispatched_at ?? $task->created_at;

        if ($reference === null) {
            return false;
        }

        return $reference->lte(($now ?? now())->copy()->subSeconds(self::redispatchAfterSeconds()));
    }

    public static function claimFailed(WorkflowTask $task): bool
    {
        if ($task->status !== TaskStatus::Ready) {
            return false;
        }

        if ($task->last_claim_failed_at === null) {
            return false;
        }

        return is_string($task->last_claim_error) && trim($task->last_claim_error) !== '';
    }

    public static function claimFailedNeedsRedispatch(WorkflowTask $task, ?CarbonInterface $now = null): bool
    {
        if (! self::claimFailed($task)) {
            return false;
        }

        $now ??= now();

        if ($task->repair_available_at !== null) {
            return $task->repair_available_at->lte($now);
        }

        return $task->last_claim_failed_at->lte($now->copy()->subSeconds(self::redispatchAfterSeconds()));
    }

    public static function readyTaskNeedsRedispatch(WorkflowTask $task, ?CarbonInterface $now = null): bool
    {
        return self::dispatchFailedNeedsRedispatch($task, $now)
            || self::claimFailedNeedsRedispatch($task, $now)
            || self::dispatchOverdue($task, $now);
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
