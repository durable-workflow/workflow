<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestAwaitWorkflow;
use Tests\TestCase;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowWaitingStatus;
use Workflow\WorkflowStub;

final class AwaitWorkflowTest extends TestCase
{
    public function testCompleted(): void
    {
        $workflow = WorkflowStub::make(TestAwaitWorkflow::class);

        $workflow->start();

        $workflow->cancel();

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow', $workflow->output());
    }

    public function testCompletedWithDelay(): void
    {
        $workflow = WorkflowStub::make(TestAwaitWorkflow::class);

        $workflow->start();

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->status() === WorkflowWaitingStatus::class,
            'the workflow to begin awaiting its cancel signal',
        );

        $workflow->cancel();

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow', $workflow->output());
    }
}
