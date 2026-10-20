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
    public static function invalidRenewals(): array
    {
        return [
            'not renewed' => [[
                'lease_renewed' => false,
            ]],
            'not continuing' => [[
                'can_continue' => false,
            ]],
            'cancelled' => [[
                'cancel_requested' => true,
            ]],
            'truthy renewed' => [[
                'lease_renewed' => 'true',
            ]],
            'another task' => [[
                'workflow_task_id' => 'another-task',
            ]],
            'another run' => [[
                'workflow_run_id' => 'another-run',
            ]],
            'another execution' => [[
                'activity_execution_id' => 'another-execution',
            ]],
            'another attempt' => [[
                'activity_attempt_id' => 'another-attempt',
            ]],
            'another owner' => [[
                'lease_owner' => 'another-worker',
            ]],
            'another generation' => [[
                'attempt_number' => 2,
            ]],
            'missing clock' => [[
                'server_time' => null,
            ]],
            'invalid clock' => [[
                'server_time' => '2026-02-30T22:00:00.000000Z',
            ]],
            'lost precision' => [[
                'server_time' => '2026-10-20T22:00:01Z',
            ]],
            'expired acknowledgement' => [[
                'lease_expires_at' => '2026-10-20T22:00:00.000000Z',
            ]],
            'unbounded acknowledgement' => [[
                'lease_expires_at' => '2099-10-20T22:00:00.000000Z',
            ]],
            'predates the original lease' => [[
                'server_time' => '2026-10-20T21:59:00.000000Z',
                'lease_expires_at' => '2026-10-20T21:59:30.000000Z',
            ]],
        ];
    }

    #[DataProvider('invalidRenewals')]
    public function testUnconfirmedRenewalCannotExtendPublication(array $changes): void
    {
        [$claim, $reply] = $this->renewalFixture();
        try {
            $original = $claim->outcomeAdmissionDeadline();
            self::assertFalse($claim->acknowledgeLeaseRenewal(array_replace($reply, $changes), hrtime(true)));
            self::assertSame($original, $claim->outcomeAdmissionDeadline());
        } finally {
            Date::setTestNow();
            Date::useDefault();
        }
    }

    public function testActualOriginalRenewalExtendsOnlyTheAcknowledgedLeaseBudget(): void
    {
        [$claim, $reply] = $this->renewalFixture();
        try {
            $original = $claim->outcomeAdmissionDeadline();
            $requestStarted = hrtime(true);
            self::assertTrue($claim->acknowledgeLeaseRenewal($reply, $requestStarted));
            $renewed = $claim->outcomeAdmissionDeadline();
            self::assertGreaterThan($original, $renewed);
            self::assertSame($requestStarted + 29_000_000_000, $renewed);

            self::assertTrue($claim->acknowledgeLeaseRenewal($reply, $requestStarted));
            self::assertSame($renewed, $claim->outcomeAdmissionDeadline());
        } finally {
            Date::setTestNow();
            Date::useDefault();
        }
    }

    public function testRuntimeRenewalDoesNotExtendOriginalBusinessDeadlines(): void
    {
        [$claim, $reply] = $this->renewalFixture(3);
        try {
            $original = $claim->outcomeAdmissionDeadline();
            self::assertTrue($claim->acknowledgeLeaseRenewal($reply, hrtime(true)));
            self::assertSame($original, $claim->outcomeAdmissionDeadline());
        } finally {
            Date::setTestNow();
            Date::useDefault();
        }
    }

    public static function workflowDeadlines(): array
    {
        return [
            'run deadline' => ['run_deadline_at'],
            'execution deadline' => ['execution_deadline_at'],
        ];
    }

    #[DataProvider('workflowDeadlines')]
    public function testRenewalPreservesTheCapturedWorkflowDeadline(string $field): void
    {
        $before = hrtime(true);
        [$claim, $reply] = $this->renewalFixture(60, 10, $field);
        $after = hrtime(true);
        try {
            $original = $claim->outcomeAdmissionDeadline();
            self::assertGreaterThanOrEqual($before + 3_000_000_000, $original);
            self::assertLessThanOrEqual($after + 3_000_000_000, $original);
            self::assertTrue($claim->acknowledgeLeaseRenewal($reply, hrtime(true)));
            self::assertSame($original, $claim->outcomeAdmissionDeadline());
        } finally {
            Date::setTestNow();
            Date::useDefault();
        }
    }

    public function testReplayedRenewalCannotAcquireAFreshRequestBudget(): void
    {
        [$claim, $reply] = $this->renewalFixture();
        try {
            self::assertTrue($claim->acknowledgeLeaseRenewal($reply, hrtime(true)));
            $original = $claim->outcomeAdmissionDeadline();
            self::assertFalse($claim->acknowledgeLeaseRenewal($reply, hrtime(true)));
            self::assertSame($original, $claim->outcomeAdmissionDeadline());
        } finally {
            Date::setTestNow();
            Date::useDefault();
        }
    }

    public function testRenewalRejectsRequestsOutsideTheCapturedClaim(): void
    {
        [$claim, $reply] = $this->renewalFixture();
        try {
            $original = $claim->outcomeAdmissionDeadline();
            self::assertFalse($claim->acknowledgeLeaseRenewal($reply, 0));
            self::assertFalse($claim->acknowledgeLeaseRenewal($reply, hrtime(true) + 1_000_000_000));
            self::assertSame($original, $claim->outcomeAdmissionDeadline());
        } finally {
            Date::setTestNow();
            Date::useDefault();
        }
    }

    public function testExpiredLocalAuthorityCannotBeRevivedByARenewalReply(): void
    {
        [$claim, $reply] = $this->renewalFixture(60, 0);
        try {
            $original = $claim->outcomeAdmissionDeadline();
            self::assertFalse($claim->acknowledgeLeaseRenewal($reply, hrtime(true)));
            self::assertSame($original, $claim->outcomeAdmissionDeadline());
        } finally {
            Date::setTestNow();
            Date::useDefault();
        }
    }

    public function testRenewalUsesTheCapturedIdentityInsteadOfMutableModelState(): void
    {
        [$claim, $reply] = $this->renewalFixture();
        try {
            $claim->task->id = 'replacement-task';
            $claim->attempt->id = 'replacement-attempt';
            $claim->task->lease_owner = 'replacement-worker';
            self::assertFalse($claim->acknowledgeLeaseRenewal(array_replace($reply, [
                'workflow_task_id' => 'replacement-task',
                'activity_attempt_id' => 'replacement-attempt',
                'lease_owner' => 'replacement-worker',
            ]), hrtime(true)));
            self::assertTrue($claim->acknowledgeLeaseRenewal($reply, hrtime(true)));
        } finally {
            Date::setTestNow();
            Date::useDefault();
        }
    }

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

    private function renewalFixture(
        int $businessSeconds = 60,
        int $leaseSeconds = 10,
        ?string $workflowDeadline = null,
    ): array {
        Date::useDefault();
        $now = Carbon::parse('2026-10-20 22:00:00.000000 UTC');
        Date::setTestNow($now);
        $claim = new ActivityTaskClaim(
            new WorkflowTask([
                'id' => 'original-task',
                'lease_owner' => 'original-worker',
                'lease_expires_at' => $now->copy()
                    ->addSeconds($leaseSeconds),
            ]),
            new WorkflowRun(array_merge(
                [
                    'id' => 'original-run',
                ],
                $workflowDeadline === null ? [] : [
                    $workflowDeadline => $now->copy()
                        ->addSeconds(3),
                ],
            )),
            new ActivityExecution([
                'id' => 'original-execution',
                'close_deadline_at' => $now->copy()
                    ->addSeconds($businessSeconds),
            ]),
            new ActivityAttempt([
                'id' => 'original-attempt',
                'attempt_number' => 1,
            ]),
        );

        return [$claim, [
            'lease_renewed' => true,
            'can_continue' => true,
            'cancel_requested' => false,
            'workflow_task_id' => 'original-task',
            'workflow_run_id' => 'original-run',
            'activity_execution_id' => 'original-execution',
            'activity_attempt_id' => 'original-attempt',
            'lease_owner' => 'original-worker',
            'attempt_number' => 1,
            'server_time' => '2026-10-20T22:00:01.000000Z',
            'lease_expires_at' => '2026-10-20T22:00:30.000000Z',
        ]];
    }
}
