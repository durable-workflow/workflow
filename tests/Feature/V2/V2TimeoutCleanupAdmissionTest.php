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

    private function started(string $runId): WorkflowHistoryEvent
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->sole();
    }
}
