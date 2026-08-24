<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestStateMachineWorkflow;
use Tests\TestCase;
use Workflow\Signal;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

final class StateMachineWorkflowTest extends TestCase
{
    public function testApproved(): void
    {
        $workflow = WorkflowStub::make(TestStateMachineWorkflow::class);

        $workflow->start();
        sleep(3);
        $workflow->submit();
        sleep(3);
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
        sleep(3);
        $workflow->submit();
        sleep(3);
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
