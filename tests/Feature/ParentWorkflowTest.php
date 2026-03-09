<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestActivity;
use Tests\Fixtures\TestChildExceptionWorkflow;
use Tests\Fixtures\TestChildTimerWorkflow;
use Tests\Fixtures\TestChildWorkflow;
use Tests\Fixtures\TestParentAsyncWorkflow;
use Tests\Fixtures\TestParentExceptionWorkflow;
use Tests\Fixtures\TestParentTimerWorkflow;
use Tests\Fixtures\TestParentWorkflow;
use Tests\TestCase;
use Workflow\AsyncWorkflow;
use Workflow\Models\StoredWorkflow;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowCreatedStatus;
use Workflow\States\WorkflowFailedStatus;
use Workflow\WorkflowStub;

final class ParentWorkflowTest extends TestCase
{
    public function testCompleted(): void
    {
        $workflow = WorkflowStub::make(TestParentWorkflow::class);

        $workflow->start();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());
        $this->assertSame([TestActivity::class, TestChildWorkflow::class], $workflow->logs()
            ->pluck('class')
            ->sort()
            ->values()
            ->toArray());
    }

    public function testRetry(): void
    {
        $workflow = WorkflowStub::make(TestParentExceptionWorkflow::class);

        $workflow->start(shouldThrow: true);

        while ($workflow->running());

        $this->assertSame(WorkflowFailedStatus::class, $workflow->status());
        $this->assertNull($workflow->output());

        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->status = WorkflowCreatedStatus::class;
        $storedWorkflow->save();
        $storedWorkflow->logs()
            ->delete();
        $storedWorkflow->exceptions()
            ->delete();

        $storedChildWorkflow = StoredWorkflow::findOrFail($workflow->id() + 1);
        $storedChildWorkflow->status = WorkflowCreatedStatus::class;
        $storedChildWorkflow->save();
        $storedChildWorkflow->logs()
            ->delete();
        $storedChildWorkflow->exceptions()
            ->delete();

        $workflow->fresh()
            ->start(shouldThrow: false);

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());
        $this->assertSame([TestActivity::class, TestChildExceptionWorkflow::class], $workflow->logs()
            ->pluck('class')
            ->sort()
            ->values()
            ->toArray());
    }

    public function testTimer(): void
    {
        $workflow = WorkflowStub::make(TestParentTimerWorkflow::class);

        $workflow->start(1);

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());
        $this->assertSame([TestActivity::class, TestChildTimerWorkflow::class], $workflow->logs()
            ->pluck('class')
            ->sort()
            ->values()
            ->toArray());
    }

    public function testAsync(): void
    {
        $workflow = WorkflowStub::make(TestParentAsyncWorkflow::class);

        $workflow->start();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());
        $this->assertSame([AsyncWorkflow::class], $workflow->logs()->pluck('class')->sort()->values()->toArray());
    }
}
