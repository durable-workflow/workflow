<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Support\WorkerHeartbeatTelemetry;

final class WorkerHeartbeatTelemetryTest extends TestCase
{
    public function testTaskSlotsDerivesAvailableFromCapacityMinusInflight(): void
    {
        $slots = WorkerHeartbeatTelemetry::taskSlots(
            workflowCapacity: 10,
            workflowInflight: 3,
            activityCapacity: 8,
            activityInflight: 8,
            sessionCapacity: 4,
            sessionInflight: 0,
        );

        self::assertSame(7, $slots['workflow_available']);
        self::assertSame(0, $slots['activity_available']);
        self::assertSame(4, $slots['session_available']);
    }

    public function testTaskSlotsFloorsAtZeroWhenInflightExceedsCapacity(): void
    {
        $slots = WorkerHeartbeatTelemetry::taskSlots(workflowCapacity: 4, workflowInflight: 9);

        self::assertSame(0, $slots['workflow_available']);
    }

    public function testTaskSlotsOmitsKeysWhenInputsAreUnknown(): void
    {
        $slots = WorkerHeartbeatTelemetry::taskSlots(activityCapacity: 5, activityInflight: 1);

        self::assertSame([
            'activity_available' => 4,
        ], $slots);
    }

    public function testProcessMetricsIncludeMemoryPidAndOptionalUptime(): void
    {
        $startedAt = time() - 30;
        $metrics = WorkerHeartbeatTelemetry::processMetrics(startedAt: $startedAt);

        self::assertArrayHasKey('memory_bytes', $metrics);
        self::assertArrayHasKey('process_id', $metrics);
        self::assertGreaterThan(0, $metrics['memory_bytes']);
        self::assertGreaterThan(0, $metrics['process_id']);

        self::assertArrayHasKey('process_uptime_seconds', $metrics);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $startedAt), $metrics['process_started_at']);
        self::assertGreaterThanOrEqual(0, $metrics['process_uptime_seconds']);
        self::assertLessThan(120, $metrics['process_uptime_seconds']);
    }

    public function testProcessMetricsOmitUptimeWhenStartedAtIsNull(): void
    {
        $metrics = WorkerHeartbeatTelemetry::processMetrics();

        self::assertArrayNotHasKey('process_uptime_seconds', $metrics);
        self::assertArrayNotHasKey('process_started_at', $metrics);
    }

    public function testProcessMetricsCpuPercentUsesStartedAtForLongRunningWorkers(): void
    {
        if (! function_exists('getrusage')) {
            self::markTestSkipped('getrusage() is not available on this platform');
        }

        $startedAt = time() - 3_600;
        $usageBefore = getrusage();
        $sampledAtBefore = microtime(true);

        $metrics = WorkerHeartbeatTelemetry::processMetrics(startedAt: $startedAt);

        $sampledAtAfter = microtime(true);
        $usageAfter = getrusage();

        self::assertIsArray($usageBefore);
        self::assertIsArray($usageAfter);

        self::assertArrayHasKey('cpu_percent', $metrics);
        self::assertGreaterThanOrEqual(
            self::roundedCpuLowerBound($usageBefore, $sampledAtAfter - $startedAt),
            $metrics['cpu_percent'],
        );
        self::assertLessThanOrEqual(
            self::roundedCpuUpperBound($usageAfter, $sampledAtBefore - $startedAt),
            $metrics['cpu_percent'],
        );
    }

    /**
     * @param array<string, int> $usage
     */
    private static function cpuSeconds(array $usage): float
    {
        $userSeconds = (int) ($usage['ru_utime.tv_sec'] ?? 0)
            + ((int) ($usage['ru_utime.tv_usec'] ?? 0)) / 1_000_000;
        $systemSeconds = (int) ($usage['ru_stime.tv_sec'] ?? 0)
            + ((int) ($usage['ru_stime.tv_usec'] ?? 0)) / 1_000_000;

        return $userSeconds + $systemSeconds;
    }

    /**
     * @param array<string, int> $usage
     */
    private static function roundedCpuLowerBound(array $usage, float $wallSeconds): float
    {
        $percentage = self::cpuPercentage($usage, $wallSeconds);

        return floor($percentage * 100) / 100;
    }

    /**
     * @param array<string, int> $usage
     */
    private static function roundedCpuUpperBound(array $usage, float $wallSeconds): float
    {
        $percentage = self::cpuPercentage($usage, $wallSeconds);

        return ceil($percentage * 100) / 100;
    }

    /**
     * @param array<string, int> $usage
     */
    private static function cpuPercentage(array $usage, float $wallSeconds): float
    {
        return min(100.0, max(0.0, (self::cpuSeconds($usage) / max(0.001, $wallSeconds)) * 100.0));
    }
}
