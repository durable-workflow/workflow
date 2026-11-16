<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestConcurrentWorkflow;
use Tests\TestCase;
use Workflow\WorkflowStub;

final class ExplicitQueueWorkerTest extends TestCase
{
    protected const START_QUEUE_WORKERS = false;

    public function testOptingOutOfAutomaticBootPreservesExplicitConcurrentWorkers(): void
    {
        $this->assertSame([], self::workerDiagnostics());
        self::restartQueueWorkers();
        $this->assertCount(self::NUMBER_OF_WORKERS, self::workerDiagnostics());
        $workflow = WorkflowStub::make(TestConcurrentWorkflow::class);
        $workflow->start();
        $this->waitForWorkflow($workflow);
        $this->assertSame('workflow_activity_other', $workflow->output());
        $this->assertSame(2, $workflow->logs()->count());
    }
}
