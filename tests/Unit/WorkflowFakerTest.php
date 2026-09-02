<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\Fixtures\TestActivity;
use Tests\Fixtures\TestChildWorkflow;
use Tests\Fixtures\TestConcurrentWorkflow;
use Tests\Fixtures\TestOtherActivity;
use Tests\Fixtures\TestParentWorkflow;
use Tests\Fixtures\TestTimeTravelWorkflow;
use Tests\TestCase;
use Workflow\WorkflowStub;

final class WorkflowFakerTest extends TestCase
{
    public function testTimeTravelWorkflow(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(TestActivity::class, 'activity');

        WorkflowStub::mock(TestOtherActivity::class, function ($context, $string) {
            $this->assertSame('other', $string);
            return 'other_activity';
        });

        WorkflowStub::assertNothingDispatched();

        $workflow = WorkflowStub::make(TestTimeTravelWorkflow::class);
        $workflow->start();

        WorkflowStub::assertDispatched(TestOtherActivity::class, 1);
        WorkflowStub::assertNotDispatched(TestActivity::class);

        $this->assertFalse($workflow->isCanceled());
        $this->assertNull($workflow->output());

        $workflow->cancel();

        $this->travel(5)
            ->minutes();

        $workflow->resume();

        $this->assertTrue($workflow->isCanceled());
        $this->assertSame($workflow->output(), 'workflow_activity_other_activity');

        WorkflowStub::assertDispatched(TestOtherActivity::class, static function ($string) {
            return $string === 'other';
        });

        WorkflowStub::assertDispatched(TestActivity::class);
    }

    public function testParentWorkflow(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(TestActivity::class, 'activity');

        WorkflowStub::mock(TestChildWorkflow::class, 'other_activity');

        $workflow = WorkflowStub::make(TestParentWorkflow::class);
        $workflow->start();

        WorkflowStub::assertDispatchedTimes(TestActivity::class, 1);
        WorkflowStub::assertDispatchedTimes(TestChildWorkflow::class, 1);

        $this->assertSame($workflow->output(), 'workflow_activity_other_activity');
    }

    public function testParentWorkflowWithCallableChildMock(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(TestActivity::class, 'activity');

        WorkflowStub::mock(TestChildWorkflow::class, static function ($context) {
            return 'other_activity';
        });

        $workflow = WorkflowStub::make(TestParentWorkflow::class);
        $workflow->start();

        WorkflowStub::assertDispatchedTimes(TestActivity::class, 1);
        WorkflowStub::assertDispatchedTimes(TestChildWorkflow::class, 1);

        $this->assertSame($workflow->output(), 'workflow_activity_other_activity');
    }

    public function testConcurrentWorkflow(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(TestActivity::class, 'activity');

        WorkflowStub::mock(TestOtherActivity::class, function ($context, $string) {
            $this->assertSame('other', $string);
            return 'other_activity';
        });

        $workflow = WorkflowStub::make(TestConcurrentWorkflow::class);
        $workflow->start();

        WorkflowStub::assertDispatched(TestActivity::class);
        WorkflowStub::assertDispatched(TestOtherActivity::class);

        $this->assertSame($workflow->output(), 'workflow_activity_other_activity');
    }
}
