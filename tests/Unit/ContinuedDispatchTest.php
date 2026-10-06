<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Fixtures\TestContinuedAwaitTimerWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\Signal;
use Workflow\States\WorkflowContinuedStatus;
use Workflow\States\WorkflowPendingStatus;
use Workflow\States\WorkflowWaitingStatus;
use Workflow\Timer;
use Workflow\WorkflowStub;

final class ContinuedDispatchTest extends TestCase
{
    public function testOldJobsDoNotDispatchTheirContinuedRunWhileSuccessorIsPending(): void
    {
        Queue::fake();

        $root = StoredWorkflow::findOrFail(WorkflowStub::make(TestContinuedAwaitTimerWorkflow::class)->id());
        $root->update([
            'arguments' => Serializer::serialize([0, 1]),
            'status' => WorkflowContinuedStatus::$name,
        ]);
        $successor = StoredWorkflow::findOrFail(WorkflowStub::make(TestContinuedAwaitTimerWorkflow::class)->id());
        $successor->update([
            'arguments' => Serializer::serialize([1, 1]),
            'status' => WorkflowPendingStatus::$name,
        ]);
        $root->children()
            ->attach($successor, [
                'parent_index' => StoredWorkflow::ACTIVE_WORKFLOW_INDEX,
                'parent_now' => now(),
            ]);
        $root->timers()
            ->create([
                'index' => 0,
                'stop_at' => now(),
            ]);

        $this->assertSame(WorkflowPendingStatus::class, $root->toWorkflow()->status());
        foreach ([new Signal($root), new Timer($root, 0)] as $job) {
            $queueJob = Mockery::mock(Job::class);
            $queueJob->shouldNotReceive('release');
            $job->setJob($queueJob);
            $job->handle();
        }

        Queue::assertNothingPushed();
        $this->assertInstanceOf(WorkflowContinuedStatus::class, $root->fresh()->status);
        $this->assertInstanceOf(WorkflowPendingStatus::class, $successor->fresh()->status);
        $this->assertSame(0, $root->logs()->count());

        $successor->update([
            'status' => WorkflowWaitingStatus::$name,
        ]);
        $successor->toWorkflow()
            ->resume();
        Queue::assertPushed(
            TestContinuedAwaitTimerWorkflow::class,
            static fn ($job): bool => $job->storedWorkflow->id === $successor->id
        );
        Queue::assertPushed(TestContinuedAwaitTimerWorkflow::class, 1);
        $this->assertInstanceOf(WorkflowPendingStatus::class, $successor->fresh()->status);
    }
}
