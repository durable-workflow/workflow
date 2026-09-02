<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestExceptionWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Base64;
use Workflow\Serializers\Serializer;
use Workflow\Serializers\Y;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

final class Base64WorkflowTest extends TestCase
{
    public function testBase64ToY(): void
    {
        config([
            'workflows.serializer' => Base64::class,
        ]);

        $workflow = WorkflowStub::make(TestExceptionWorkflow::class);

        $workflow->start();

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());

        config([
            'workflows.serializer' => Y::class,
        ]);

        if ($workflow->exceptions()->first()) {
            $this->assertSame(
                'failed',
                Serializer::unserialize($workflow->exceptions()->first()->exception)['message']
            );
        }
    }

    public function testYToBase64(): void
    {
        config([
            'workflows.serializer' => Y::class,
        ]);

        $workflow = WorkflowStub::make(TestExceptionWorkflow::class);

        $workflow->start();

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());

        config([
            'workflows.serializer' => Base64::class,
        ]);

        if ($workflow->exceptions()->first()) {
            $this->assertSame(
                'failed',
                Serializer::unserialize($workflow->exceptions()->first()->exception)['message']
            );
        }
    }
}
