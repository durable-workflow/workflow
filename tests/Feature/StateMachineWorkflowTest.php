<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestStateMachineWorkflow;
use Tests\TestCase;
use Workflow\Signal;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowWaitingStatus;
use Workflow\WorkflowStub;

final class StateMachineWorkflowTest extends TestCase
{
    public function testApproved(): void
    {
        $workflow = WorkflowStub::make(TestStateMachineWorkflow::class);

        $workflow->start();
        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->status() === WorkflowWaitingStatus::class,
            'the initial waiting state before submission',
        );
        $workflow->submit();
        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->isSubmitted() === true
                && $workflow->logs()
                    ->contains('class', Signal::class),
            'the submitted state before approval',
        );
        $workflow->approve();

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('approved', $workflow->output());
        $this->assertSame([Signal::class, Signal::class], $workflow->logs()
            ->pluck('class')
            ->sort()
            ->values()
            ->toArray());
    }

    public function testDenied(): void
    {
        $workflow = WorkflowStub::make(TestStateMachineWorkflow::class);

        $workflow->start();
        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->status() === WorkflowWaitingStatus::class,
            'the initial waiting state before submission',
        );
        $workflow->submit();
        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->isSubmitted() === true
                && $workflow->logs()
                    ->contains('class', Signal::class),
            'the submitted state before denial',
        );
        $workflow->deny();

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('denied', $workflow->output());
        $this->assertSame([Signal::class, Signal::class], $workflow->logs()
            ->pluck('class')
            ->sort()
            ->values()
            ->toArray());
    }
}
