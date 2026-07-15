<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestTimerQueryWorkflow;
use Tests\Fixtures\TestTimerWorkflow;
use Tests\TestCase;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

final class TimerWorkflowTest extends TestCase
{
    public function testTimerWorkflow(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class);

        $now = now();

        $workflow->start(0);

        while ($workflow->running());

        $this->assertLessThan(5, $now->diffInSeconds(now()));
        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow', $workflow->output());
    }

    public function testTimerWorkflowDelay(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class);

        $now = now();

        $workflow->start(5);

        while ($workflow->running());

        $this->assertGreaterThanOrEqual(5, $now->diffInSeconds(now()));
        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow', $workflow->output());
    }

    public function testTimerQueryDuringWait(): void
    {
        $workflow = WorkflowStub::make(TestTimerQueryWorkflow::class);

        $workflow->start(10);

        sleep(1);

        $status = $workflow->getStatus();

        $this->assertSame('waiting', $status);
    }
}
