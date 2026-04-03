<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestProbeBackToBackWorkflow;
use Tests\Fixtures\TestProbeChildFailureCompensationActivity;
use Tests\Fixtures\TestProbeChildFailureParentStepActivity;
use Tests\Fixtures\TestProbeChildFailureParentWorkflow;
use Tests\Fixtures\TestProbeRetryActivity;
use Tests\Fixtures\TestProbeRetryWorkflow;
use Tests\TestCase;
use Workflow\Exception;
use Workflow\Signal;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

final class ExceptionLoggingReplayTest extends TestCase
{
    public function testSignalRetryLogsEachSequentialException(): void
    {
        $workflow = WorkflowStub::make(TestProbeRetryWorkflow::class);

        $workflow->start();

        sleep(1);
        $workflow->requestRetry();

        sleep(1);
        $workflow->requestRetry();

        while ($workflow->running());

        $classes = $workflow->logs()
            ->pluck('class')
            ->all();

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('success', $workflow->output());
        $this->assertSame([
            Exception::class,
            Signal::class,
            Exception::class,
            Signal::class,
            TestProbeRetryActivity::class,
        ], $classes);
    }

    public function testBackToBackCaughtExceptionsEachPersist(): void
    {
        $workflow = WorkflowStub::make(TestProbeBackToBackWorkflow::class);

        $workflow->start();

        while ($workflow->running());

        $classes = $workflow->logs()
            ->pluck('class')
            ->all();

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('caught second: second failure', $workflow->output());
        $this->assertSame([Exception::class, Exception::class], $classes);
    }

    public function testParallelChildFailuresStillDeduplicateToOneParentException(): void
    {
        $workflow = WorkflowStub::make(TestProbeChildFailureParentWorkflow::class);

        $workflow->start();

        while ($workflow->running());

        $classes = $workflow->logs()
            ->pluck('class')
            ->all();

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('caught: child failed: child-1', $workflow->output());
        $this->assertSame([
            TestProbeChildFailureParentStepActivity::class,
            Exception::class,
            TestProbeChildFailureCompensationActivity::class,
        ], $classes);
        $this->assertSame(1, $workflow->logs()->where('class', Exception::class)->count());
    }
}
