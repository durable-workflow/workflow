<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestStressParentWorkflow;
use Tests\TestCase;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

class RaceConditionTest extends TestCase
{
    public function testParentWorkflowWithParallelChildWorkflows(int $children = 100, int $actPerChild = 10): void
    {
        $runId = (int) now()
            ->format('Uu');

        $workflow = WorkflowStub::make(TestStressParentWorkflow::class);
        $workflow->start($runId, $children, $actPerChild);

        // This stress case deliberately dispatches 100 children and 1,000
        // activities, so retain its original two-minute completion budget.
        $this->waitForWorkflow($workflow, timeoutSeconds: 120.0);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame([
            'run_id' => $runId,
            'children' => $children,
            'activities_per_child' => $actPerChild,
        ], $workflow->output());
    }
}
