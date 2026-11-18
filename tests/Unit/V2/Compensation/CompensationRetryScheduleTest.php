<?php

declare(strict_types=1);

namespace Tests\Unit\V2\Compensation;

use ReflectionClass;
use Tests\TestCase;
use Workflow\V2\Compensation\Activities\CompensateObligationActivity;
use Workflow\V2\Compensation\Recovery\CompensationRetrySchedule;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Support\ActivityLease;
use Workflow\V2\Support\ActivityRetryPolicy;

/** #1205: a recovery run outlasts one crashed child attempt; in-origin compensation keeps failing fast. */
final class CompensationRetryScheduleTest extends TestCase
{
    public function testRecoveryScheduleOutlastsOneLeaseRepairCycle(): void
    {
        config([
            ActivityLease::CONFIG_KEY => 900,
            'workflows.v2.task_repair.loop_throttle_seconds' => 7,
            'workflows.v2.task_repair.failure_backoff_max_seconds' => 40,
        ]);

        $this->assertSame(6, CompensationRetrySchedule::ATTEMPTS);
        $this->assertSame([1, 2, 5, 10, 947], CompensationRetrySchedule::backoff());
        $this->assertGreaterThan(900 + 7, CompensationRetrySchedule::backoff()[4]);
    }

    public function testInOriginCompensationKeepsTheClassDefaultBudget(): void
    {
        $snapshot = ActivityRetryPolicy::snapshot(
            (new ReflectionClass(CompensateObligationActivity::class))->newInstanceWithoutConstructor()
        );

        $this->assertSame(3, $snapshot['max_attempts']);
        $this->assertSame([1, 2, 5, 10, 15, 30, 60, 120], $snapshot['backoff_seconds']);
    }

    public function testAnExecutionKeepsTheBudgetItRecorded(): void
    {
        $execution = new ActivityExecution();
        $execution->retry_policy = [
            'snapshot_version' => 1,
            'max_attempts' => 6,
            'backoff_seconds' => [1, 2, 5, 10, 365],
            'start_to_close_timeout' => null,
            'schedule_to_start_timeout' => null,
            'schedule_to_close_timeout' => null,
            'heartbeat_timeout' => null,
            'non_retryable_error_types' => [],
        ];
        $fallback = (new ReflectionClass(CompensateObligationActivity::class))->newInstanceWithoutConstructor();

        $this->assertSame(6, ActivityRetryPolicy::maxAttempts($execution, $fallback));
        $this->assertSame(365, ActivityRetryPolicy::backoffSeconds($execution, $fallback, 5));
    }
}
