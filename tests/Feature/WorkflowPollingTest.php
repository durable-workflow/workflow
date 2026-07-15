<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\TerminateQueueWorker;
use Tests\Fixtures\TestAwaitWorkflow;
use Tests\TestCase;
use Workflow\WorkflowStub;

final class WorkflowPollingTest extends TestCase
{
    public function testNonProgressingWorkflowFailsQuicklyWithDurableDiagnostics(): void
    {
        $workflow = WorkflowStub::make(TestAwaitWorkflow::class);
        $startedAt = hrtime(true);

        try {
            $this->waitForWorkflow(
                $workflow,
                static fn (WorkflowStub $workflow): bool => ! $workflow->running(),
                'a terminal state',
                0.05,
            );

            $this->fail('The non-progressing workflow unexpectedly reached a terminal state.');
        } catch (AssertionFailedError $failure) {
            $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

            $this->assertLessThan(10.0, $elapsedSeconds);
            $this->assertStringContainsString(
                'waiting for workflow to reach a terminal state',
                $failure->getMessage(),
            );
            $this->assertStringContainsString(
                '"workflow":{"id":"' . $workflow->id() . '"',
                $failure->getMessage(),
            );
            $this->assertStringContainsString('"run":{"id":"' . $workflow->id() . '"', $failure->getMessage());
            $this->assertStringContainsString('"status":"created"', $failure->getMessage());
            $this->assertStringContainsString('"task":', $failure->getMessage());
            $this->assertStringContainsString('"history":', $failure->getMessage());
        }
    }

    public function testDeadQueueWorkerFailsImmediatelyWithCapturedOutput(): void
    {
        $workflow = WorkflowStub::make(TestAwaitWorkflow::class);

        TerminateQueueWorker::dispatch()
            ->onConnection('redis')
            ->onQueue('default');

        try {
            $this->waitForWorkflow(
                $workflow,
                static fn (WorkflowStub $workflow): bool => ! $workflow->running(),
                'a terminal state',
                5.0,
            );

            $this->fail('The terminated queue worker was not detected.');
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString('Queue worker ', $failure->getMessage());
            $this->assertStringContainsString(' exited while waiting', $failure->getMessage());
            $this->assertStringContainsString('"exit_code":23', $failure->getMessage());
            $this->assertStringContainsString('intentional worker stdout probe', $failure->getMessage());
            $this->assertStringContainsString('intentional worker stderr probe', $failure->getMessage());
            $this->assertStringContainsString('"status":"created"', $failure->getMessage());
            $this->assertStringContainsString('"history":', $failure->getMessage());
        }
    }
}
