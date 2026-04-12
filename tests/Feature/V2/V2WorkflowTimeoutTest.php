<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestHistoryBudgetWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

final class V2WorkflowTimeoutTest extends TestCase
{
    public function testStartWithExecutionTimeoutSnapsOnInstanceAndRun(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'timeout-exec-1');
        $workflow->start('Taylor', StartOptions::rejectDuplicate()->withExecutionTimeout(3600));

        $this->assertTrue($workflow->refresh()->completed());

        $instance = WorkflowInstance::query()->findOrFail('timeout-exec-1');
        $this->assertSame(3600, (int) $instance->execution_timeout_seconds);

        $run = WorkflowRun::query()->where('id', $workflow->runId())->firstOrFail();
        $this->assertNotNull($run->execution_deadline_at);
        $this->assertNull($run->run_timeout_seconds);
        $this->assertNull($run->run_deadline_at);
    }

    public function testStartWithRunTimeoutSnapsOnRun(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'timeout-run-1');
        $workflow->start('Taylor', StartOptions::rejectDuplicate()->withRunTimeout(1800));

        $this->assertTrue($workflow->refresh()->completed());

        $instance = WorkflowInstance::query()->findOrFail('timeout-run-1');
        $this->assertNull($instance->execution_timeout_seconds);

        $run = WorkflowRun::query()->where('id', $workflow->runId())->firstOrFail();
        $this->assertSame(1800, (int) $run->run_timeout_seconds);
        $this->assertNotNull($run->run_deadline_at);
        $this->assertNull($run->execution_deadline_at);
    }

    public function testStartWithBothTimeoutsSnapsBoth(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'timeout-both-1');
        $workflow->start(
            'Taylor',
            StartOptions::rejectDuplicate()
                ->withExecutionTimeout(7200)
                ->withRunTimeout(3600),
        );

        $this->assertTrue($workflow->refresh()->completed());

        $instance = WorkflowInstance::query()->findOrFail('timeout-both-1');
        $this->assertSame(7200, (int) $instance->execution_timeout_seconds);

        $run = WorkflowRun::query()->where('id', $workflow->runId())->firstOrFail();
        $this->assertSame(3600, (int) $run->run_timeout_seconds);
        $this->assertNotNull($run->execution_deadline_at);
        $this->assertNotNull($run->run_deadline_at);
    }

    public function testTimeoutFieldsAppearInWorkflowStartedHistory(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'timeout-history-1');
        $workflow->start(
            'Taylor',
            StartOptions::rejectDuplicate()
                ->withExecutionTimeout(3600)
                ->withRunTimeout(1800),
        );

        $this->assertTrue($workflow->refresh()->completed());

        $startedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();

        $payload = $startedEvent->payload;

        $this->assertSame(3600, $payload['execution_timeout_seconds']);
        $this->assertSame(1800, $payload['run_timeout_seconds']);
        $this->assertArrayHasKey('execution_deadline_at', $payload);
        $this->assertArrayHasKey('run_deadline_at', $payload);
    }

    public function testNoTimeoutFieldsWhenNotConfigured(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'timeout-none-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $instance = WorkflowInstance::query()->findOrFail('timeout-none-1');
        $this->assertNull($instance->execution_timeout_seconds);

        $run = WorkflowRun::query()->where('id', $workflow->runId())->firstOrFail();
        $this->assertNull($run->run_timeout_seconds);
        $this->assertNull($run->execution_deadline_at);
        $this->assertNull($run->run_deadline_at);
    }

    public function testStartOptionsValidatesTimeoutMinimum(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('at least 1 second');

        StartOptions::rejectDuplicate()->withExecutionTimeout(0);
    }

    public function testStartOptionsValidatesRunTimeoutMinimum(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('at least 1 second');

        StartOptions::rejectDuplicate()->withRunTimeout(-1);
    }

    public function testTimeoutBuildersChainingPreservesValues(): void
    {
        $options = StartOptions::rejectDuplicate()
            ->withBusinessKey('bk-1')
            ->withExecutionTimeout(3600)
            ->withRunTimeout(1800)
            ->withLabels([
                'env' => 'prod',
            ])
            ->withSearchAttributes([
                'status' => 'active',
            ]);

        $this->assertSame(3600, $options->executionTimeoutSeconds);
        $this->assertSame(1800, $options->runTimeoutSeconds);
        $this->assertSame('bk-1', $options->businessKey);
        $this->assertSame([
            'env' => 'prod',
        ], $options->labels);
        $this->assertSame([
            'status' => 'active',
        ], $options->searchAttributes);
    }

    public function testContinueAsNewCarriesForwardTimeouts(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestHistoryBudgetWorkflow::class, 'timeout-can-1');

        config()
            ->set('workflows.v2.history_budget.continue_as_new_event_threshold', 1);

        $workflow->start(
            0,
            1,
            StartOptions::rejectDuplicate()
                ->withExecutionTimeout(7200)
                ->withRunTimeout(3600),
        );

        $workflow->refresh();

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'timeout-can-1')
            ->orderBy('run_number')
            ->get();

        $this->assertGreaterThanOrEqual(2, $runs->count());

        $firstRun = $runs->first();
        $lastRun = $runs->last();

        $this->assertSame(3600, (int) $firstRun->run_timeout_seconds);
        $this->assertNotNull($firstRun->execution_deadline_at);
        $this->assertNotNull($firstRun->run_deadline_at);

        $this->assertSame(3600, (int) $lastRun->run_timeout_seconds);
        $this->assertNotNull($lastRun->execution_deadline_at);
        $this->assertNotNull($lastRun->run_deadline_at);

        // Execution deadline should be identical across runs (same instance-level timeout)
        $this->assertEquals(
            $firstRun->execution_deadline_at->toIso8601String(),
            $lastRun->execution_deadline_at->toIso8601String(),
        );

        // Run deadline should be different (reset for each new run)
        $this->assertNotEquals(
            $firstRun->run_deadline_at->toIso8601String(),
            $lastRun->run_deadline_at->toIso8601String(),
        );
    }

    public function testControlPlaneDescribeIncludesTimeoutFields(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'timeout-describe-1');
        $workflow->start(
            'Taylor',
            StartOptions::rejectDuplicate()
                ->withExecutionTimeout(3600)
                ->withRunTimeout(1800),
        );

        /** @var \Workflow\V2\Contracts\WorkflowControlPlane $controlPlane */
        $controlPlane = app(\Workflow\V2\Contracts\WorkflowControlPlane::class);
        $description = $controlPlane->describe('timeout-describe-1');

        $this->assertTrue($description['found']);
        $this->assertSame(3600, $description['execution_timeout_seconds']);
        $this->assertSame(1800, $description['run']['run_timeout_seconds']);
        $this->assertNotNull($description['run']['execution_deadline_at']);
        $this->assertNotNull($description['run']['run_deadline_at']);
    }

    public function testControlPlaneStartWithTimeouts(): void
    {
        WorkflowStub::fake();

        /** @var \Workflow\V2\Contracts\WorkflowControlPlane $controlPlane */
        $controlPlane = app(\Workflow\V2\Contracts\WorkflowControlPlane::class);

        $result = $controlPlane->start('tests.greeting-workflow', 'timeout-cp-1', [
            'execution_timeout_seconds' => 7200,
            'run_timeout_seconds' => 3600,
        ]);

        $this->assertTrue($result['started']);

        $instance = WorkflowInstance::query()->findOrFail('timeout-cp-1');
        $this->assertSame(7200, (int) $instance->execution_timeout_seconds);

        $run = WorkflowRun::query()->where('id', $result['workflow_run_id'])->firstOrFail();
        $this->assertSame(3600, (int) $run->run_timeout_seconds);
        $this->assertNotNull($run->execution_deadline_at);
        $this->assertNotNull($run->run_deadline_at);
    }
}
