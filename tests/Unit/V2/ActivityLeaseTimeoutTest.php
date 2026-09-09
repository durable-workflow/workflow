<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestActivityTimeoutCleanupWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityOutcomeRecorder;
use Workflow\V2\Support\ActivityTaskClaimer;
use Workflow\V2\Support\TaskRepair;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class ActivityLeaseTimeoutTest extends TestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function repairModes(): iterable
    {
        yield 'ordinary repair pass' => [false];
        yield 'already repaired by an older worker' => [true];
    }

    #[DataProvider('repairModes')]
    public function testExpiredLeaseCannotBypassOverallTimeout(bool $alreadyRepaired): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-09-09 00:00:00');
        try {
            $task = $this->scheduleActivity();
            [$claim] = ActivityTaskClaimer::claim($task->id);
            $this->assertNotNull($claim);

            // The worker disappeared after its claim committed, before recording an outcome.
            Carbon::setTestNow(now()->addMinutes(12));
            if ($alreadyRepaired) {
                TaskRepair::recoverExistingTask($task->fresh(), $claim->run->fresh());
                $this->assertNull(ActivityTaskClaimer::claim($task->id)[0]);
            }

            $report = TaskWatchdog::runPass();
            $this->assertSame(1, $report['activity_timeouts_enforced']);
            $this->assertSame([], $report['activity_timeout_failures']);
            $this->assertSame(ActivityStatus::Failed, $claim->execution->fresh()->status);
            $this->assertSame(TaskStatus::Cancelled, $task->fresh()->status);
            $this->assertSame(1, ActivityAttempt::count());
            $event = WorkflowHistoryEvent::where('event_type', HistoryEventType::ActivityTimedOut)->sole();
            $this->assertSame('schedule_to_close', $event->payload['timeout_kind']);

            $late = ActivityOutcomeRecorder::record(
                $task->id,
                $claim->attemptId(),
                $claim->attemptNumber(),
                'late',
                null,
                1,
                0
            );
            $this->assertFalse($late['recorded']);
            $this->assertSame('stale_attempt', $late['reason']);
            TaskWatchdog::runPass();
            $this->assertSame(
                1,
                WorkflowHistoryEvent::where('event_type', HistoryEventType::ActivityTimedOut)->count()
            );
            $this->assertSame(
                0,
                WorkflowHistoryEvent::where('event_type', HistoryEventType::ActivityCompleted)->count()
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testPendingActivityCannotBeClaimedAfterItsOverallDeadline(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-09-09 00:00:00');
        try {
            $task = $this->scheduleActivity();
            Carbon::setTestNow(now()->addMinute());
            $this->assertNull(ActivityTaskClaimer::claim($task->id)[0]);
            $this->assertSame(0, ActivityAttempt::count());
            $this->assertSame(1, TaskWatchdog::runPass()['activity_timeouts_enforced']);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function scheduleActivity(): WorkflowTask
    {
        config([
            'queue.default' => 'database',
        ]);
        WorkflowStub::make(TestActivityTimeoutCleanupWorkflow::class, 'lease-timeout')->start();
        $workflowTask = WorkflowTask::where('task_type', TaskType::Workflow)->where(
            'status',
            TaskStatus::Ready
        )->sole();
        $this->app->call([new RunWorkflowTask($workflowTask->id), 'handle']);

        return WorkflowTask::where('task_type', TaskType::Activity)->where('status', TaskStatus::Ready)->sole();
    }
}
