<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestActivity;
use Tests\Fixtures\TestOtherActivity;
use Tests\Fixtures\TestSignalExceptionWorkflow;
use Tests\Fixtures\TestSignalExceptionWorkflowLeader;
use Tests\Fixtures\TestWorkflow;
use Tests\TestCase;
use Workflow\Signal;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

final class WorkflowTest extends TestCase
{
    public function testCompleted(): void
    {
        $workflow = WorkflowStub::make(TestWorkflow::class);

        $workflow->start(shouldAssert: false);

        $workflow->cancel();

        while (! $workflow->isCanceled());

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());
        $this->assertSame([TestActivity::class, TestOtherActivity::class, Signal::class], $workflow->logs()
            ->pluck('class')
            ->sort()
            ->values()
            ->toArray());
    }

    public function testCompletedDelay(): void
    {
        $workflow = WorkflowStub::make(TestWorkflow::class);

        $workflow->start(shouldAssert: true);

        sleep(5);

        $workflow->cancel();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());
        $this->assertSame(
            [TestActivity::class, TestOtherActivity::class, TestWorkflow::class, Signal::class],
            $workflow->logs()
                ->pluck('class')
                ->sort()
                ->values()
                ->toArray()
        );
    }

    public function testTestSignalExceptionWorkflowEarly(): void
    {
        $workflow = WorkflowStub::make(TestSignalExceptionWorkflow::class);

        $workflow->start([
            'test' => 'data',
        ]);

        sleep(1);

        $workflow->shouldRetry();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertTrue($workflow->output());
    }

    public function testTestSignalExceptionWorkflowLate(): void
    {
        $workflow = WorkflowStub::make(TestSignalExceptionWorkflow::class);

        $workflow->start([
            'test' => 'data',
        ]);

        sleep(3);

        $workflow->shouldRetry();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertTrue($workflow->output());
    }

    public function testTestSignalExceptionWorkflowLeaderEarly(): void
    {
        $workflow = WorkflowStub::make(TestSignalExceptionWorkflowLeader::class);

        $workflow->start([
            'test' => 'data',
        ]);

        sleep(1);

        $workflow->shouldRetry();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertTrue($workflow->output());
    }

    public function testTestSignalExceptionWorkflowLeaderLate(): void
    {
        $workflow = WorkflowStub::make(TestSignalExceptionWorkflowLeader::class);

        $workflow->start([
            'test' => 'data',
        ]);

        sleep(3);

        $workflow->shouldRetry();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertTrue($workflow->output());
    }
}
