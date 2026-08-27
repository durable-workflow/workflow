<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestHeartbeatWorkflow;
use Tests\TestCase;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

final class HeartbeatWorkflowTest extends TestCase
{
    public function testHeartbeat(): void
    {
        $workflow = WorkflowStub::make(TestHeartbeatWorkflow::class);

        $workflow->start();

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());
    }
}
