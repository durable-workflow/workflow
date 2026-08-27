<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestActivityAwaitActivityAwaitWorkflow;
use Tests\Fixtures\TestActivityThenAwaitWorkflow;
use Tests\Fixtures\TestActivityThrowsAwaitRetryWorkflow;
use Tests\Fixtures\TestChatBotWorkflow;
use Tests\Fixtures\TestMultipleAwaitsWorkflow;
use Tests\Fixtures\TestMultiStageApprovalWorkflow;
use Tests\Fixtures\TestPureAwaitWorkflow;
use Tests\TestCase;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

final class SignalReplayTest extends TestCase
{
    public function testPureAwait(): void
    {
        $workflow = WorkflowStub::make(TestPureAwaitWorkflow::class);
        $workflow->start();

        sleep(1);
        $workflow->approve(true);

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('approved', $workflow->output());
    }

    public function testActivityThenAwait(): void
    {
        $workflow = WorkflowStub::make(TestActivityThenAwaitWorkflow::class);
        $workflow->start();

        sleep(1);
        $workflow->approve(true);

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('approved', $workflow->output());
    }

    public function testMultipleAwaitsWithDelays(): void
    {
        $workflow = WorkflowStub::make(TestMultipleAwaitsWorkflow::class);
        $workflow->start();

        sleep(1);
        $workflow->approveFirst(true);
        sleep(1);
        $workflow->approveSecond(true);

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('both_approved', $workflow->output());
    }

    public function testActivityAwaitActivityAwait(): void
    {
        $workflow = WorkflowStub::make(TestActivityAwaitActivityAwaitWorkflow::class);
        $workflow->start();

        sleep(1);
        $workflow->approveFirst(true);
        sleep(1);
        $workflow->approveSecond(true);

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('completed', $workflow->output());
    }

    public function testSignalsSentBeforeProcessing(): void
    {
        $workflow = WorkflowStub::make(TestMultipleAwaitsWorkflow::class);

        $workflow->start();
        $workflow->approveFirst(true);
        $workflow->approveSecond(true);

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('both_approved', $workflow->output());
    }

    public function testActivityThrowsAwaitRetry(): void
    {
        $workflow = WorkflowStub::make(TestActivityThrowsAwaitRetryWorkflow::class);
        $workflow->start();

        sleep(3);
        $workflow->shouldRetry();

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertTrue($workflow->output());
    }

    public function testMultiStageApprovalPattern(): void
    {
        $workflow = WorkflowStub::make(TestMultiStageApprovalWorkflow::class);
        $workflow->start();

        sleep(1);
        $workflow->approveManager(true);
        sleep(1);
        $workflow->approveFinance(true);
        sleep(1);
        $workflow->approveLegal(true);
        sleep(1);
        $workflow->approveExecutive(true);

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('approved', $workflow->output());
    }

    public function testChatBotWorkflowWithInbox(): void
    {
        $workflow = WorkflowStub::make(TestChatBotWorkflow::class);
        $workflow->start();

        sleep(1);
        $workflow->send('Unknown');

        sleep(2);
        $message = $workflow->receive();
        $this->assertSame('You said: Unknown', $message);

        sleep(2);
        $workflow->send('User');

        sleep(2);
        $message = $workflow->receive();
        $this->assertSame('You said: User', $message);

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('completed', $workflow->output());
    }
}
