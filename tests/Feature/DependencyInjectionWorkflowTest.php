<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestDependencyInjectionWorkflow;
use Tests\TestCase;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

final class DependencyInjectionWorkflowTest extends TestCase
{
    public function testDependencyInjection(): void
    {
        $workflow = WorkflowStub::make(TestDependencyInjectionWorkflow::class);

        $workflow->start();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow-injected:activity-injected', $workflow->output());
    }
}
