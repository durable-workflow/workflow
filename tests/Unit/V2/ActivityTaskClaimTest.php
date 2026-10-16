<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityTaskClaim;

final class ActivityTaskClaimTest extends TestCase
{
    public static function deadlines(): array
    {
        return [
            'lease' => [30_000_000, null, null, null, 30_000_000],
            'close' => [30_000_000, 7_123_456, null, null, 7_123_456],
            'heartbeat' => [30_000_000, 20_000_000, 3_000_001, null, 3_000_001],
            'schedule to close' => [30_000_000, 20_000_000, 10_000_000, 1_000_001, 1_000_001],
            'expired lease' => [-1, null, null, null, 0],
            'expired activity deadline' => [30_000_000, -1, null, null, 0],
            'missing lease' => [null, 20_000_000, null, null, 0],
        ];
    }

    #[DataProvider('deadlines')]
    public function testOutcomeAdmissionNeverRenewsItsOriginalBound(
        ?int $lease,
        ?int $close,
        ?int $heartbeat,
        ?int $scheduleToClose,
        int $expected,
    ): void {
        Date::useDefault();
        $now = Carbon::parse('2026-10-16 13:20:00.123456 UTC');
        Date::setTestNow($now);
        try {
            $task = new WorkflowTask([
                'lease_expires_at' => $lease === null ? null : $now->copy()
                    ->addMicroseconds($lease),
            ]);
            $execution = new ActivityExecution([
                'close_deadline_at' => $close === null ? null : $now->copy()
                    ->addMicroseconds($close),
                'heartbeat_deadline_at' => $heartbeat === null ? null : $now->copy()
                    ->addMicroseconds($heartbeat),
                'schedule_to_close_deadline_at' => $scheduleToClose === null
                    ? null : $now->copy()
                        ->addMicroseconds($scheduleToClose),
            ]);
            $before = hrtime(true);
            $claim = new ActivityTaskClaim($task, new WorkflowRun(), $execution, new ActivityAttempt());
            $after = hrtime(true);
            $deadline = $claim->outcomeAdmissionDeadline();
            self::assertGreaterThanOrEqual($before + $expected * 1_000 - 1_000, $deadline);
            self::assertLessThanOrEqual($after + $expected * 1_000 + 1_000, $deadline);

            Date::setTestNow($now->copy()->subDay());
            $task->lease_expires_at = $now->copy()
                ->addDay();
            $execution->close_deadline_at = $now->copy()
                ->addDay();
            self::assertSame($deadline, $claim->outcomeAdmissionDeadline());
        } finally {
            Date::setTestNow();
            Date::useDefault();
        }
    }
}
