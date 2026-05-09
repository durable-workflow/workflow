<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Support\WorkerHeartbeatTelemetry;

final class WorkerHeartbeatTelemetryTest extends TestCase
{
    public function test_task_slots_derives_available_from_capacity_minus_inflight(): void
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

    public function test_task_slots_floors_at_zero_when_inflight_exceeds_capacity(): void
    {
        $slots = WorkerHeartbeatTelemetry::taskSlots(
            workflowCapacity: 4,
            workflowInflight: 9,
        );

        self::assertSame(0, $slots['workflow_available']);
    }

    public function test_task_slots_omits_keys_when_inputs_are_unknown(): void
    {
        $slots = WorkerHeartbeatTelemetry::taskSlots(
            activityCapacity: 5,
            activityInflight: 1,
        );

        self::assertSame(['activity_available' => 4], $slots);
    }

    public function test_process_metrics_include_memory_pid_and_optional_uptime(): void
    {
        $metrics = WorkerHeartbeatTelemetry::processMetrics(startedAt: time() - 30);

        self::assertArrayHasKey('memory_bytes', $metrics);
        self::assertArrayHasKey('process_id', $metrics);
        self::assertGreaterThan(0, $metrics['memory_bytes']);
        self::assertGreaterThan(0, $metrics['process_id']);

        self::assertArrayHasKey('process_uptime_seconds', $metrics);
        self::assertGreaterThanOrEqual(0, $metrics['process_uptime_seconds']);
        self::assertLessThan(120, $metrics['process_uptime_seconds']);
    }

    public function test_process_metrics_omit_uptime_when_started_at_is_null(): void
    {
        $metrics = WorkerHeartbeatTelemetry::processMetrics();

        self::assertArrayNotHasKey('process_uptime_seconds', $metrics);
    }

    public function test_process_metrics_cpu_percent_uses_started_at_for_long_running_workers(): void
    {
        if (! function_exists('getrusage')) {
            self::markTestSkipped('getrusage() is not available on this platform');
        }

        // Burn a small, predictable amount of CPU so getrusage() reports
        // non-zero accumulated time for this PHP process.
        $sum = 0;
        for ($i = 0; $i < 200_000; $i++) {
            $sum += $i;
        }
        self::assertGreaterThan(0, $sum);

        // A heartbeat from a worker that booted an hour ago: even if this
        // PHP process has burned a few hundred milliseconds of CPU
        // overall, dividing by 3600 wall-clock seconds must keep
        // cpu_percent in single digits — far from the 100% the bug
        // would produce when the wall-clock denominator was the
        // first-call cached "now".
        $metrics = WorkerHeartbeatTelemetry::processMetrics(startedAt: time() - 3600);

        self::assertArrayHasKey('cpu_percent', $metrics);
        self::assertGreaterThanOrEqual(0.0, $metrics['cpu_percent']);
        self::assertLessThan(10.0, $metrics['cpu_percent']);
    }
}
