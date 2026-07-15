<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestCoroutineSendExceptionWorkflow;
use Tests\Fixtures\TestExceptionWorkflow;
use Tests\Fixtures\TestNonRetryableExceptionWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowFailedStatus;
use Workflow\WorkflowStub;

final class ExceptionWorkflowTest extends TestCase
{
    public function testRetry(): void
    {
        $workflow = WorkflowStub::make(TestExceptionWorkflow::class);

        $workflow->start();

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());
        if ($workflow->exceptions()->first()) {
            $this->assertSame(
                'failed',
                Serializer::unserialize($workflow->exceptions()->first()->exception)['message']
            );
        }
    }

    public function testNonRetryableException(): void
    {
        $workflow = WorkflowStub::make(TestNonRetryableExceptionWorkflow::class);

        $workflow->start();

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowFailedStatus::class, $workflow->status());
        $this->assertNotNull($workflow->exceptions()->first());
        $this->assertNull($workflow->output());
        $this->assertSame(
            'This is a non-retryable error',
            Serializer::unserialize($workflow->exceptions()->last()->exception)['message']
        );
    }

    public function testCoroutineSendException(): void
    {
        $workflow = WorkflowStub::make(TestCoroutineSendExceptionWorkflow::class);

        $workflow->start();

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowFailedStatus::class, $workflow->status());
        $this->assertNotNull($workflow->exceptions()->first());
        $this->assertNull($workflow->output());
        $this->assertSame(
            'exception test',
            Serializer::unserialize($workflow->exceptions()->first()->exception)['message']
        );
    }
}
