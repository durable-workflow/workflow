<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestAwaitWithTimeoutReplayWorkflow;
use Tests\Fixtures\TestAwaitWithTimeoutWorkflow;
use Tests\TestCase;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

final class AwaitWithTimeoutWorkflowTest extends TestCase
{
    public function testCompleted(): void
    {
        $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class);

        $startedAt = hrtime(true);

        $workflow->start(shouldTimeout: false);

        $this->waitForWorkflow($workflow);

        $this->assertLessThan(5, self::elapsedSeconds($startedAt));
        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow', $workflow->output());
    }

    public function testTimedout(): void
    {
        $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class);

        $startedAt = hrtime(true);

        $workflow->start(shouldTimeout: true);

        // The awaited predicate deliberately times out after five seconds.
        $this->waitForWorkflow($workflow, timeoutSeconds: 15.0);

        $this->assertGreaterThanOrEqual(5, self::elapsedSeconds($startedAt));
        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_timed_out', $workflow->output());
    }

    public function testTimedoutResultStaysFalseAfterReplay(): void
    {
        $workflow = WorkflowStub::make(TestAwaitWithTimeoutReplayWorkflow::class);

        $workflow->start();

        // Include the intentional one-second timeout and the replayed activity.
        $this->waitForWorkflow($workflow, timeoutSeconds: 10.0);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertFalse($workflow->output());
    }

    private static function elapsedSeconds(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000_000;
    }
}
