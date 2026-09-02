<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\Fixtures\TestChildWorkflow;
use Tests\Fixtures\TestWorkflow;
use Tests\TestCase;
use Workflow\ChildWorkflow;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowRunningStatus;
use Workflow\WorkflowStub;

final class ChildWorkflowTest extends TestCase
{
    public function testHandleReleasesWhenParentWorkflowIsRunning(): void
    {
        $parent = WorkflowStub::make(TestWorkflow::class);
        $storedParent = StoredWorkflow::findOrFail($parent->id());
        $storedParent->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowRunningStatus::class,
        ]);

        $storedChild = StoredWorkflow::create([
            'class' => TestChildWorkflow::class,
            'arguments' => Serializer::serialize([]),
        ]);

        $job = new ChildWorkflow(0, now()->toDateTimeString(), $storedChild, true, $storedParent);

        $job->handle();

        $this->assertSame(1, $storedParent->logs()->count());
        $this->assertSame(WorkflowRunningStatus::class, $storedParent->refresh()->status::class);
    }
}
