<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestAwaitWithTimeoutWorkflow;
use Tests\Fixtures\V2\TestAwaitWorkflow;
use Tests\Fixtures\V2\TestKeyedAwaitWithTimeoutWorkflow;
use Tests\Fixtures\V2\TestKeyedAwaitWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\ConditionWaitDefinitionMismatchException;
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
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'redis');
    }

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

    public function testKeyedAwaitWorkflowRecordsConditionKeyAndResumesAfterUpdate(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestKeyedAwaitWorkflow::class, 'await-keyed-update');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertSame('condition', $workflow->refresh()->summary()?->wait_kind);
        $this->assertSame('Waiting for condition approval.ready', $workflow->summary()?->wait_reason);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));
        $conditionWait = $this->findConditionWait($detail['waits']);
        $conditionTimeline = collect($detail['timeline'])
            ->firstWhere('type', HistoryEventType::ConditionWaitOpened->value);

        $this->assertSame('approval.ready', $conditionWait['condition_key']);
        $this->assertSame('approval.ready', $conditionWait['target_name']);
        $this->assertSame('condition', $conditionWait['target_type']);
        $this->assertSame('approval.ready', $conditionTimeline['condition_key'] ?? null);
        $this->assertSame('Waiting for condition approval.ready.', $conditionTimeline['summary'] ?? null);

        /** @var WorkflowHistoryEvent $opened */
        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ConditionWaitOpened->value)
            ->firstOrFail();

        $this->assertSame('approval.ready', $opened->payload['condition_key'] ?? null);

        $update = $workflow->attemptUpdate('approve', true);

        $this->assertTrue($update->completed());
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame(['approved' => true], $workflow->output());

        /** @var WorkflowHistoryEvent $satisfied */
        $satisfied = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ConditionWaitSatisfied->value)
            ->firstOrFail();

        $this->assertSame('approval.ready', $satisfied->payload['condition_key'] ?? null);
    }

    public function testKeyedAwaitWithTimeoutCarriesConditionKeyIntoTimeoutTransport(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestKeyedAwaitWithTimeoutWorkflow::class, 'await-keyed-timeout');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));
        $conditionWait = $this->findConditionWait($detail['waits']);
        $timerTask = $this->findTask($detail['tasks'], 'timer');

        $this->assertSame('Waiting for condition approval.ready or timeout', $detail['wait_reason']);
        $this->assertSame('approval.ready', $conditionWait['condition_key']);
        $this->assertSame('approval.ready', $conditionWait['target_name']);
        $this->assertSame('approval.ready', $timerTask['condition_key']);
        $this->assertSame('approval.ready', $detail['timers'][0]['condition_key']);

        /** @var WorkflowHistoryEvent $timerScheduled */
        $timerScheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::TimerScheduled->value)
            ->firstOrFail();

        $this->assertSame('approval.ready', $timerScheduled->payload['condition_key'] ?? null);
    }

    public function testQueryReplayRejectsConditionWaitKeyDrift(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestKeyedAwaitWorkflow::class, 'await-keyed-query-drift');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        /** @var WorkflowHistoryEvent $opened */
        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ConditionWaitOpened->value)
            ->firstOrFail();

        $payload = $opened->payload;
        $payload['condition_key'] = 'approval.changed';
        $opened->forceFill(['payload' => $payload])->save();

        $this->expectException(ConditionWaitDefinitionMismatchException::class);
        $this->expectExceptionMessage('approval.changed');

        $workflow->refresh()->currentState();
    }

    public function testQueryReplayRejectsPreviouslyUnkeyedConditionWaitWhenCurrentYieldHasKey(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestKeyedAwaitWorkflow::class, 'await-unkeyed-query-drift');
        $workflow->start();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitOpened, [
            'condition_wait_id' => 'condition:1',
            'sequence' => 1,
        ]);

        $this->expectException(ConditionWaitDefinitionMismatchException::class);
        $this->expectExceptionMessage('recorded with condition key [none]');
        $this->expectExceptionMessage('current workflow yielded [approval.ready]');

        $workflow->refresh()->currentState();
    }

    public function testWorkflowWorkerBlocksReplayWhenRecordedConditionKeyDoesNotMatchCurrentYield(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestKeyedAwaitWorkflow::class, 'await-keyed-worker-drift');
        $workflow->start();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitOpened, [
            'condition_wait_id' => 'condition:1',
            'condition_key' => 'approval.changed',
            'sequence' => 1,
        ]);

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertFalse($workflow->refresh()->failed());
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => HistoryEventType::WorkflowFailed->value,
        ]);
        $this->assertDatabaseMissing('workflow_failures', [
            'workflow_run_id' => $workflow->runId(),
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertTrue($task->payload['replay_blocked'] ?? false);
        $this->assertSame('condition_wait_definition_mismatch', $task->payload['replay_blocked_reason'] ?? null);
        $this->assertSame('approval.changed', $task->payload['replay_blocked_recorded_condition_key'] ?? null);
        $this->assertSame('approval.ready', $task->payload['replay_blocked_current_condition_key'] ?? null);
        $this->assertStringContainsString('recorded with condition key [approval.changed]', (string) $task->last_error);
        $this->assertStringContainsString('current workflow yielded [approval.ready]', (string) $task->last_error);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));

        $this->assertSame('workflow_replay_blocked', $detail['liveness_state']);
        $this->assertStringContainsString('Run this workflow on a compatible build', $detail['liveness_reason']);
        $this->assertTrue($detail['can_repair']);
        $this->assertSame('replay_blocked', $detail['tasks'][0]['transport_state']);
        $this->assertTrue($detail['tasks'][0]['replay_blocked']);
        $this->assertSame('condition:1', $detail['tasks'][0]['replay_blocked_condition_wait_id']);
        $this->assertSame('approval.changed', $detail['tasks'][0]['replay_blocked_recorded_condition_key']);
        $this->assertSame('approval.ready', $detail['tasks'][0]['replay_blocked_current_condition_key']);

        $repair = $workflow->refresh()->attemptRepair();

        $this->assertTrue($repair->accepted());
        $this->assertSame('repair_dispatched', $repair->outcome());

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertFalse($task->payload['replay_blocked'] ?? false);
        $this->assertNull($task->last_error);
        $this->assertSame(1, $task->repair_count);
    }

    public function testWorkflowWorkerBlocksReplayWhenPreviouslyUnkeyedConditionWaitGainsCurrentKey(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestKeyedAwaitWorkflow::class, 'await-unkeyed-worker-drift');
        $workflow->start();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitOpened, [
            'condition_wait_id' => 'condition:1',
            'sequence' => 1,
        ]);

        $this->runReadyWorkflowTask($workflow->runId());

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertTrue($task->payload['replay_blocked'] ?? false);
        $this->assertSame('condition_wait_definition_mismatch', $task->payload['replay_blocked_reason'] ?? null);
        $this->assertNull($task->payload['replay_blocked_recorded_condition_key'] ?? null);
        $this->assertSame('approval.ready', $task->payload['replay_blocked_current_condition_key'] ?? null);
        $this->assertStringContainsString('recorded with condition key [none]', (string) $task->last_error);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));

        $this->assertSame('workflow_replay_blocked', $detail['liveness_state']);
        $this->assertStringContainsString(
            'recorded condition key [none] does not match the current yielded key [approval.ready]',
            $detail['liveness_reason'],
        );
        $this->assertSame('replay_blocked', $detail['tasks'][0]['transport_state']);
        $this->assertNull($detail['tasks'][0]['replay_blocked_recorded_condition_key']);
        $this->assertSame('approval.ready', $detail['tasks'][0]['replay_blocked_current_condition_key']);
    }

    public function testAwaitWorkflowCanApplySubmittedUpdateOnWorkflowWorker(): void
    {
        config()->set('queue.default', 'redis');

        Queue::fake();

        $workflow = WorkflowStub::make(TestAwaitWorkflow::class, 'await-submitted-update');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $update = $workflow->submitUpdate('approve', true);

        $this->assertTrue($update->accepted());
        $this->assertFalse($update->completed());
        $this->assertSame('accepted', $update->updateStatus());
        $this->assertSame('waiting', $workflow->refresh()->status());

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'approved' => true,
            'stage' => 'completed',
            'workflow_id' => 'await-submitted-update',
            'run_id' => $workflow->runId(),
        ], $workflow->output());

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
        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => HistoryEventType::TimerCancelled->value,
        ]);

        /** @var WorkflowHistoryEvent $timerCancelled */
        $timerCancelled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::TimerCancelled->value)
            ->firstOrFail();

        $this->assertSame($timer->id, $timerCancelled->payload['timer_id'] ?? null);
        $this->assertSame($timer->sequence, $timerCancelled->payload['sequence'] ?? null);
        $this->assertSame('condition_timeout', $timerCancelled->payload['timer_kind'] ?? null);
        $this->assertNotNull($timerCancelled->payload['condition_wait_id'] ?? null);
        $this->assertNotNull($timerCancelled->payload['cancelled_at'] ?? null);

        $timerId = $timer->id;
        $deadlineAt = $timer->fire_at?->toJSON();

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));
        $conditionWait = $this->findConditionWait($detail['waits']);

        $this->assertSame('resolved', $conditionWait['status']);
        $this->assertSame('satisfied', $conditionWait['source_status']);
        $this->assertSame('external_input', $conditionWait['resume_source_kind']);
        $this->assertNull($conditionWait['resume_source_id']);
        $this->assertNotNull($conditionWait['deadline_at']);

        $this->assertSame($timerId, $detail['timers'][0]['id']);
        $this->assertSame('cancelled', $detail['timers'][0]['status']);
        $this->assertSame($deadlineAt, $detail['timers'][0]['fire_at']?->toJSON());
        $this->assertNotNull($detail['timers'][0]['cancelled_at']);

        $timerTask->delete();
        $timer->delete();

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));

        $this->assertSame($timerId, $detail['timers'][0]['id']);
        $this->assertSame('cancelled', $detail['timers'][0]['status']);
        $this->assertSame('condition_timeout', $detail['timers'][0]['timer_kind']);
        $this->assertSame($timerCancelled->payload['condition_wait_id'], $detail['timers'][0]['condition_wait_id']);
        $this->assertSame($deadlineAt, $detail['timers'][0]['fire_at']?->toJSON());
        $this->assertNotNull($detail['timers'][0]['cancelled_at']);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ConditionWaitOpened',
            'TimerScheduled',
            'UpdateAccepted',
            'UpdateApplied',
            'UpdateCompleted',
            'TimerCancelled',
            'ConditionWaitSatisfied',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

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

    public function testAwaitWithTimeoutStillTimesOutWhenTimerRowIsRecoveredFromHistory(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-08 15:00:00'));

        try {
            $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'await-timeout-history-fire');
            $workflow->start();

            $this->runReadyWorkflowTask($workflow->runId());

            /** @var WorkflowTimer $timer */
            $timer = WorkflowTimer::query()
                ->where('workflow_run_id', $workflow->runId())
                ->firstOrFail();

            $timerId = $timer->id;

            $timer->delete();

            RunSummaryProjector::project(WorkflowRun::query()->findOrFail($workflow->runId()));

            Carbon::setTestNow(now()->addSeconds(5));

            $this->runReadyTimerTask($workflow->runId());
            $this->runReadyWorkflowTask($workflow->runId());

            /** @var WorkflowTimer $restoredTimer */
            $restoredTimer = WorkflowTimer::query()->findOrFail($timerId);

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame('fired', $restoredTimer->status->value);
            $this->assertNotNull($restoredTimer->fired_at);
            $this->assertSame([
                'approved' => false,
                'timed_out' => true,
                'stage' => 'timed-out',
                'workflow_id' => 'await-timeout-history-fire',
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

    public function testAwaitWithTimeoutAppliesFiredTimeoutHistoryWhenTimerRowAndResumeTaskDrift(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-08 15:30:00'));

        try {
            $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'await-timeout-fired-history');
            $workflow->start();

            $runId = $workflow->runId();

            $this->assertNotNull($runId);

            $this->runReadyWorkflowTask($runId);

            Carbon::setTestNow(now()->addSeconds(5));

            $this->runReadyTimerTask($runId);

            /** @var WorkflowTimer $timer */
            $timer = WorkflowTimer::query()
                ->where('workflow_run_id', $runId)
                ->firstOrFail();
            $timerId = $timer->id;

            /** @var WorkflowTask $resumeTask */
            $resumeTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Ready->value)
                ->sole();

            $resumeTask->delete();
            $timer->delete();

            RunSummaryProjector::project(WorkflowRun::query()->findOrFail($runId));

            $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));
            $conditionWait = $this->findConditionWait($detail['waits']);
            $missingWorkflowTask = collect($detail['tasks'])
                ->first(
                    static fn (array $task): bool => ($task['type'] ?? null) === 'workflow'
                        && ($task['transport_state'] ?? null) === 'missing'
                );

            $this->assertSame('condition', $detail['wait_kind']);
            $this->assertSame('Waiting to apply condition timeout', $detail['wait_reason']);
            $this->assertSame('repair_needed', $detail['liveness_state']);
            $this->assertSame('timeout_fired', $conditionWait['source_status']);
            $this->assertNotNull($conditionWait['timeout_fired_at']);
            $this->assertIsArray($missingWorkflowTask);
            $this->assertSame('missing', $missingWorkflowTask['status']);
            $this->assertSame('condition', $missingWorkflowTask['workflow_wait_kind']);
            $this->assertSame($timerId, $missingWorkflowTask['timer_id']);
            $this->assertStringContainsString('condition timeout', $missingWorkflowTask['summary']);

            $result = WorkflowStub::loadRun($runId)->attemptRepair();

            $this->assertTrue($result->accepted());
            $this->assertSame('repair_dispatched', $result->outcome());

            /** @var WorkflowTask $repairedTask */
            $repairedTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Ready->value)
                ->sole();

            $this->assertSame('condition', $repairedTask->payload['workflow_wait_kind'] ?? null);
            $this->assertSame('timer', $repairedTask->payload['resume_source_kind'] ?? null);
            $this->assertSame($timerId, $repairedTask->payload['resume_source_id'] ?? null);
            $this->assertSame($timerId, $repairedTask->payload['timer_id'] ?? null);
            $this->assertSame(1, $repairedTask->repair_count);

            Queue::assertPushed(
                RunWorkflowTask::class,
                static fn (RunWorkflowTask $job): bool => $job->taskId === $repairedTask->id,
            );

            $this->runReadyWorkflowTask($runId);

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame([
                'approved' => false,
                'timed_out' => true,
                'stage' => 'timed-out',
                'workflow_id' => 'await-timeout-fired-history',
                'run_id' => $runId,
            ], $workflow->output());
            $this->assertSame(1, WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::TimerScheduled->value)
                ->count());
            $this->assertSame(1, WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::TimerFired->value)
                ->count());
            $this->assertDatabaseHas('workflow_history_events', [
                'workflow_run_id' => $runId,
                'event_type' => HistoryEventType::ConditionWaitTimedOut->value,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAwaitWithTimeoutRepairRestoresMissingTimeoutTimerTransportFromHistory(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-08 16:00:00'));

        try {
            $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'await-timeout-history-repair');
            $workflow->start();

            $runId = $workflow->runId();

            $this->assertNotNull($runId);

            $this->runReadyWorkflowTask($runId);

            $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));
            $conditionWait = $this->findConditionWait($detail['waits']);
            $timerTask = $this->findTask($detail['tasks'], 'timer');
            $timerId = $timerTask['timer_id'] ?? null;
            $timerTaskId = $timerTask['id'] ?? null;
            $conditionWaitId = $conditionWait['condition_wait_id'] ?? null;
            $deadlineAt = $conditionWait['deadline_at']?->toJSON();

            $this->assertIsString($timerId);
            $this->assertIsString($timerTaskId);
            $this->assertIsString($conditionWaitId);
            $this->assertNotNull($deadlineAt);

            WorkflowTask::query()->whereKey($timerTaskId)->delete();
            WorkflowTimer::query()->whereKey($timerId)->delete();

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()->findOrFail($runId);
            $summary = RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            );

            $this->assertSame('condition', $summary->wait_kind);
            $this->assertSame('timer', $summary->resume_source_kind);
            $this->assertSame($timerId, $summary->resume_source_id);
            $this->assertSame('repair_needed', $summary->liveness_state);

            $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));
            $conditionWait = $this->findConditionWait($detail['waits']);

            $this->assertTrue($detail['can_repair']);
            $this->assertFalse($conditionWait['task_backed']);
            $this->assertSame($timerId, $conditionWait['resume_source_id']);
            $this->assertSame($deadlineAt, $conditionWait['deadline_at']?->toJSON());
            $missingTimerTask = $this->findTask($detail['tasks'], 'timer');

            $this->assertSame('missing', $missingTimerTask['status']);
            $this->assertSame('missing', $missingTimerTask['transport_state']);
            $this->assertTrue($missingTimerTask['task_missing']);
            $this->assertSame($timerId, $missingTimerTask['timer_id']);

            $result = WorkflowStub::loadRun($runId)->attemptRepair();

            $this->assertTrue($result->accepted());
            $this->assertSame('repair_dispatched', $result->outcome());

            /** @var WorkflowTimer $restoredTimer */
            $restoredTimer = WorkflowTimer::query()->findOrFail($timerId);
            /** @var WorkflowTask $repairedTask */
            $repairedTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Timer->value)
                ->where('status', TaskStatus::Ready->value)
                ->sole();

            $this->assertSame('pending', $restoredTimer->status->value);
            $this->assertSame($deadlineAt, $restoredTimer->fire_at?->toJSON());
            $this->assertSame([
                'timer_id' => $timerId,
                'condition_wait_id' => $conditionWaitId,
            ], $repairedTask->payload);
            $this->assertSame($deadlineAt, $repairedTask->available_at?->toJSON());
            $this->assertSame(1, $repairedTask->repair_count);

            Queue::assertPushed(
                RunTimerTask::class,
                static fn (RunTimerTask $job): bool => $job->taskId === $repairedTask->id,
            );

            Carbon::setTestNow(now()->addSeconds(5));

            $this->runReadyTimerTask($runId);
            $this->runReadyWorkflowTask($runId);

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame([
                'approved' => false,
                'timed_out' => true,
                'stage' => 'timed-out',
                'workflow_id' => 'await-timeout-history-repair',
                'run_id' => $runId,
            ], $workflow->output());
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

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<string, mixed>|null
     */
    private function findTaskOrNull(array $tasks, string $type): ?array
    {
        foreach ($tasks as $task) {
            if (($task['type'] ?? null) === $type) {
                return $task;
            }
        }

        return null;
    }
}
