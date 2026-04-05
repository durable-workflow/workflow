<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestQueryContinueAsNewWorkflow;
use Tests\Fixtures\V2\TestQueryWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class V2QueryWorkflowTest extends TestCase
{
    public function testQueriesReplayCommittedHistoryAndForwardArguments(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestQueryWorkflow::class, 'query-current');
        $workflow->start();

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        $this->assertSame('waiting-for-name', $workflow->currentStage());
        $this->assertSame(1, $workflow->query('countEventsMatching', 'start'));
        $this->assertSame(0, $workflow->query('countEventsMatching', 'name:'));
    }

    public function testQueriesIgnorePendingAcceptedSignalsUntilTheyAreApplied(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestQueryWorkflow::class, 'query-pending-signal');
        $workflow->start();

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        $result = $workflow->attemptSignal('name-provided', 'Taylor');

        $this->assertTrue($result->accepted());
        $this->assertSame('waiting-for-name', $workflow->currentStage());
        $this->assertSame(0, $workflow->query('countEventsMatching', 'name:'));

        $this->drainReadyTasks();

        $this->assertSame('waiting-for-timer', $workflow->refresh()->currentStage());
        $this->assertSame(1, $workflow->query('countEventsMatching', 'name:'));
    }

    public function testQueriesCanTargetHistoricalSelectedRunsAcrossContinueAsNew(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestQueryContinueAsNewWorkflow::class, 'query-continue');
        $started = $workflow->start(0, 2);
        $firstRunId = $started->runId();

        $this->assertNotNull($firstRunId);

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame(2, $workflow->currentCount());

        $historical = WorkflowStub::loadRun($firstRunId);

        $this->assertSame(1, $historical->currentCount());
    }

    private function drainReadyTasks(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->orderBy('created_at')
                ->first();

            if ($task === null) {
                return;
            }

            if ($task->available_at !== null && $task->available_at->isFuture()) {
                return;
            }

            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }
}
