<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class StickyExecution
{
    public const DEFAULT_TTL_SECONDS = 300;

    public const DEFAULT_CACHE_CAPACITY = 100;

    public const DEFAULT_CAPACITY_PRESSURE_RATIO = 0.9;

    public const MODE_STICKY_HIT_EXPECTED = 'sticky_hit_expected';

    public const MODE_COLD_REPLAY = 'cold_replay';

    public const MODE_FORCED_COLD_REPLAY = 'forced_cold_replay';

    /**
     * @return array<string, mixed>
     */
    public static function describe(): array
    {
        return [
            'feature' => 'sticky_execution',
            'kind' => 'v2_replay_optimization',
            'correctness_fallback' => 'cold_replay',
            'routing_identity' => 'worker_id',
            'cache_owner' => 'worker_process',
            'durable_affinity_fields' => ['sticky_worker_id', 'sticky_until'],
            'task_diagnostic_fields' => ['sticky_replay_mode', 'sticky_claimed_at'],
            'replay_modes' => self::replayModes(),
            'default_ttl_seconds' => self::DEFAULT_TTL_SECONDS,
            'default_cache_capacity' => self::DEFAULT_CACHE_CAPACITY,
            'default_capacity_pressure_ratio' => self::DEFAULT_CAPACITY_PRESSURE_RATIO,
            'contract' => [
                'sticky caches are process-local and owned by the worker that advertises them',
                'sticky affinity expires and ordinary cold replay is always the correctness fallback',
                'worker replacement, restart, drain, or cache eviction may force cold replay',
                'workflow code must remain replay-safe and must not rely on process-local state for correctness',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function replayModes(): array
    {
        return [
            self::MODE_STICKY_HIT_EXPECTED,
            self::MODE_COLD_REPLAY,
            self::MODE_FORCED_COLD_REPLAY,
        ];
    }

    public static function enabled(): bool
    {
        return (bool) config('workflows.v2.sticky_execution.enabled', true);
    }

    public static function ttlSeconds(): int
    {
        return max(1, (int) config('workflows.v2.sticky_execution.ttl_seconds', self::DEFAULT_TTL_SECONDS));
    }

    public static function activeWorkerId(WorkflowRun|WorkflowTask $model, ?CarbonInterface $now = null): ?string
    {
        $workerId = self::nonEmptyString($model->sticky_worker_id ?? null);

        if ($workerId === null || ! self::activeUntil($model, $now)) {
            return null;
        }

        return $workerId;
    }

    public static function activeUntil(WorkflowRun|WorkflowTask $model, ?CarbonInterface $now = null): bool
    {
        $until = $model->sticky_until ?? null;

        if (! $until instanceof CarbonInterface) {
            return false;
        }

        return $until->gt($now ?? now());
    }

    public static function claimReplayMode(WorkflowTask $task, ?string $leaseOwner, ?CarbonInterface $now = null): string
    {
        $stickyWorkerId = self::nonEmptyString($task->sticky_worker_id);

        if ($stickyWorkerId === null) {
            return self::MODE_COLD_REPLAY;
        }

        if (! self::activeUntil($task, $now)) {
            return self::MODE_FORCED_COLD_REPLAY;
        }

        return $leaseOwner === $stickyWorkerId
            ? self::MODE_STICKY_HIT_EXPECTED
            : self::MODE_FORCED_COLD_REPLAY;
    }

    public static function shouldInherit(WorkflowRun $run, ?CarbonInterface $now = null): bool
    {
        return self::enabled() && self::activeWorkerId($run, $now) !== null;
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}
