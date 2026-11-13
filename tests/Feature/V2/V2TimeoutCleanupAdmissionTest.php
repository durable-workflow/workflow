<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestContinueAsNewLargePayloadWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

/**
 * Timeout cleanup admission and inheritance (#863). The allowance is recorded
 * at start; the work deadline is derived from the hard deadlines and carried
 * across continue-as-new exactly as they are.
 */
final class V2TimeoutCleanupAdmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()
            ->set('queue.default', 'redis');
    }

    public function testStartRecordsTheAllowanceAndTheDerivedWorkDeadline(): void
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestContinueAsNewLargePayloadWorkflow::class, 'timeout-cleanup-admission');
        $workflow->start('x', 1, new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        $this->assertTrue(
            $run->timeout_cleanup_work_deadline_at->equalTo($run->execution_deadline_at->copy()->subSeconds(10))
        );
        $started = $this->started($run->id);
        $this->assertSame(10, $started->payload['timeout_cleanup']['allowance_seconds']);
        $this->assertSame(
            $run->timeout_cleanup_work_deadline_at->toIso8601String(),
            $started->payload['timeout_cleanup']['work_deadlines']['execution'],
        );
    }

    public function testAStartWithoutTheOptionRecordsNothing(): void
    {
        $workflow = WorkflowStub::make(TestContinueAsNewLargePayloadWorkflow::class, 'timeout-cleanup-none');
        $workflow->start('x', 1, new StartOptions(executionTimeoutSeconds: 70));
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        $this->assertNull($run->timeout_cleanup_work_deadline_at);
        $this->assertArrayNotHasKey('timeout_cleanup', $this->started($run->id)->payload);
    }

    public function testContinueAsNewKeepsTheExecutionWorkDeadline(): void
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestContinueAsNewLargePayloadWorkflow::class, 'timeout-cleanup-continue');
        $workflow->start('x', 0, new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $first = WorkflowRun::query()->findOrFail($workflow->runId());

        $this->travel(5)
            ->seconds();
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $first->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);

        $second = WorkflowRun::query()
            ->where('workflow_instance_id', $first->workflow_instance_id)
            ->where('run_number', 2)
            ->sole();
        $this->assertTrue($second->execution_deadline_at->equalTo($first->execution_deadline_at));
        $this->assertTrue($second->timeout_cleanup_work_deadline_at->equalTo($first->timeout_cleanup_work_deadline_at));
        $this->assertSame(10, $this->started($second->id)->payload['timeout_cleanup']['allowance_seconds']);
    }

    public function testContinueAsNewDerivesANewRunWorkDeadline(): void
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestContinueAsNewLargePayloadWorkflow::class, 'timeout-cleanup-continue-run');
        $workflow->start('x', 0, new StartOptions(runTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $first = WorkflowRun::query()->findOrFail($workflow->runId());
        $this->travel(30)
            ->seconds();
        $task = WorkflowTask::query()->where('workflow_run_id', $first->id)
            ->where('task_type', TaskType::Workflow->value)->where('status', TaskStatus::Ready->value)->sole();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
        $second = WorkflowRun::query()->where('workflow_instance_id', $first->workflow_instance_id)
            ->where('run_number', 2)
            ->sole();
        $this->assertNull($second->execution_deadline_at);
        $this->assertTrue($second->run_deadline_at->equalTo(now()->addSeconds(70)));
        $this->assertTrue($second->timeout_cleanup_work_deadline_at->equalTo(now()->addSeconds(60)));
        $this->assertSame(
            $second->timeout_cleanup_work_deadline_at->toIso8601String(),
            $this->started($second->id)
->payload['timeout_cleanup']['work_deadlines']['run']
        );
    }

    public function testPollModeRefusesTimeoutCleanupBeforeStartWrites(): void
    {
        $workflow = WorkflowStub::make(TestContinueAsNewLargePayloadWorkflow::class, 'timeout-cleanup-poll');
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('timeout cleanup is not supported by poll workers');
        try {
            $workflow->start('x', 1, new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        } finally {
            $this->assertSame(0, WorkflowRun::query()->count());
            $this->assertSame(0, \Workflow\V2\Models\WorkflowCommand::query()->count());
        }
    }

    public function testTheBridgeRefusesAnEmbeddedTimeoutCleanupRunWithoutTakingItsLease(): void
    {
        $workflow = WorkflowStub::make(TestContinueAsNewLargePayloadWorkflow::class, 'timeout-cleanup-bridge');
        $workflow->start('x', 1, new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $task = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())->sole();
        $bridge = $this->app->make(\Workflow\V2\Contracts\WorkflowTaskBridge::class);
        $result = $bridge->claimStatus($task->id, 'unsupported-worker');
        $this->assertFalse($result['claimed']);
        $this->assertSame('timeout_cleanup_not_supported', $result['reason']);
        $this->assertSame(TaskStatus::Ready, $task->refresh()->status);
        $this->assertNull($task->lease_owner);
    }

    public function testTheServiceStartRefusesTheOptionBeforeCreatingAnInstance(): void
    {
        $control = $this->app->make(\Workflow\V2\Contracts\WorkflowControlPlane::class);
        $result = $control->start('foreign-workflow', 'timeout-cleanup-service', [
            'execution_timeout_seconds' => 70,
            'timeout_cleanup_seconds' => 10,
        ]);
        $this->assertFalse($result['accepted']);
        $this->assertSame('timeout_cleanup_not_supported', $result['reason']);
        $this->assertSame(0, \Workflow\V2\Models\WorkflowInstance::query()->count());
    }

    private function started(string $runId): WorkflowHistoryEvent
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->sole();
    }
}
