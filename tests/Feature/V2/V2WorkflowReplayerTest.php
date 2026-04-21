<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

final class V2WorkflowReplayerTest extends TestCase
{
    public function testPublicReplayerCanReplayLiveRun(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'public-replayer-live-run');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        $state = (new WorkflowReplayer())->replay(WorkflowRun::query()->findOrFail($runId));

        $this->assertSame(1, $state->sequence);
        $this->assertInstanceOf(ActivityCall::class, $state->current);
        $this->assertSame('public-replayer-live-run', $state->workflow->workflowId());
    }

    public function testPublicReplayerCanReplayHistoryExportBundle(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'public-replayer-history-export');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);
        $this->runReadyTaskForRun($runId, TaskType::Activity);
        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        $run = WorkflowRun::query()->findOrFail($runId);
        $export = HistoryExport::forRun($run);
        $state = (new WorkflowReplayer())->replayExport($export);

        $this->assertSame(2, $state->sequence);
        $this->assertNull($state->current);
        $this->assertSame('public-replayer-history-export', $state->workflow->workflowId());
    }

    private function runReadyTaskForRun(string $runId, TaskType $taskType): void
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', $taskType->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->first();

        if ($task === null) {
            $this->fail(sprintf('Expected a ready %s task for run %s.', $taskType->value, $runId));
        }

        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };

        $this->app->call([$job, 'handle']);
    }
}
