<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestTimerQueryWorkflow;
use Tests\Fixtures\TestTimerWorkflow;
use Tests\TestCase;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowWaitingStatus;
use Workflow\WorkflowStub;

final class TimerWorkflowTest extends TestCase
{
    public function testTimerWorkflow(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class);

        $now = now();

        $workflow->start(0);

        $this->waitForWorkflow($workflow);

        $this->assertLessThan(5, $now->diffInSeconds(now()));
        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow', $workflow->output());
    }

    public function testTimerWorkflowDelay(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class);

        $now = now();

        $workflow->start(5);

        // The workflow deliberately waits five seconds; allow bounded queue
        // scheduling overhead without falling back to the global test timeout.
        $this->waitForWorkflow($workflow, timeoutSeconds: 15.0);

        $this->assertGreaterThanOrEqual(5, $now->diffInSeconds(now()));
        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow', $workflow->output());
    }

    public function testTimerQueryDuringWait(): void
    {
        $workflow = WorkflowStub::make(TestTimerQueryWorkflow::class);

        $workflow->start(10);

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->status() === WorkflowWaitingStatus::class,
            'the ten-second timer to enter its waiting state',
            15.0,
        );

        $status = $workflow->getStatus();

        $this->assertSame('waiting', $status);
    }
}
