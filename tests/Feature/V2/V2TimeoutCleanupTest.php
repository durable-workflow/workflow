<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestTimeoutCleanupWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

/**
 * Deadline-triggered cleanup end to end (#863, contract vectors 1-3, 11).
 */
final class V2TimeoutCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()
            ->set('queue.default', 'redis');
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function outcomes(): iterable
    {
        yield 'cleanup completes' => ['complete', 0, 'completed'];
        yield 'cleanup throws' => ['fail', 0, 'failed'];
        yield 'cleanup outlives the hard deadline' => ['expire', 11, 'expired'];
    }

    #[DataProvider('outcomes')]
    public function testAnIdleRunIsWokenAtItsWorkDeadlineAndEndsTimedOut(
        string $mode,
        int $extraSeconds,
        string $outcome,
    ): void {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestTimeoutCleanupWorkflow::class, 'timeout-cleanup-' . $mode);
        $workflow->start($mode, new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);
        $this->assertSame(RunStatus::Waiting, WorkflowRun::query()->findOrFail($runId)->status);

        // Past the work deadline, before the hard deadline: the watchdog wakes it.
        $this->travel(61)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        $this->runReadyWorkflowTask($runId);

        if ($extraSeconds > 0) {
            $this->assertFalse(WorkflowRun::query()->findOrFail($runId)->status->isTerminal());
            $this->travel($extraSeconds)
                ->seconds();
            TaskWatchdog::runPass(runIds: [$runId]);
            $this->runReadyWorkflowTaskIfAny($runId);
        }

        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('timed_out', $run->closed_reason);

        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderBy('sequence')->get();
        $started = $events->firstWhere('event_type', HistoryEventType::WorkflowTimeoutCleanupStarted);
        $this->assertNotNull($started);
        $this->assertSame('execution', $started->payload['timeout_kind']);
        $terminal = $events->last();
        $this->assertSame(HistoryEventType::WorkflowTimedOut, $terminal->event_type);
        $this->assertSame($outcome, $terminal->payload['timeout_cleanup']['outcome']);
        $this->assertSame('execution_timeout', $terminal->payload['timeout_kind']);
        $this->assertSame('timeout', $terminal->payload['failure_category']);
        if ($outcome === 'failed') {
            $this->assertIsString($terminal->payload['timeout_cleanup']['failure_id'] ?? null);
        }
    }

    public function testTerminatingDuringCleanupPreemptsIt(): void
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestTimeoutCleanupWorkflow::class, 'timeout-cleanup-preempted');
        $workflow->start('expire', new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);
        $this->travel(61)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        $this->runReadyWorkflowTask($runId);
        $this->assertFalse(WorkflowRun::query()->findOrFail($runId)->status->isTerminal());

        $workflow->refresh()
            ->terminate('operator stop');

        $terminal = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderByDesc(
            'sequence'
        )->firstOrFail();
        $this->assertSame(HistoryEventType::WorkflowTerminated, $terminal->event_type);
        $this->assertSame('preempted', $terminal->payload['timeout_cleanup']['outcome'] ?? null);
        $this->assertSame(RunStatus::Terminated, WorkflowRun::query()->findOrFail($runId)->status);
    }

    public function testWithoutTheOptionTheHardTimeoutIsUnchanged(): void
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestTimeoutCleanupWorkflow::class, 'timeout-cleanup-off');
        $workflow->start('complete', new StartOptions(executionTimeoutSeconds: 70));
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);

        $this->travel(61)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('status', TaskStatus::Ready->value)
            ->where('task_type', TaskType::Workflow->value)
            ->count());

        $this->travel(10)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        $this->runReadyWorkflowTaskIfAny($runId);

        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderBy('sequence')->get();
        $this->assertNull($events->firstWhere('event_type', HistoryEventType::WorkflowTimeoutCleanupStarted));
        $this->assertSame(HistoryEventType::WorkflowTimedOut, $events->last()->event_type);
        $this->assertArrayNotHasKey('timeout_cleanup', $events->last()->payload);
    }

    private function runReadyWorkflowTask(string $runId): void
    {
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function runReadyWorkflowTaskIfAny(string $runId): void
    {
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->first();
        if ($task !== null) {
            $this->app->call([new RunWorkflowTask($task->id), 'handle']);
        }
    }
}
