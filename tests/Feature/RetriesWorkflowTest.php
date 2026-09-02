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

        // Three failed activity attempts include one- and two-second queue
        // backoffs before Laravel marks the activity exhausted.
        $this->waitForWorkflow($workflow, timeoutSeconds: 15.0);

        $this->assertSame(4, $workflow->exceptions()->count());
    }
}
