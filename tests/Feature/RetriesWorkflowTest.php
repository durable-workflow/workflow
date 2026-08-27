<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestRetriesWorkflow;
use Tests\TestCase;
use Workflow\WorkflowStub;

final class RetriesWorkflowTest extends TestCase
{
    public function testRetries(): void
    {
        $workflow = WorkflowStub::make(TestRetriesWorkflow::class);

        $workflow->start();

        $this->waitForWorkflow($workflow, timeoutSeconds: 15.0);

        $this->assertSame(4, $workflow->exceptions()->count());
    }
}
