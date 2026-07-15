<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestActivity;
use Tests\Fixtures\TestActivityAwaitActivityAwaitWorkflow;
use Tests\Fixtures\TestActivityThenAwaitWorkflow;
use Tests\Fixtures\TestActivityThrowsAwaitRetryWorkflow;
use Tests\Fixtures\TestChatBotAnswerActivity;
use Tests\Fixtures\TestChatBotAskActivity;
use Tests\Fixtures\TestChatBotWorkflow;
use Tests\Fixtures\TestMultipleAwaitsWorkflow;
use Tests\Fixtures\TestMultiStageApprovalWorkflow;
use Tests\Fixtures\TestPureAwaitWorkflow;
use Tests\Fixtures\TestRequestExecutiveApprovalActivity;
use Tests\Fixtures\TestRequestFinanceApprovalActivity;
use Tests\Fixtures\TestRequestLegalApprovalActivity;
use Tests\Fixtures\TestRequestManagerApprovalActivity;
use Tests\TestCase;
use Workflow\Exception;
use Workflow\Signal;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowWaitingStatus;
use Workflow\WorkflowStub;

final class SignalReplayTest extends TestCase
{
    public function testPureAwait(): void
    {
        $workflow = WorkflowStub::make(TestPureAwaitWorkflow::class);
        $workflow->start();

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->status() === WorkflowWaitingStatus::class,
            'the pure await workflow to begin waiting',
        );
        $workflow->approve(true);

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('approved', $workflow->output());
    }

    public function testActivityThenAwait(): void
    {
        $workflow = WorkflowStub::make(TestActivityThenAwaitWorkflow::class);
        $workflow->start();

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->logs()
                ->contains('class', TestActivity::class),
            'the activity before the approval await to finish',
        );
        $workflow->approve(true);

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('approved', $workflow->output());
    }

    public function testMultipleAwaitsWithDelays(): void
    {
        $workflow = WorkflowStub::make(TestMultipleAwaitsWorkflow::class);
        $workflow->start();

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->status() === WorkflowWaitingStatus::class,
            'the first approval await',
        );
        $workflow->approveFirst(true);
        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->logs()
                ->where('class', Signal::class)
                ->count() >= 1,
            'the first approval signal to be durable',
        );
        $workflow->approveSecond(true);

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('both_approved', $workflow->output());
    }

    public function testActivityAwaitActivityAwait(): void
    {
        $workflow = WorkflowStub::make(TestActivityAwaitActivityAwaitWorkflow::class);
        $workflow->start();

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->logs()
                ->where('class', TestActivity::class)
                ->count() >= 1,
            'the first activity to finish',
        );
        $workflow->approveFirst(true);
        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->logs()
                ->where('class', TestActivity::class)
                ->count() >= 2,
            'the second activity to finish',
        );
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

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->logs()
                ->contains('class', Exception::class),
            'the caught activity exception before retry',
        );
        $workflow->shouldRetry();

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertTrue($workflow->output());
    }

    public function testMultiStageApprovalPattern(): void
    {
        $workflow = WorkflowStub::make(TestMultiStageApprovalWorkflow::class);
        $workflow->start();

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->logs()
                ->contains('class', TestRequestManagerApprovalActivity::class),
            'the manager approval request',
        );
        $workflow->approveManager(true);
        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->logs()
                ->contains('class', TestRequestFinanceApprovalActivity::class)
                && $workflow->logs()
                    ->contains('class', TestRequestLegalApprovalActivity::class),
            'the finance and legal approval requests',
        );
        $workflow->approveFinance(true);
        $workflow->approveLegal(true);
        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->logs()
                ->contains('class', TestRequestExecutiveApprovalActivity::class),
            'the executive approval request',
        );
        $workflow->approveExecutive(true);

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('approved', $workflow->output());
    }

    public function testChatBotWorkflowWithInbox(): void
    {
        $workflow = WorkflowStub::make(TestChatBotWorkflow::class);
        $workflow->start();

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->logs()
                ->contains('class', TestChatBotAskActivity::class),
            'the first chatbot question',
        );
        $workflow->send('Unknown');

        $message = null;
        $this->waitForWorkflow(
            $workflow,
            static function (WorkflowStub $workflow) use (&$message): bool {
                $message = $workflow->receive();

                return $message !== null;
            },
            'the first chatbot response',
        );
        $this->assertSame('You said: Unknown', $message);

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->logs()
                ->where('class', TestChatBotAskActivity::class)
                ->count() >= 2
                && $workflow->logs()
                    ->contains('class', TestChatBotAnswerActivity::class),
            'the second chatbot question',
        );
        $workflow->send('User');

        $message = null;
        $this->waitForWorkflow(
            $workflow,
            static function (WorkflowStub $workflow) use (&$message): bool {
                $message = $workflow->receive();

                return $message !== null;
            },
            'the second chatbot response',
        );
        $this->assertSame('You said: User', $message);

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('completed', $workflow->output());
    }
}
