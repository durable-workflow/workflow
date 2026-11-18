<?php

declare(strict_types=1);

namespace Tests\Unit\V2\Compensation;

use ReflectionClass;
use Tests\TestCase;
use Workflow\V2\Compensation\Activities\CompensateObligationActivity;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Support\ActivityLease;
use Workflow\V2\Support\ActivityRetryPolicy;

/** #1205: one crashed child attempt must be repairable before its parent exhausts its retries. */
final class CompensationRetryScheduleTest extends TestCase
{
    public function testNewCompensationsOutlastOneLeaseRepairCycle(): void
    {
        config([
            ActivityLease::CONFIG_KEY => 900,
            'workflows.v2.task_repair.loop_throttle_seconds' => 7,
            'workflows.v2.task_repair.failure_backoff_max_seconds' => 40,
        ]);
        $snapshot = ActivityRetryPolicy::snapshot($this->compensation());

        $this->assertSame(6, $snapshot['max_attempts']);
        $this->assertSame([1, 2, 5, 10, 947], $snapshot['backoff_seconds']);
        // The final wait alone exceeds the lease plus one repair pass.
        $this->assertGreaterThan(900 + 7, $snapshot['backoff_seconds'][4]);
        // A persistent failure still ends after a bounded total wait.
        $this->assertSame(1 + 2 + 5 + 10 + 947, array_sum($snapshot['backoff_seconds']));
    }

    public function testCompensationsScheduledBeforeTheChangeKeepTheirRecordedBudget(): void
    {
        $execution = new ActivityExecution();
        $execution->retry_policy = [
            'snapshot_version' => 1,
            'max_attempts' => 3,
            'backoff_seconds' => [1, 2, 5, 10, 15, 30, 60, 120],
            'start_to_close_timeout' => null,
            'schedule_to_start_timeout' => null,
            'schedule_to_close_timeout' => null,
            'heartbeat_timeout' => null,
            'non_retryable_error_types' => [],
        ];
        $current = $this->compensation();

        $this->assertSame(3, ActivityRetryPolicy::maxAttempts($execution, $current));
        $this->assertSame(2, ActivityRetryPolicy::backoffSeconds($execution, $current, 2));
    }

    private function compensation(): CompensateObligationActivity
    {
        return (new ReflectionClass(CompensateObligationActivity::class))->newInstanceWithoutConstructor();
    }
}
