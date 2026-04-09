<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestAwaitWithTimeoutWorkflow;
use Tests\Fixtures\V2\TestAwaitWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\WorkflowStub;

final class V2AwaitWorkflowTest extends TestCase
{
    public function testAwaitWorkflowRecordsConditionWaitAndResumesAfterUpdate(): void
    {
        Queue::fake();
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 3);
        config()->set('workflows.v2.history_budget.continue_as_new_size_bytes_threshold', 1000000);

        $workflow = WorkflowStub::make(TestAwaitWorkflow::class, 'await-update');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame('condition', $workflow->summary()?->wait_kind);
        $this->assertSame('Waiting for condition', $workflow->summary()?->wait_reason);
        $this->assertSame(3, $workflow->summary()?->history_event_count);
        $this->assertGreaterThan(0, $workflow->summary()?->history_size_bytes);
        $this->assertTrue($workflow->summary()?->continue_as_new_recommended);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));
        $conditionWait = $this->findConditionWait($detail['waits']);

        $this->assertSame('condition', $detail['wait_kind']);
        $this->assertSame(3, $detail['history_event_count']);
        $this->assertGreaterThan(0, $detail['history_size_bytes']);
        $this->assertSame(3, $detail['history_event_threshold']);
        $this->assertSame(1000000, $detail['history_size_bytes_threshold']);
        $this->assertTrue($detail['continue_as_new_recommended']);
        $this->assertSame($conditionWait['condition_wait_id'], $detail['open_wait_id']);
        $this->assertSame('external_input', $detail['resume_source_kind']);
        $this->assertNull($detail['resume_source_id']);
        $this->assertSame('open', $conditionWait['status']);
        $this->assertSame('waiting', $conditionWait['source_status']);
        $this->assertTrue($conditionWait['external_only']);
        $this->assertFalse($conditionWait['task_backed']);
        $this->assertSame('Waiting for condition.', $conditionWait['summary']);

        $update = $workflow->attemptUpdate('approve', true);

        $this->assertTrue($update->accepted());
        $this->assertTrue($update->completed());

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'approved' => true,
            'stage' => 'completed',
            'workflow_id' => 'await-update',
            'run_id' => $workflow->runId(),
        ], $workflow->output());

        $this->assertSame(8, $workflow->summary()?->history_event_count);
        $this->assertTrue($workflow->summary()?->continue_as_new_recommended);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ConditionWaitOpened',
            'UpdateAccepted',
            'UpdateApplied',
            'UpdateCompleted',
            'ConditionWaitSatisfied',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testAwaitWorkflowUpdateRedispatchesExistingReadyWorkflowTaskBeforeWorkerRuns(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestAwaitWorkflow::class, 'await-update-before-worker');
        $workflow->start();

        /** @var WorkflowTask $initialTask */
        $initialTask = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        Queue::fake();

        $update = $workflow->attemptUpdate('approve', true);

        $this->assertTrue($update->accepted());
        $this->assertTrue($update->completed());
        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->count());

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $initialTask->id,
        );

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'approved' => true,
            'stage' => 'completed',
            'workflow_id' => 'await-update-before-worker',
            'run_id' => $workflow->runId(),
        ], $workflow->output());
    }

    public function testAwaitWithTimeoutProjectsConditionWaitAndTimesOut(): void
    {
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-08 12:00:00'));

        try {
            $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'await-timeout');
            $workflow->start();

            $this->runReadyWorkflowTask($workflow->runId());

            $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));
            $conditionWait = $this->findConditionWait($detail['waits']);
            $timerTask = $this->findTask($detail['tasks'], 'timer');

            $this->assertSame('condition', $detail['wait_kind']);
            $this->assertSame('Waiting for condition or timeout', $detail['wait_reason']);
            $this->assertSame('waiting_for_condition', $detail['liveness_state']);
            $this->assertSame('timer', $detail['resume_source_kind']);
            $this->assertNotNull($detail['resume_source_id']);
            $this->assertSame($conditionWait['condition_wait_id'], $detail['open_wait_id']);
            $this->assertSame('open', $conditionWait['status']);
            $this->assertTrue($conditionWait['external_only']);
            $this->assertTrue($conditionWait['task_backed']);
            $this->assertSame(5, $conditionWait['timeout_seconds']);
            $this->assertSame('timer', $conditionWait['resume_source_kind']);
            $this->assertNotNull($conditionWait['resume_source_id']);
            $this->assertSame($conditionWait['condition_wait_id'], $timerTask['condition_wait_id']);
            $this->assertSame(1, $timerTask['timer_sequence']);
            $this->assertStringContainsString('Condition timeout for 5 seconds', $timerTask['summary']);

            Carbon::setTestNow(now()->addSeconds(5));

            $this->runReadyTimerTask($workflow->runId());
            $this->runReadyWorkflowTask($workflow->runId());

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame([
                'approved' => false,
                'timed_out' => true,
                'stage' => 'timed-out',
                'workflow_id' => 'await-timeout',
                'run_id' => $workflow->runId(),
            ], $workflow->output());

            $this->assertSame([
                'StartAccepted',
                'WorkflowStarted',
                'ConditionWaitOpened',
                'TimerScheduled',
                'TimerFired',
                'ConditionWaitTimedOut',
                'WorkflowCompleted',
            ], WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $workflow->runId())
                ->orderBy('sequence')
                ->pluck('event_type')
                ->map(static fn ($eventType) => $eventType->value)
                ->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAwaitWithTimeoutCancelsTimerWhenUpdateWins(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'await-timeout-update');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $workflow->attemptUpdate('approve', true);
        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertTrue($workflow->refresh()->completed());

        $timer->refresh();

        /** @var WorkflowTask $timerTask */
        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Timer->value)
            ->firstOrFail();

        $this->assertSame('cancelled', $timer->status->value);
        $this->assertSame(TaskStatus::Cancelled->value, $timerTask->status->value);
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => HistoryEventType::ConditionWaitTimedOut->value,
        ]);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));
        $conditionWait = $this->findConditionWait($detail['waits']);

        $this->assertSame('resolved', $conditionWait['status']);
        $this->assertSame('satisfied', $conditionWait['source_status']);
        $this->assertSame('external_input', $conditionWait['resume_source_kind']);
        $this->assertNull($conditionWait['resume_source_id']);
        $this->assertNotNull($conditionWait['deadline_at']);

        $this->assertSame([
            'approved' => true,
            'timed_out' => false,
            'stage' => 'approved',
            'workflow_id' => 'await-timeout-update',
            'run_id' => $workflow->runId(),
        ], $workflow->output());
    }

    public function testAwaitWithTimeoutKeepsConditionWaitProjectionWhenTimerRowDrifts(): void
    {
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-08 13:00:00'));

        try {
            $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'await-timeout-drift');
            $workflow->start();

            $this->runReadyWorkflowTask($workflow->runId());

            /** @var WorkflowTimer $timer */
            $timer = WorkflowTimer::query()
                ->where('workflow_run_id', $workflow->runId())
                ->firstOrFail();

            $timerId = $timer->id;
            $deadlineAt = $timer->fire_at?->toJSON();

            $timer->delete();

            RunSummaryProjector::project(WorkflowRun::query()->findOrFail($workflow->runId()));

            $run = WorkflowRun::query()->with('summary')->findOrFail($workflow->runId());
            $detail = RunDetailView::forRun($run);
            $conditionWait = $this->findConditionWait($detail['waits']);
            $timerTask = $this->findTask($detail['tasks'], 'timer');

            $this->assertSame('condition', $detail['wait_kind']);
            $this->assertSame('Waiting for condition or timeout', $detail['wait_reason']);
            $this->assertSame('waiting_for_condition', $detail['liveness_state']);
            $this->assertSame('timer', $detail['resume_source_kind']);
            $this->assertSame($timerId, $detail['resume_source_id']);
            $this->assertSame($conditionWait['condition_wait_id'], $detail['open_wait_id']);
            $this->assertSame($deadlineAt, $run->summary?->wait_deadline_at?->toJSON());
            $this->assertSame('open', $conditionWait['status']);
            $this->assertSame('timer', $conditionWait['resume_source_kind']);
            $this->assertSame($timerId, $conditionWait['resume_source_id']);
            $this->assertSame($deadlineAt, $conditionWait['deadline_at']?->toJSON());
            $this->assertSame(5, $conditionWait['timeout_seconds']);
            $this->assertTrue($conditionWait['task_backed']);
            $this->assertSame($conditionWait['condition_wait_id'], $timerTask['condition_wait_id']);
            $this->assertSame($timerId, $timerTask['timer_id']);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function runReadyWorkflowTask(string $runId): void
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();

        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function runReadyTimerTask(string $runId): void
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Timer->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();

        $this->app->call([new RunTimerTask($task->id), 'handle']);
    }

    /**
     * @param list<array<string, mixed>> $waits
     * @return array<string, mixed>
     */
    private function findConditionWait(array $waits): array
    {
        foreach ($waits as $wait) {
            if (($wait['kind'] ?? null) === 'condition') {
                return $wait;
            }
        }

        $this->fail('Condition wait was not found.');
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<string, mixed>
     */
    private function findTask(array $tasks, string $type): array
    {
        foreach ($tasks as $task) {
            if (($task['type'] ?? null) === $type) {
                return $task;
            }
        }

        $this->fail(sprintf('Task of type [%s] was not found.', $type));
    }
}
