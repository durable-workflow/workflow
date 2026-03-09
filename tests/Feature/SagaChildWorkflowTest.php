<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestSagaChildWorkflow;
use Tests\Fixtures\TestSagaSingleChildWorkflow;
use Tests\TestCase;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

final class SagaChildWorkflowTest extends TestCase
{
    public function testSingleChildExceptionTriggersCompensation(): void
    {
        $workflow = WorkflowStub::make(TestSagaSingleChildWorkflow::class);

        $workflow->start();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('compensated', $workflow->output());
    }

    public function testParallelChildExceptionsTriggersCompensation(): void
    {
        $workflow = WorkflowStub::make(TestSagaChildWorkflow::class);

        $workflow->start();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('compensated', $workflow->output());
    }
}
