<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestActivity;
use Tests\Fixtures\TestSagaParallelActivityWorkflow;
use Tests\Fixtures\TestSagaWorkflow;
use Tests\Fixtures\TestUndoActivity;
use Tests\TestCase;
use Workflow\Exception;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowFailedStatus;
use Workflow\WorkflowStub;

final class SagaWorkflowTest extends TestCase
{
    public function testCompleted(): void
    {
        $workflow = WorkflowStub::make(TestSagaWorkflow::class);

        $workflow->start(shouldThrow: false);

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('saga_workflow', $workflow->output());
        $this->assertSame([TestActivity::class, TestUndoActivity::class, Exception::class], $workflow->logs()
            ->pluck('class')
            ->sort()
            ->values()
            ->toArray());
    }

    public function testFailed(): void
    {
        $workflow = WorkflowStub::make(TestSagaWorkflow::class);

        $workflow->start(shouldThrow: true);

        while ($workflow->running());

        $this->assertSame(WorkflowFailedStatus::class, $workflow->status());
        $this->assertNull($workflow->output());
        $this->assertSame([TestActivity::class, TestUndoActivity::class, Exception::class], $workflow->logs()
            ->pluck('class')
            ->sort()
            ->values()
            ->toArray());
    }

    public function testParallelActivityExceptionsTriggersCompensation(): void
    {
        $workflow = WorkflowStub::make(TestSagaParallelActivityWorkflow::class);

        $workflow->start();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('compensated', $workflow->output());
    }
}
