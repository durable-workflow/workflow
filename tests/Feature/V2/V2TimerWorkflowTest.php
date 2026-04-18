<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\Fixtures\V2\TestMultipleTimerWorkflow;
use Tests\Fixtures\V2\TestPendingTimerSignalWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\WorkflowStub;

final class V2TimerWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');
    }

    public function testTimerSchedulingCreatesRowAndHistoryEvents(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'timer-lifecycle-1');
        $workflow->start(120);

        $this->assertSame('waiting', $workflow->refresh()->status());

        // Timer row should be created with Pending status.
        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $this->assertSame(TimerStatus::Pending, $timer->status);
        $this->assertSame(120, $timer->delay_seconds);
        $this->assertNotNull($timer->fire_at);

        // Timer task should be created and waiting for the future.
        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Timer->value)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Ready, $timerTask->status);
        $this->assertTrue($timerTask->available_at?->isFuture() ?? false);

        // TimerScheduled history event should be recorded.
        $scheduledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::TimerScheduled->value)
            ->firstOrFail();

        $this->assertSame($timer->id, $scheduledEvent->payload['timer_id']);
        $this->assertSame(120, $scheduledEvent->payload['delay_seconds']);
        $this->assertArrayHasKey('fire_at', $scheduledEvent->payload);

        // Timer should not yet have a fired event.
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => HistoryEventType::TimerFired->value,
        ]);

        // Advance time past the timer and drain.
        $this->travel(121)
            ->seconds();
        WorkflowStub::runReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());

        // Timer should now be Fired.
        $timer->refresh();
        $this->assertSame(TimerStatus::Fired, $timer->status);

        // TimerFired history event should be recorded.
        $firedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::TimerFired->value)
            ->firstOrFail();

        $this->assertSame($timer->id, $firedEvent->payload['timer_id']);
    }

    public function testZeroDurationTimerFiresInlineWithoutTimerTask(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'timer-zero-1');
        $workflow->start(0);

        // timer(0) should fire inline — workflow completes immediately.
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'waited' => true,
            'workflow_id' => 'timer-zero-1',
            'run_id' => $workflow->runId(),
        ], $workflow->output());

        // No separate timer task should exist (inline fire).
        $timerTaskCount = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Timer->value)
            ->count();

        $this->assertSame(0, $timerTaskCount);

        // History should still record TimerScheduled and TimerFired.
        $eventTypes = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all();

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'TimerScheduled',
            'TimerFired',
            'WorkflowCompleted',
        ], $eventTypes);
    }

    public function testTimerCancellationOnWorkflowCancel(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'timer-cancel-1');
        $workflow->start(300);

        // Workflow should be waiting on the timer.
        $this->assertSame('waiting', $workflow->refresh()->status());

        // Timer should be Pending.
        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $this->assertSame(TimerStatus::Pending, $timer->status);

        // Cancel the workflow.
        $result = $workflow->attemptCancel('test cancellation');
        $this->assertTrue($result->accepted());
        $this->assertSame('cancelled', $workflow->refresh()->status());

        // Timer should now be Cancelled.
        $timer->refresh();
        $this->assertSame(TimerStatus::Cancelled, $timer->status);

        // TimerCancelled history event should be recorded.
        $cancelledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::TimerCancelled->value)
            ->firstOrFail();

        $this->assertSame($timer->id, $cancelledEvent->payload['timer_id']);
        $this->assertArrayHasKey('cancelled_at', $cancelledEvent->payload);
    }

    public function testTimerCancellationOnWorkflowTerminate(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'timer-terminate-1');
        $workflow->start(600);

        $this->assertSame('waiting', $workflow->refresh()->status());

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $this->assertSame(TimerStatus::Pending, $timer->status);

        // Terminate the workflow.
        $result = $workflow->attemptTerminate('test termination');
        $this->assertTrue($result->accepted());
        $this->assertSame('terminated', $workflow->refresh()->status());

        // Timer should now be Cancelled.
        $timer->refresh();
        $this->assertSame(TimerStatus::Cancelled, $timer->status);

        // TimerCancelled history event should exist.
        $cancelledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::TimerCancelled->value)
            ->firstOrFail();

        $this->assertSame($timer->id, $cancelledEvent->payload['timer_id']);
    }

    public function testMultipleSequentialTimersProduceOrderedHistory(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestMultipleTimerWorkflow::class, 'timer-multi-1');
        $workflow->start(30, 60);

        $this->assertSame('waiting', $workflow->refresh()->status());

        // First timer should be pending.
        $timers = WorkflowTimer::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('created_at')
            ->get();

        $this->assertCount(1, $timers, 'Only the first timer should exist before it fires.');

        $firstTimer = $timers->first();
        $this->assertSame(TimerStatus::Pending, $firstTimer->status);
        $this->assertSame(30, $firstTimer->delay_seconds);

        // Advance past first timer and drain.
        $this->travel(31)
            ->seconds();
        WorkflowStub::runReadyTasks();

        // Second timer should now be pending. Workflow still waiting.
        $this->assertSame('waiting', $workflow->refresh()->status());

        $timers = WorkflowTimer::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $timers);

        $firstTimer = $timers->first();
        $secondTimer = $timers->last();
        $this->assertSame(TimerStatus::Fired, $firstTimer->status);
        $this->assertSame(TimerStatus::Pending, $secondTimer->status);
        $this->assertSame(60, $secondTimer->delay_seconds);

        // Advance past second timer and drain.
        $this->travel(61)
            ->seconds();
        WorkflowStub::runReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertTrue($workflow->output()['timers_completed']);

        // Both timers should be Fired.
        $secondTimer->refresh();
        $this->assertSame(TimerStatus::Fired, $secondTimer->status);

        // History should show ordered timer events.
        $timerEvents = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->whereIn('event_type', [HistoryEventType::TimerScheduled->value, HistoryEventType::TimerFired->value])
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all();

        $this->assertSame(['TimerScheduled', 'TimerFired', 'TimerScheduled', 'TimerFired'], $timerEvents);
    }

    public function testTimerRunDetailViewShowsTimerWaitsAndStatus(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'timer-detail-1');
        $workflow->start(90);

        $this->assertSame('waiting', $workflow->refresh()->status());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $run->loadMissing(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents']);

        $detail = RunDetailView::forRun($run);

        // Should have timer data in the detail view.
        $this->assertArrayHasKey('timers', $detail);
        $this->assertNotEmpty($detail['timers']);

        $timerDetail = $detail['timers'][0];
        $this->assertSame('pending', $timerDetail['status']);
        $this->assertSame(90, $timerDetail['delay_seconds']);
        $this->assertArrayHasKey('fire_at', $timerDetail);

        // Should have a timer wait in the waits list.
        $this->assertArrayHasKey('waits', $detail);
        $timerWaits = array_filter(
            $detail['waits'],
            static fn (array $wait): bool => ($wait['kind'] ?? null) === 'timer'
        );

        $this->assertNotEmpty($timerWaits, 'Run detail should include a timer wait.');

        $timerWait = array_values($timerWaits)[0];
        $this->assertSame('open', $timerWait['status']);
    }

    public function testTimerAndSignalInteractionWithQueryReplay(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestPendingTimerSignalWorkflow::class, 'timer-signal-1');
        $workflow->start(30);

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame('before-timer', $workflow->currentStage());
        $this->assertSame(['started'], $workflow->currentEvents());

        // Timer fires after time travel.
        $this->travel(31)
            ->seconds();
        WorkflowStub::runReadyTasks();

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame('after-timer', $workflow->currentStage());
        $this->assertSame(['started', 'timer-fired'], $workflow->currentEvents());

        // Signal resumes the workflow.
        $workflow->signal('resume', 'go');

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'stage' => 'completed',
            'events' => ['started', 'timer-fired', 'signal:go'],
        ], $workflow->output());

        // Verify timer is Fired.
        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $this->assertSame(TimerStatus::Fired, $timer->status);

        // Verify complete ordered history.
        $eventTypes = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all();

        $this->assertContains('TimerScheduled', $eventTypes);
        $this->assertContains('TimerFired', $eventTypes);
        $this->assertContains('SignalReceived', $eventTypes);
        $this->assertContains('WorkflowCompleted', $eventTypes);

        // Timer events should come before signal event in history.
        $timerFiredIndex = array_search('TimerFired', $eventTypes, true);
        $signalReceivedIndex = array_search('SignalReceived', $eventTypes, true);
        $this->assertLessThan(
            $signalReceivedIndex,
            $timerFiredIndex,
            'TimerFired should precede SignalReceived in history.'
        );
    }
}
