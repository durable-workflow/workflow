<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestParentSignalingChildViaSignal;
use Tests\Fixtures\TestParentWorkflowSignalingChildDirectly;
use Tests\Fixtures\TestParentWorkflowWithContextCheck;
use Tests\Fixtures\TestParentWorkflowWithMultipleChildren;
use Tests\TestCase;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowWaitingStatus;
use Workflow\WorkflowStub;

final class ChildWorkflowSignalingTest extends TestCase
{
    public function testParentCanSignalChildDirectly(): void
    {
        $parentWorkflow = WorkflowStub::make(TestParentWorkflowSignalingChildDirectly::class);
        $parentWorkflow->start();

        $this->waitForWorkflow($parentWorkflow);

        $this->assertSame(WorkflowCompletedStatus::class, $parentWorkflow->status());
        $this->assertSame('direct_signaling_approved', $parentWorkflow->output());
    }

    public function testParentContextNotCorruptedByChildSignaling(): void
    {
        $parentWorkflow = WorkflowStub::make(TestParentWorkflowWithContextCheck::class);
        $parentWorkflow->start();

        $this->waitForWorkflow($parentWorkflow);

        $this->assertSame(WorkflowCompletedStatus::class, $parentWorkflow->status());
        $this->assertSame('success', $parentWorkflow->output());
    }

    public function testParentSignalMethodForwardsToChild(): void
    {
        $parentWorkflow = WorkflowStub::make(TestParentSignalingChildViaSignal::class);
        $parentWorkflow->start();

        $this->waitForWorkflow(
            $parentWorkflow,
            static fn (WorkflowStub $workflow): bool => $workflow->status() === WorkflowWaitingStatus::class,
            'the parent to await its forwarding signal',
        );

        $parentWorkflow->forwardApproval('approved');

        // This path includes both parent and child dispatches, so retain its
        // original ten-second completion budget.
        $this->waitForWorkflow($parentWorkflow, timeoutSeconds: 10.0);

        $this->assertSame(WorkflowCompletedStatus::class, $parentWorkflow->status());
        $this->assertSame('forwarded_approved', $parentWorkflow->output());
    }

    public function testChildrenReturnsMultipleHandlesInOrder(): void
    {
        $parentWorkflow = WorkflowStub::make(TestParentWorkflowWithMultipleChildren::class);
        $parentWorkflow->start();

        $this->waitForWorkflow($parentWorkflow);

        $this->assertSame(WorkflowCompletedStatus::class, $parentWorkflow->status());
        $this->assertSame('child1_first|child2_second|child3_third', $parentWorkflow->output());
    }
}
