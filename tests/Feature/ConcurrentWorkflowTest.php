<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestActivity;
use Tests\Fixtures\TestConcurrentWorkflow;
use Tests\Fixtures\TestOtherActivity;
use Tests\TestCase;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

final class ConcurrentWorkflowTest extends TestCase
{
    public function testCompleted(): void
    {
        $workflow = WorkflowStub::make(TestConcurrentWorkflow::class);

        $workflow->start();

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());
        $this->assertSame([TestActivity::class, TestOtherActivity::class], $workflow->logs()
            ->pluck('class')
            ->sort()
            ->values()
            ->toArray());
    }
}
