<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestAiWorkflow;
use Tests\TestCase;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

final class AiWorkflowTest extends TestCase
{
    public function testAiWorkflowConversation(): void
    {
        $workflow = WorkflowStub::make(TestAiWorkflow::class);
        $workflow->start();

        sleep(1);
        $workflow->send('Hello');

        $message = null;
        $attempts = 0;
        do {
            sleep(3);
            $message = $workflow->receive();
            $attempts++;
        } while ($message === null && $attempts < 10);

        $this->assertSame('Echo: Hello', $message);

        sleep(1);
        $workflow->send('World');

        $message = null;
        $attempts = 0;
        do {
            sleep(3);
            $message = $workflow->receive();
            $attempts++;
        } while ($message === null && $attempts < 10);

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
