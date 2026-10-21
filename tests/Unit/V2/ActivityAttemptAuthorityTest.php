<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityAttemptAuthority;

final class ActivityAttemptAuthorityTest extends TestCase
{
    public static function workflowDeadlines(): array
    {
        return [
            'run' => ['run_deadline_at', 'run_timeout'],
            'execution' => ['execution_deadline_at', 'execution_timeout'],
        ];
    }

    #[DataProvider('workflowDeadlines')]
    public function testLiveLeaseCannotOverrideOriginalWorkflowDeadline(string $field, string $reason): void
    {
        Date::useDefault();
        try {
            $deadline = Carbon::parse('2026-10-21T03:00:00.123456Z');
            $task = new WorkflowTask([
                'lease_expires_at' => $deadline->copy()
                    ->addMinutes(5),
            ]);
            $execution = new ActivityExecution();
            $run = new WorkflowRun([
                $field => $deadline,
            ]);
            $before = [$execution->getAttributes(), $run->getAttributes()];

            self::assertNull(
                ActivityAttemptAuthority::expiredReason($task, $execution, $deadline->copy()->subMicrosecond(), $run)
            );
            self::assertSame($reason, ActivityAttemptAuthority::expiredReason($task, $execution, $deadline, $run));
            $task->lease_expires_at = $deadline->copy()
                ->addDay();
            self::assertSame(
                $reason,
                ActivityAttemptAuthority::expiredReason($task, $execution, $deadline->copy()->addMicrosecond(), $run)
            );
            self::assertSame($before, [$execution->getAttributes(), $run->getAttributes()]);
        } finally {
            Date::setTestNow();
            Date::useDefault();
        }
    }
}
