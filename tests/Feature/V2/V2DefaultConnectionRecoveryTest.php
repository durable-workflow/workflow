<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class V2DefaultConnectionRecoveryTest extends TestCase
{
    public function testRealDefaultWorkersRecoverALostEnvelopeWithoutRepeatingActivity(): void
    {
        $this->assertSame('redis', config('queue.default'));
        $queue = $this->app->make('queue');
        // Drop only the initial queue envelope. Separate workers continue to
        // use Redis, and the durable task remains committed in the database.
        Queue::fake();
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'default-connection-recovery');
        $workflow->start('recovery');
        $runId = $workflow->runId();
        $task = WorkflowTask::query()->where('workflow_run_id', $runId)->firstOrFail();
        $this->assertNull($task->connection);
        $task->forceFill([
            'available_at' => now()
                ->subMinute(),
            'last_dispatched_at' => now()
                ->subMinute(),
        ])->save();
        Queue::swap($queue);
        $this->enableAutomaticTaskRepair();

        $this->waitForWorkflow($workflow, static fn (WorkflowStub $run): bool => ! $run->refresh()->running());

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame($runId, $workflow->runId());
        $this->assertSame([
            'greeting' => 'Hello, recovery!',
            'workflow_id' => 'default-connection-recovery',
            'run_id' => $runId,
        ], $workflow->output());
        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame(1, $task->attempt_count);
        $this->assertGreaterThanOrEqual(1, $task->repair_count);
        $activities = ActivityExecution::query()->where('workflow_run_id', $runId)->get();
        $this->assertCount(1, $activities);
        $this->assertSame(ActivityStatus::Completed, $activities->first()->status);
        $this->assertSame(1, $activities->first()->attempt_count);
        foreach ([HistoryEventType::ActivityCompleted, HistoryEventType::WorkflowCompleted] as $eventType) {
            $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
                ->where('event_type', $eventType->value)
                ->count());
        }
    }
}
