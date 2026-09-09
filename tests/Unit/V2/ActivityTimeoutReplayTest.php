<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestActivityTimeoutCleanupWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\ActivityTimeoutException;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class ActivityTimeoutReplayTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function modes(): iterable
    {
        yield 'activity caught' => [false, true];
        yield 'activity uncaught' => [false, false];
        yield 'local activity caught' => [true, true];
        yield 'local activity uncaught' => [true, false];
    }

    #[DataProvider('modes')]
    public function testTimeoutReplaysAndRunsCleanup(bool $local, bool $recover): void
    {
        config([
            'queue.default' => 'database',
        ]);
        Queue::fake();
        Carbon::setTestNow('2026-09-09 00:00:00');
        try {
            WorkflowStub::make(TestActivityTimeoutCleanupWorkflow::class, 'timeout-replay')->start($local, $recover);
            $this->runTask(TaskType::Workflow);
            if (! $local) {
                Carbon::setTestNow(now()->addMinute());
                $report = TaskWatchdog::runPass();
                $this->assertSame(1, $report['activity_timeouts_enforced']);
                $this->assertSame([], $report['activity_timeout_failures']);
                $this->runTask(TaskType::Workflow);
            }

            // Reload the persisted event and run a fresh workflow task, not an in-memory throwable.
            $event = WorkflowHistoryEvent::where('event_type', HistoryEventType::ActivityTimedOut)->sole();
            $this->assertSame(ActivityTimeoutException::class, $event->payload['exception_class']);
            $this->assertSame('timeout', $event->payload['failure_category']);
            $this->runTask(TaskType::Activity);
            $this->runTask(TaskType::Workflow);
            $stub = WorkflowStub::load('timeout-replay');
            $this->assertSame($recover ? 'completed' : 'failed', $stub->status());
            if ($recover) {
                $this->assertSame('recovered', $stub->output());
            }
            $this->assertSame(
                1,
                WorkflowHistoryEvent::where('event_type', HistoryEventType::ActivityCompleted)->count()
            );
            $this->assertSame($recover ? 0 : 1, WorkflowTask::where('status', TaskStatus::Failed)->count());
            $this->assertSame(0, WorkflowTask::where('last_error', 'like', '%Unable to restore workflow failure%')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    private function runTask(TaskType $type): void
    {
        $task = WorkflowTask::where('task_type', $type)->where('status', TaskStatus::Ready)->sole();
        $job = $type === TaskType::Workflow ? new RunWorkflowTask($task->id) : new RunActivityTask($task->id);
        $this->app->call([$job, 'handle']);
    }
}
