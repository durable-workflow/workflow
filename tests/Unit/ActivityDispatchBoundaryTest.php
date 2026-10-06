<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\TestDispatchBoundaryActivity;
use Tests\Fixtures\TestDispatchBoundaryWorkflow;
use Tests\Fixtures\TestOtherActivity;
use Tests\TestCase;
use Workflow\ActivityStub;
use Workflow\Models\StoredWorkflow;
use Workflow\WorkflowStub;

final class ActivityDispatchBoundaryTest extends TestCase
{
    public function testQueuedActivitiesKeepTheirSlotsAndWaitForTheWorkflowUnlock(): void
    {
        Queue::fake();
        $stored = StoredWorkflow::create([
            'class' => TestDispatchBoundaryWorkflow::class,
        ]);
        $workflow = new TestDispatchBoundaryWorkflow($stored);
        $workflow->setJob($this->createStub(Job::class));
        $released = false;
        $workflow->onUnlock = static function (bool $shouldSignal) use (&$released): void {
            $released = $shouldSignal;
        };
        $previous = WorkflowStub::getContext();

        try {
            WorkflowStub::setContext([
                'workflow' => $workflow,
                'storedWorkflow' => $stored,
                'index' => 0,
                'now' => now()
                    ->toIso8601String(),
                'replaying' => false,
            ]);

            ActivityStub::make(TestDispatchBoundaryActivity::class, 'fixture');
            ActivityStub::make(TestOtherActivity::class);
            Queue::assertNothingPushed();
            ($workflow->onUnlock)(true);

            $this->assertTrue($released);
            Queue::assertPushed(TestDispatchBoundaryActivity::class, static fn ($job): bool =>
                $job->index === 0 && $job->arguments === ['fixture']);
            Queue::assertPushed(TestOtherActivity::class, static fn ($job): bool => $job->index === 1);
            Queue::assertPushed(TestDispatchBoundaryActivity::class, 1);
            Queue::assertPushed(TestOtherActivity::class, 1);
        } finally {
            WorkflowStub::setContext((array) $previous);
        }
    }
}
