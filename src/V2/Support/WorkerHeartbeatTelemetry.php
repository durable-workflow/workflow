<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Builds the worker-fleet telemetry payload (task-slot availability + basic
 * process-level metrics) that every official SDK ships with periodic worker
 * heartbeats. The payload feeds the worker management API, the CLI worker
 * listing, and the operator Worker Status view so operators can answer
 * "what workers are polling task queue X right now, what's their slot
 * capacity, when did each last check in" without writing custom monitoring.
 *
 * SDKs are not required to populate every key — anything they don't have
 * cheap access to in the runtime is simply omitted, and the server records
 * only what the SDK reports. The shape is shared between PHP and Python
 * SDKs (and future runtimes) so the operator surface stays identical
 * regardless of which SDK emitted the heartbeat.
 *
 * @api Stable surface intended for SDK integrators that drive the
 *      worker-protocol heartbeat directly. Adding new optional keys to the
 *      returned arrays is a minor change; renaming or removing keys is a
 *      breaking change.
 */
final class WorkerHeartbeatTelemetry
{
    /**
     * Build the `task_slots` entry for a heartbeat payload.
     *
     * Each *_inflight argument is the number of slots currently consumed by
     * in-flight tasks of that family; the available count is derived as
     * max(0, capacity - inflight). When a SDK does not track in-flight count
     * for a slot family, callers should pass null for that family and the
     * key will be omitted from the resulting payload.
     *
     * @return array<string, int>
     */
    public static function taskSlots(
        ?int $workflowCapacity = null,
        ?int $workflowInflight = null,
        ?int $activityCapacity = null,
        ?int $activityInflight = null,
        ?int $sessionCapacity = null,
        ?int $sessionInflight = null,
    ): array {
        $slots = [];

        if ($workflowCapacity !== null && $workflowInflight !== null) {
            $slots['workflow_available'] = max(0, $workflowCapacity - $workflowInflight);
        }

        if ($activityCapacity !== null && $activityInflight !== null) {
            $slots['activity_available'] = max(0, $activityCapacity - $activityInflight);
        }

        if ($sessionCapacity !== null && $sessionInflight !== null) {
            $slots['session_available'] = max(0, $sessionCapacity - $sessionInflight);
        }

        return $slots;
    }

    /**
     * Build the `process_metrics` entry for a heartbeat payload using
     * runtime APIs that PHP exposes everywhere (no extension required).
     *
     * The optional `$startedAt` argument is a Unix timestamp captured at
     * worker boot; it is used to derive `process_uptime_seconds`. When
     * `$startedAt` is null the uptime entry is omitted.
     *
     * @return array<string, float|int|string>
     */
    public static function processMetrics(?int $startedAt = null): array
    {
        $metrics = [
            'memory_bytes' => self::memoryBytes(),
            'process_id' => self::processId(),
        ];

        $cpuPercent = self::cpuPercent();
        if ($cpuPercent !== null) {
            $metrics['cpu_percent'] = $cpuPercent;
        }

        if ($startedAt !== null) {
            $metrics['process_uptime_seconds'] = max(0, time() - $startedAt);
        }

        $host = self::host();
        if ($host !== null) {
            $metrics['host'] = $host;
        }

        return $metrics;
    }

    private static function memoryBytes(): int
    {
        return max(0, (int) memory_get_usage(true));
    }

    private static function processId(): int
    {
        return max(0, (int) getmypid());
    }

    /**
     * Approximate CPU percent for the current process across the runtime
     * since the process started. Returns null when the runtime does not
     * expose `getrusage()` on this platform.
     */
    private static function cpuPercent(): ?float
    {
        if (! function_exists('getrusage')) {
            return null;
        }

        $usage = getrusage();
        if (! is_array($usage)) {
            return null;
        }

        $userSeconds = (int) ($usage['ru_utime.tv_sec'] ?? 0)
            + ((int) ($usage['ru_utime.tv_usec'] ?? 0)) / 1_000_000;
        $systemSeconds = (int) ($usage['ru_stime.tv_sec'] ?? 0)
            + ((int) ($usage['ru_stime.tv_usec'] ?? 0)) / 1_000_000;
        $cpuSeconds = $userSeconds + $systemSeconds;

        $wallSeconds = max(0.001, microtime(true) - (float) self::processStart());

        return round(min(100.0, max(0.0, ($cpuSeconds / $wallSeconds) * 100.0)), 2);
    }

    private static function host(): ?string
    {
        $host = gethostname();

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Best-effort estimate of the process start time. Returns the
     * server-time at first call, which underestimates the true start
     * for long-running workers but is monotonic and sufficient for the
     * CPU-percent ratio.
     */
    private static function processStart(): float
    {
        static $startedAt = null;

        if ($startedAt === null) {
            $startedAt = microtime(true);
        }

        return $startedAt;
    }
}
