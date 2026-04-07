<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\Fixtures\TestChildWorkflow;
use Tests\Fixtures\TestWorkflow;
use Tests\TestCase;
use Workflow\ChildWorkflow;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowCreatedStatus;
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

    public function testHandleDoesNotWakeParentWhenSiblingsArePending(): void
    {
        $parent = WorkflowStub::make(TestWorkflow::class);
        $storedParent = StoredWorkflow::findOrFail($parent->id());
        $storedParent->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowCreatedStatus::class,
        ]);

        $storedChild1 = StoredWorkflow::create([
            'class' => TestChildWorkflow::class,
            'arguments' => Serializer::serialize([]),
        ]);
        $storedChild2 = StoredWorkflow::create([
            'class' => TestChildWorkflow::class,
            'arguments' => Serializer::serialize([]),
        ]);
        $storedChild3 = StoredWorkflow::create([
            'class' => TestChildWorkflow::class,
            'arguments' => Serializer::serialize([]),
        ]);

        $storedChild1->parents()
            ->attach($storedParent, [
                'parent_index' => 0,
                'parent_now' => now(),
            ]);
        $storedChild2->parents()
            ->attach($storedParent, [
                'parent_index' => 1,
                'parent_now' => now(),
            ]);
        $storedChild3->parents()
            ->attach($storedParent, [
                'parent_index' => 2,
                'parent_now' => now(),
            ]);

        // Only the first child completes; two siblings are still pending
        $job = new ChildWorkflow(0, now()->toDateTimeString(), $storedChild1, true, $storedParent);
        $job->handle();

        // Log written but parent not dispatched (still in created status)
        $this->assertSame(1, $storedParent->logs()->count());
        $this->assertSame(WorkflowCreatedStatus::class, $storedParent->refresh()->status::class);
    }

    public function testHandleWakesParentOnLastSiblingCompletion(): void
    {
        $parent = WorkflowStub::make(TestWorkflow::class);
        $storedParent = StoredWorkflow::findOrFail($parent->id());
        $storedParent->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowCreatedStatus::class,
        ]);

        $storedChild1 = StoredWorkflow::create([
            'class' => TestChildWorkflow::class,
            'arguments' => Serializer::serialize([]),
        ]);
        $storedChild2 = StoredWorkflow::create([
            'class' => TestChildWorkflow::class,
            'arguments' => Serializer::serialize([]),
        ]);

        $storedChild1->parents()
            ->attach($storedParent, [
                'parent_index' => 0,
                'parent_now' => now(),
            ]);
        $storedChild2->parents()
            ->attach($storedParent, [
                'parent_index' => 1,
                'parent_now' => now(),
            ]);

        // First child completes — parent should not be woken
        $job1 = new ChildWorkflow(0, now()->toDateTimeString(), $storedChild1, true, $storedParent);
        $job1->handle();
        $this->assertSame(WorkflowCreatedStatus::class, $storedParent->refresh()->status::class);

        // Second (last) child completes — parent should be dispatched (pending)
        $job2 = new ChildWorkflow(1, now()->toDateTimeString(), $storedChild2, true, $storedParent);
        $job2->handle();
        $this->assertSame(2, $storedParent->logs()->count());
        $this->assertNotSame(WorkflowCreatedStatus::class, $storedParent->refresh()->status::class);
    }
}
