<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestFailingWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowCreatedStatus;
use Workflow\States\WorkflowFailedStatus;
use Workflow\WorkflowStub;

final class FailingWorkflowTest extends TestCase
{
    public function testRetry(): void
    {
        $workflow = WorkflowStub::make(TestFailingWorkflow::class);

        $workflow->start(shouldFail: true);

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowFailedStatus::class, $workflow->status());
        $this->assertNull($workflow->output());

        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->status = WorkflowCreatedStatus::class;
        $storedWorkflow->save();

        $workflow->fresh()
            ->start(shouldFail: false);

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());
    }
}
