<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\ChildWorkflowHandle;

final class WorkflowIdentitySurfaceTest extends TestCase
{
    public function testChildWorkflowHandleExposesWorkflowIdAlias(): void
    {
        $handle = new ChildWorkflowHandle(
            workflowInstanceId: 'child-workflow-001',
            workflowRunId: '01HRUN00000000000000000000',
            childCallId: '01HCALL0000000000000000000',
            commandDispatchEnabled: false,
        );

        $this->assertSame('child-workflow-001', $handle->instanceId());
        $this->assertSame('child-workflow-001', $handle->workflowId());
        $this->assertSame('01HRUN00000000000000000000', $handle->runId());
    }
}
