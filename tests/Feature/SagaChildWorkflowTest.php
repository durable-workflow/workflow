<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestActivity;
use Tests\Fixtures\TestChildExceptionThrowingWorkflow;
use Tests\Fixtures\TestSagaChildWorkflow;
use Tests\Fixtures\TestSagaSingleChildWorkflow;
use Tests\Fixtures\TestUndoActivity;
use Tests\TestCase;
use Workflow\Exception;
use Workflow\Models\StoredWorkflow;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

final class SagaChildWorkflowTest extends TestCase
{
    public function testSingleChildExceptionTriggersCompensation(): void
    {
        $workflow = WorkflowStub::make(TestSagaSingleChildWorkflow::class);

        $workflow->start();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('compensated', $workflow->output());
    }

    public function testParallelChildExceptionsTriggersCompensation(): void
    {
        $workflow = WorkflowStub::make(TestSagaChildWorkflow::class);

        $workflow->start();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('compensated', $workflow->output());

        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());

        $this->assertEqualsCanonicalizing([
            TestActivity::class,
            TestUndoActivity::class,
            Exception::class,
        ], $storedWorkflow->logs()
            ->pluck('class')
            ->toArray());

        $childLogs = $storedWorkflow->children()
            ->with('logs')
            ->get()
            ->flatMap(static fn (StoredWorkflow $childWorkflow) => $childWorkflow->logs->pluck('class'))
            ->values()
            ->toArray();

        $this->assertNotContains(TestUndoActivity::class, $childLogs);
        $this->assertContains(TestChildExceptionThrowingWorkflow::class, $childLogs);
    }
}
