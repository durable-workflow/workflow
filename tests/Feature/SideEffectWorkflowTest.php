<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestActivity;
use Tests\Fixtures\TestOtherActivity;
use Tests\Fixtures\TestSideEffectWorkflow;
use Tests\TestCase;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

final class SideEffectWorkflowTest extends TestCase
{
    public function testCompleted(): void
    {
        $workflow = WorkflowStub::make(TestSideEffectWorkflow::class);

        $workflow->start();

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow', $workflow->output());
        $this->assertSame(
            [TestActivity::class, TestOtherActivity::class, TestOtherActivity::class, TestSideEffectWorkflow::class],
            $workflow->logs()
                ->pluck('class')
                ->sort()
                ->values()
                ->toArray()
        );
    }
}
