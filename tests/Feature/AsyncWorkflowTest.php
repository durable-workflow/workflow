<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestAsyncWorkflow;
use Tests\TestCase;
use Workflow\AsyncWorkflow;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

final class AsyncWorkflowTest extends TestCase
{
    public function testAsyncWorkflow(): void
    {
        $workflow = WorkflowStub::make(TestAsyncWorkflow::class);

        $workflow->start();

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());
        $this->assertSame([AsyncWorkflow::class], $workflow->logs()->pluck('class')->sort()->values()->toArray());
    }
}
