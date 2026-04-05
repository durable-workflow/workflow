<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use RuntimeException;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestFailingWorkflow;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\TestCase;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\WorkflowStub;

final class V2HistoryTimelineTest extends TestCase
{
    public function testTimelineIncludesTypedActivityEntriesForCompletedRun(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'timeline-greeting');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        /** @var ActivityExecution $activity */
        $activity = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();

        $timeline = HistoryTimeline::forRun($run);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ActivityScheduled',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], array_column($timeline, 'type'));

        $this->assertSame('command', $timeline[0]['kind']);
        $this->assertSame('Start accepted as started_new.', $timeline[0]['summary']);
        $this->assertNotNull($timeline[0]['recorded_at']);
        $this->assertSame($timeline[0]['command_id'], $timeline[0]['command']['id']);
        $this->assertSame('start', $timeline[0]['command_type']);
        $this->assertSame('accepted', $timeline[0]['command_status']);
        $this->assertSame('started_new', $timeline[0]['command_outcome']);
        $this->assertSame('start', $timeline[0]['command']['type']);
        $this->assertSame('accepted', $timeline[0]['command']['status']);
        $this->assertSame('started_new', $timeline[0]['command']['outcome']);
        $this->assertNull($timeline[0]['task']);

        $this->assertSame('activity', $timeline[2]['kind']);
        $this->assertSame('Scheduled TestGreetingActivity.', $timeline[2]['summary']);
        $this->assertSame($activity->id, $timeline[2]['activity_execution_id']);
        $this->assertSame(TestGreetingActivity::class, $timeline[2]['activity_type']);
        $this->assertSame(TestGreetingActivity::class, $timeline[2]['activity_class']);
        $this->assertSame('completed', $timeline[2]['activity_status']);
        $this->assertSame($activity->id, $timeline[2]['activity']['id']);
        $this->assertSame(1, $timeline[2]['activity']['sequence']);
        $this->assertSame(TestGreetingActivity::class, $timeline[2]['activity']['type']);
        $this->assertSame(TestGreetingActivity::class, $timeline[2]['activity']['class']);
        $this->assertSame('completed', $timeline[2]['activity']['status']);
        $this->assertSame('workflow', $timeline[2]['task']['type']);
        $this->assertSame('completed', $timeline[2]['task']['status']);

        $this->assertSame('Completed TestGreetingActivity.', $timeline[3]['summary']);
        $this->assertSame($activity->id, $timeline[3]['activity_execution_id']);
        $this->assertSame('activity', $timeline[3]['task']['type']);
        $this->assertSame('completed', $timeline[3]['task']['status']);
        $this->assertSame($activity->closed_at?->toJSON(), $timeline[3]['activity']['closed_at']);

        $this->assertSame('workflow', $timeline[4]['kind']);
        $this->assertSame('Workflow completed.', $timeline[4]['summary']);
        $this->assertNull($timeline[4]['command']);
        $this->assertNull($timeline[4]['failure']);
    }

    public function testTimelineIncludesTypedTimerEntriesForCompletedRun(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'timeline-timer');
        $workflow->start(1);
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();

        $timeline = HistoryTimeline::forRun($run);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'TimerScheduled',
            'TimerFired',
            'WorkflowCompleted',
        ], array_column($timeline, 'type'));

        $this->assertSame('timer', $timeline[2]['kind']);
        $this->assertSame('Scheduled timer for 1 second.', $timeline[2]['summary']);
        $this->assertSame($timer->id, $timeline[2]['timer_id']);
        $this->assertSame(1, $timeline[2]['delay_seconds']);
        $this->assertSame($timer->id, $timeline[2]['timer']['id']);
        $this->assertSame(1, $timeline[2]['timer']['sequence']);
        $this->assertSame('fired', $timeline[2]['timer']['status']);
        $this->assertSame(1, $timeline[2]['timer']['delay_seconds']);
        $this->assertSame($timer->fire_at?->toJSON(), $timeline[2]['timer']['fire_at']);
        $this->assertSame('workflow', $timeline[2]['task']['type']);
        $this->assertSame('completed', $timeline[2]['task']['status']);

        $this->assertSame('Timer fired after 1 second.', $timeline[3]['summary']);
        $this->assertSame($timer->id, $timeline[3]['timer_id']);
        $this->assertSame('timer', $timeline[3]['task']['type']);
        $this->assertSame('completed', $timeline[3]['task']['status']);
        $this->assertSame($timer->fired_at?->toJSON(), $timeline[3]['timer']['fired_at']);
    }

    public function testTimelineIncludesTypedFailureEntriesForFailedRun(): void
    {
        $workflow = WorkflowStub::make(TestFailingWorkflow::class, 'timeline-failure');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->failed());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        /** @var ActivityExecution $activity */
        $activity = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();
        /** @var WorkflowFailure $activityFailure */
        $activityFailure = WorkflowFailure::query()
            ->where('workflow_run_id', $runId)
            ->where('propagation_kind', 'activity')
            ->firstOrFail();
        /** @var WorkflowFailure $terminalFailure */
        $terminalFailure = WorkflowFailure::query()
            ->where('workflow_run_id', $runId)
            ->where('propagation_kind', 'terminal')
            ->firstOrFail();

        $timeline = HistoryTimeline::forRun($run);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ActivityScheduled',
            'ActivityFailed',
            'WorkflowFailed',
        ], array_column($timeline, 'type'));

        $this->assertSame('activity', $timeline[3]['kind']);
        $this->assertSame('Failed TestFailingActivity: boom.', $timeline[3]['summary']);
        $this->assertSame($activity->id, $timeline[3]['activity_execution_id']);
        $this->assertSame($activityFailure->id, $timeline[3]['failure_id']);
        $this->assertSame(RuntimeException::class, $timeline[3]['exception_class']);
        $this->assertSame('boom', $timeline[3]['message']);
        $this->assertSame($activityFailure->id, $timeline[3]['failure']['id']);
        $this->assertSame('activity_execution', $timeline[3]['failure']['source_kind']);
        $this->assertSame($activity->id, $timeline[3]['failure']['source_id']);
        $this->assertSame('activity', $timeline[3]['failure']['propagation_kind']);
        $this->assertTrue($timeline[3]['failure']['handled']);
        $this->assertSame(RuntimeException::class, $timeline[3]['failure']['exception_class']);
        $this->assertSame('boom', $timeline[3]['failure']['message']);
        $this->assertSame('activity', $timeline[3]['task']['type']);
        $this->assertSame('completed', $timeline[3]['task']['status']);
        $this->assertSame('failed', $timeline[3]['activity']['status']);

        $this->assertSame('workflow', $timeline[4]['kind']);
        $this->assertSame('Workflow failed: [RuntimeException] boom.', $timeline[4]['summary']);
        $this->assertSame($terminalFailure->id, $timeline[4]['failure_id']);
        $this->assertSame(RuntimeException::class, $timeline[4]['exception_class']);
        $this->assertSame('[RuntimeException] boom', $timeline[4]['message']);
        $this->assertSame($terminalFailure->id, $timeline[4]['failure']['id']);
        $this->assertSame('workflow_run', $timeline[4]['failure']['source_kind']);
        $this->assertSame($runId, $timeline[4]['failure']['source_id']);
        $this->assertSame('terminal', $timeline[4]['failure']['propagation_kind']);
        $this->assertFalse($timeline[4]['failure']['handled']);
        $this->assertSame(RuntimeException::class, $timeline[4]['failure']['exception_class']);
        $this->assertSame('[RuntimeException] boom', $timeline[4]['failure']['message']);
        $this->assertSame('workflow', $timeline[4]['task']['type']);
        $this->assertSame('failed', $timeline[4]['task']['status']);
        $this->assertNull($timeline[4]['activity']);
    }

    public function testTimelineIncludesTypedSignalEntriesForSignalDrivenRun(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'timeline-signal');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $workflow->signal('name-provided', 'Taylor');

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);

        $timeline = HistoryTimeline::forRun($run);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'SignalReceived',
            'SignalApplied',
            'ActivityScheduled',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], array_column($timeline, 'type'));

        $this->assertSame('signal', $timeline[2]['kind']);
        $this->assertSame('Waiting for signal name-provided.', $timeline[2]['summary']);
        $this->assertSame('name-provided', $timeline[2]['signal_name']);
        $this->assertSame('command', $timeline[3]['kind']);
        $this->assertSame('Signal name-provided received.', $timeline[3]['summary']);
        $this->assertSame('signal', $timeline[3]['command_type']);
        $this->assertSame('signal_received', $timeline[3]['command_outcome']);
        $this->assertSame('name-provided', $timeline[3]['command']['target_name']);
        $this->assertSame('signal', $timeline[4]['kind']);
        $this->assertSame('Applied signal name-provided.', $timeline[4]['summary']);
        $this->assertSame('name-provided', $timeline[4]['signal_name']);
        $this->assertSame('signal', $timeline[4]['command_type']);
    }

    private function waitFor(callable $condition): void
    {
        $deadline = microtime(true) + 20;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }

            usleep(100000);
        }

        $this->fail('Timed out waiting for workflow to settle.');
    }
}
