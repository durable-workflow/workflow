<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestAiWorkflow;
use Tests\TestCase;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowWaitingStatus;
use Workflow\WorkflowStub;

final class AiWorkflowTest extends TestCase
{
    public function testAiWorkflowConversation(): void
    {
        $workflow = WorkflowStub::make(TestAiWorkflow::class);
        $workflow->start();

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->status() === WorkflowWaitingStatus::class,
            'the AI workflow to await its first message',
        );

        $workflow->send('Hello');

        $message = null;
        $this->waitForWorkflow(
            $workflow,
            static function (WorkflowStub $workflow) use (&$message): bool {
                $message = $workflow->receive();

                return $message !== null;
            },
            'the first AI response to be available',
        );

        $this->assertSame('Echo: Hello', $message);

        $workflow->send('World');

        $message = null;
        $this->waitForWorkflow(
            $workflow,
            static function (WorkflowStub $workflow) use (&$message): bool {
                $message = $workflow->receive();

                return $message !== null;
            },
            'the second AI response to be available',
        );

        $this->assertSame('Echo: World', $message);

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => ! $workflow->running(),
            'a terminal state after the second response',
        );

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('completed', $workflow->output());
    }
}
