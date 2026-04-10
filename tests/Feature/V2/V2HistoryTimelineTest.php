<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fixtures\V2\TestChildGreetingWorkflow;
use Tests\Fixtures\V2\TestFailingWorkflow;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestHeartbeatWorkflow;
use Tests\Fixtures\V2\TestNestedParallelActivityWorkflow;
use Tests\Fixtures\V2\TestParentChildWorkflow;
use Tests\Fixtures\V2\TestSideEffectWorkflow;
use Tests\Fixtures\V2\TestSignalOrderingWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\WorkflowStub;

final class V2HistoryTimelineTest extends TestCase
{
    protected function tearDown(): void
    {
        TestSideEffectWorkflow::resetCounter();

        parent::tearDown();
    }

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
            'ActivityStarted',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], array_column($timeline, 'type'));

        $this->assertSame('command', $timeline[0]['kind']);
        $this->assertSame('point', $timeline[0]['entry_kind']);
        $this->assertSame('workflow_command', $timeline[0]['source_kind']);
        $this->assertSame('Start accepted as started_new.', $timeline[0]['summary']);
        $this->assertNotNull($timeline[0]['recorded_at']);
        $this->assertSame($timeline[0]['command_id'], $timeline[0]['command']['id']);
        $this->assertSame($timeline[0]['command_id'], $timeline[0]['source_id']);
        $this->assertSame('start', $timeline[0]['command_type']);
        $this->assertSame('accepted', $timeline[0]['command_status']);
        $this->assertSame('started_new', $timeline[0]['command_outcome']);
        $this->assertSame('start', $timeline[0]['command']['type']);
        $this->assertSame('accepted', $timeline[0]['command']['status']);
        $this->assertSame('started_new', $timeline[0]['command']['outcome']);
        $this->assertNull($timeline[0]['command']['requested_run_id']);
        $this->assertSame($runId, $timeline[0]['command']['resolved_run_id']);
        $this->assertNull($timeline[0]['task']);

        $this->assertSame('activity', $timeline[2]['kind']);
        $this->assertSame('activity_execution', $timeline[2]['source_kind']);
        $this->assertSame($activity->id, $timeline[2]['source_id']);
        $this->assertSame('Scheduled TestGreetingActivity.', $timeline[2]['summary']);
        $this->assertSame($activity->id, $timeline[2]['activity_execution_id']);
        $this->assertSame(TestGreetingActivity::class, $timeline[2]['activity_type']);
        $this->assertSame(TestGreetingActivity::class, $timeline[2]['activity_class']);
        $this->assertSame('pending', $timeline[2]['activity_status']);
        $this->assertSame($activity->id, $timeline[2]['activity']['id']);
        $this->assertSame(1, $timeline[2]['activity']['sequence']);
        $this->assertSame(TestGreetingActivity::class, $timeline[2]['activity']['type']);
        $this->assertSame(TestGreetingActivity::class, $timeline[2]['activity']['class']);
        $this->assertNull($timeline[2]['activity']['attempt_id']);
        $this->assertSame(0, $timeline[2]['activity']['attempt_count']);
        $this->assertSame('pending', $timeline[2]['activity']['status']);
        $this->assertNull($timeline[2]['activity']['started_at']);
        $this->assertNull($timeline[2]['activity']['closed_at']);
        $this->assertSame('workflow', $timeline[2]['task']['type']);
        $this->assertSame('completed', $timeline[2]['task']['status']);

        $this->assertSame('Started TestGreetingActivity.', $timeline[3]['summary']);
        $this->assertSame($activity->id, $timeline[3]['activity_execution_id']);
        $this->assertSame('activity', $timeline[3]['task']['type']);
        $this->assertSame('leased', $timeline[3]['task']['status']);
        $this->assertSame($activity->current_attempt_id, $timeline[3]['activity']['attempt_id']);
        $this->assertSame(1, $timeline[3]['activity']['attempt_count']);
        $this->assertSame('running', $timeline[3]['activity']['status']);
        $this->assertSame($activity->started_at?->toJSON(), $timeline[3]['activity']['started_at']);
        $this->assertNull($timeline[3]['activity']['closed_at']);

        $this->assertSame('Completed TestGreetingActivity.', $timeline[4]['summary']);
        $this->assertSame($activity->id, $timeline[4]['activity_execution_id']);
        $this->assertSame('activity', $timeline[4]['task']['type']);
        $this->assertSame('completed', $timeline[4]['task']['status']);
        $this->assertSame($activity->current_attempt_id, $timeline[4]['activity']['attempt_id']);
        $this->assertSame(1, $timeline[4]['activity']['attempt_count']);
        $this->assertSame($activity->closed_at?->toJSON(), $timeline[4]['activity']['closed_at']);

        $this->assertSame('workflow', $timeline[5]['kind']);
        $this->assertSame('Workflow completed.', $timeline[5]['summary']);
        $this->assertNull($timeline[5]['command']);
        $this->assertNull($timeline[5]['failure']);
    }

    public function testTimelineIncludesTypedActivityHeartbeatEntries(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestHeartbeatWorkflow::class, 'timeline-heartbeat');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->drainReadyTasks();
        $workflow->refresh();

        $this->assertTrue($workflow->completed());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        /** @var ActivityExecution $activity */
        $activity = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();

        $timeline = collect(HistoryTimeline::forRun($run));
        $heartbeat = $timeline->firstWhere('type', 'ActivityHeartbeatRecorded');

        $this->assertIsArray($heartbeat);
        $this->assertSame('activity', $heartbeat['kind']);
        $this->assertSame('activity_execution', $heartbeat['source_kind']);
        $this->assertSame($activity->id, $heartbeat['source_id']);
        $this->assertSame('Recorded heartbeat for TestHeartbeatActivity.', $heartbeat['summary']);
        $this->assertSame($activity->id, $heartbeat['activity_execution_id']);
        $this->assertSame('running', $heartbeat['activity_status']);
        $this->assertSame('activity', $heartbeat['task']['type']);
        $this->assertSame('leased', $heartbeat['task']['status']);
        $this->assertSame($activity->current_attempt_id, $heartbeat['activity']['attempt_id']);
        $this->assertSame(1, $heartbeat['activity']['attempt_count']);
        $this->assertSame($activity->last_heartbeat_at?->toJSON(), $heartbeat['activity']['last_heartbeat_at']);
    }

    public function testTimelineIncludesNestedParallelActivityPathMetadata(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestNestedParallelActivityWorkflow::class, 'timeline-nested-parallel');
        $workflow->start('Taylor', 'Abigail', 'Selena');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runNextReadyTask();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        $timeline = collect(HistoryTimeline::forRun($run))
            ->where('type', 'ActivityScheduled')
            ->values();

        $this->assertCount(3, $timeline);

        $this->assertSame('parallel-activities:1:3', $timeline[0]['parallel_group_id']);
        $this->assertSame([
            [
                'parallel_group_id' => 'parallel-activities:1:3',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 3,
                'parallel_group_index' => 0,
            ],
        ], $timeline[0]['parallel_group_path']);

        $this->assertSame('parallel-activities:2:2', $timeline[1]['parallel_group_id']);
        $this->assertSame([
            [
                'parallel_group_id' => 'parallel-activities:1:3',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 3,
                'parallel_group_index' => 1,
            ],
            [
                'parallel_group_id' => 'parallel-activities:2:2',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 2,
                'parallel_group_size' => 2,
                'parallel_group_index' => 0,
            ],
        ], $timeline[1]['parallel_group_path']);

        $this->assertSame('parallel-activities:2:2', $timeline[2]['parallel_group_id']);
        $this->assertSame([
            [
                'parallel_group_id' => 'parallel-activities:1:3',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 3,
                'parallel_group_index' => 2,
            ],
            [
                'parallel_group_id' => 'parallel-activities:2:2',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 2,
                'parallel_group_size' => 2,
                'parallel_group_index' => 1,
            ],
        ], $timeline[2]['parallel_group_path']);
    }

    public function testTimelineIncludesTypedTimerEntriesForCompletedRun(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'timeline-timer');
        $workflow->start(5);
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->drainReadyTasks();

        /** @var WorkflowTask $timerTask */
        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Timer->value)
            ->firstOrFail();

        if ($timerTask->status === TaskStatus::Ready) {
            $timerTask->forceFill([
                'available_at' => now()
                    ->subSecond(),
            ])->save();

            $this->drainReadyTasks();
        }

        $this->assertTrue($workflow->refresh()->completed());

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
        $this->assertSame('timer', $timeline[2]['source_kind']);
        $this->assertSame($timer->id, $timeline[2]['source_id']);
        $this->assertSame('Scheduled timer for 5 seconds.', $timeline[2]['summary']);
        $this->assertSame($timer->id, $timeline[2]['timer_id']);
        $this->assertSame(5, $timeline[2]['delay_seconds']);
        $this->assertSame($timer->id, $timeline[2]['timer']['id']);
        $this->assertSame(1, $timeline[2]['timer']['sequence']);
        $this->assertSame('pending', $timeline[2]['timer']['status']);
        $this->assertSame(5, $timeline[2]['timer']['delay_seconds']);
        $this->assertSame($timer->fire_at?->toJSON(), $timeline[2]['timer']['fire_at']);
        $this->assertNull($timeline[2]['timer']['fired_at']);
        $this->assertSame('workflow', $timeline[2]['task']['type']);
        $this->assertSame('completed', $timeline[2]['task']['status']);

        $this->assertSame('Timer fired after 5 seconds.', $timeline[3]['summary']);
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
            'ActivityStarted',
            'ActivityFailed',
            'WorkflowFailed',
        ], array_column($timeline, 'type'));

        $this->assertSame('activity', $timeline[4]['kind']);
        $this->assertSame('activity_execution', $timeline[4]['source_kind']);
        $this->assertSame($activity->id, $timeline[4]['source_id']);
        $this->assertSame('Failed TestFailingActivity: boom.', $timeline[4]['summary']);
        $this->assertSame($activity->id, $timeline[4]['activity_execution_id']);
        $this->assertSame($activityFailure->id, $timeline[4]['failure_id']);
        $this->assertSame(RuntimeException::class, $timeline[4]['exception_class']);
        $this->assertSame('boom', $timeline[4]['message']);
        $this->assertSame($activityFailure->id, $timeline[4]['failure']['id']);
        $this->assertSame('activity_execution', $timeline[4]['failure']['source_kind']);
        $this->assertSame($activity->id, $timeline[4]['failure']['source_id']);
        $this->assertSame('activity', $timeline[4]['failure']['propagation_kind']);
        $this->assertFalse($timeline[4]['failure']['handled']);
        $this->assertSame(RuntimeException::class, $timeline[4]['failure']['exception_class']);
        $this->assertSame('boom', $timeline[4]['failure']['message']);
        $this->assertSame('activity', $timeline[4]['task']['type']);
        $this->assertSame('completed', $timeline[4]['task']['status']);
        $this->assertSame('failed', $timeline[4]['activity']['status']);

        $this->assertSame('workflow', $timeline[5]['kind']);
        $this->assertSame('workflow_run', $timeline[5]['source_kind']);
        $this->assertSame($runId, $timeline[5]['source_id']);
        $this->assertSame('Workflow failed: boom.', $timeline[5]['summary']);
        $this->assertSame($terminalFailure->id, $timeline[5]['failure_id']);
        $this->assertSame(RuntimeException::class, $timeline[5]['exception_class']);
        $this->assertSame('boom', $timeline[5]['message']);
        $this->assertSame($terminalFailure->id, $timeline[5]['failure']['id']);
        $this->assertSame('workflow_run', $timeline[5]['failure']['source_kind']);
        $this->assertSame($runId, $timeline[5]['failure']['source_id']);
        $this->assertSame('terminal', $timeline[5]['failure']['propagation_kind']);
        $this->assertFalse($timeline[5]['failure']['handled']);
        $this->assertSame(RuntimeException::class, $timeline[5]['failure']['exception_class']);
        $this->assertSame('boom', $timeline[5]['failure']['message']);
        $this->assertSame('workflow', $timeline[5]['task']['type']);
        $this->assertSame('failed', $timeline[5]['task']['status']);
        $this->assertNull($timeline[5]['activity']);
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
            'ActivityStarted',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], array_column($timeline, 'type'));

        $this->assertSame('signal', $timeline[2]['kind']);
        $this->assertSame('signal_wait', $timeline[2]['source_kind']);
        $this->assertSame('Waiting for signal name-provided.', $timeline[2]['summary']);
        $this->assertSame('name-provided', $timeline[2]['signal_name']);
        $this->assertSame('command', $timeline[3]['kind']);
        $this->assertSame('workflow_command', $timeline[3]['source_kind']);
        $this->assertSame('Signal name-provided received.', $timeline[3]['summary']);
        $this->assertSame(2, $timeline[3]['command_sequence']);
        $this->assertSame('signal', $timeline[3]['command_type']);
        $this->assertSame('signal_received', $timeline[3]['command_outcome']);
        $this->assertSame(2, $timeline[3]['command']['sequence']);
        $this->assertSame('name-provided', $timeline[3]['command']['target_name']);
        $this->assertSame('signal', $timeline[4]['kind']);
        $this->assertSame('signal_wait', $timeline[4]['source_kind']);
        $this->assertSame('Applied signal name-provided.', $timeline[4]['summary']);
        $this->assertSame('name-provided', $timeline[4]['signal_name']);
        $this->assertSame(2, $timeline[4]['command_sequence']);
        $this->assertSame('signal', $timeline[4]['command_type']);
        $this->assertSame($timeline[2]['signal_wait_id'], $timeline[2]['source_id']);
        $this->assertSame($timeline[2]['signal_wait_id'], $timeline[4]['source_id']);
    }

    public function testTimelineExposesSignalWaitIdentityForRepeatedSameNamedSignals(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalOrderingWorkflow::class, 'timeline-signal-order');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->drainReadyTasks();
        $workflow->refresh();

        $workflow->signal('message', 'first');
        $workflow->signal('message', 'second');

        $this->drainReadyTasks();
        $workflow->refresh();

        $this->assertTrue($workflow->completed());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);

        $timeline = HistoryTimeline::forRun($run);
        $opened = array_values(array_filter(
            $timeline,
            static fn (array $entry): bool => ($entry['type'] ?? null) === 'SignalWaitOpened',
        ));
        $received = array_values(array_filter(
            $timeline,
            static fn (array $entry): bool => ($entry['type'] ?? null) === 'SignalReceived',
        ));
        $applied = array_values(array_filter(
            $timeline,
            static fn (array $entry): bool => ($entry['type'] ?? null) === 'SignalApplied',
        ));

        $this->assertCount(2, $opened);
        $this->assertCount(2, $received);
        $this->assertCount(2, $applied);
        $this->assertSame([1, 2], array_column($opened, 'workflow_sequence'));
        $this->assertSame([1, 2], array_column($applied, 'workflow_sequence'));
        $this->assertSame(array_column($opened, 'signal_wait_id'), array_column($received, 'signal_wait_id'));
        $this->assertSame(array_column($opened, 'signal_wait_id'), array_column($applied, 'signal_wait_id'));
        $this->assertNotSame($opened[0]['signal_wait_id'], $opened[1]['signal_wait_id']);
    }

    public function testTimelineKeepsCommandAndTaskSnapshotsWhenRowsDrift(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'timeline-history-snapshots');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $workflow->signal('name-provided', 'Taylor');

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        /** @var WorkflowCommand $signalCommand */
        $signalCommand = WorkflowCommand::query()
            ->where('workflow_run_id', $runId)
            ->where('command_type', 'signal')
            ->firstOrFail();
        /** @var WorkflowTask $activityTask */
        $activityTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->firstOrFail();

        $originalCommandSequence = $signalCommand->command_sequence;
        $originalCommandSource = $signalCommand->source;
        $originalTaskAvailableAt = $activityTask->available_at?->toJSON();
        $originalTaskAttemptCount = $activityTask->attempt_count;

        $signalCommand->forceFill([
            'command_sequence' => 99,
            'payload' => Serializer::serialize([
                'name' => 'tampered',
                'arguments' => ['Mallory'],
            ]),
            'source' => 'webhook',
        ])->save();

        $activityTask->forceFill([
            'available_at' => now()->addDay(),
            'attempt_count' => $originalTaskAttemptCount + 10,
        ])->save();

        $timeline = HistoryTimeline::forRun($run->fresh());
        $signalReceived = collect($timeline)->firstWhere('type', 'SignalReceived');
        $activityCompleted = collect($timeline)->firstWhere('type', 'ActivityCompleted');

        $this->assertIsArray($signalReceived);
        $this->assertIsArray($activityCompleted);
        $this->assertSame($originalCommandSequence, $signalReceived['command_sequence']);
        $this->assertSame('instance', $signalReceived['command']['target_scope']);
        $this->assertSame('name-provided', $signalReceived['command']['target_name']);
        $this->assertSame(config('workflows.serializer'), $signalReceived['command']['payload_codec']);
        $this->assertTrue($signalReceived['command']['payload_available']);
        $this->assertSame(serialize([
            'name' => 'name-provided',
            'arguments' => ['Taylor'],
            'validation_errors' => [],
        ]), $signalReceived['command']['payload']);
        $this->assertSame($originalCommandSource, $signalReceived['command']['source']);
        $this->assertSame($originalTaskAvailableAt, $activityCompleted['task']['available_at']);
        $this->assertSame($originalTaskAttemptCount, $activityCompleted['task']['attempt_count']);
    }

    public function testTimelineKeepsActivitySnapshotsWhenExecutionRowDrifts(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'timeline-activity-snapshot-drift');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->completed());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        /** @var ActivityExecution $activity */
        $activity = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();

        $originalTimeline = collect(HistoryTimeline::forRun($run));
        $originalStarted = $originalTimeline->firstWhere('type', 'ActivityStarted');
        $originalCompleted = $originalTimeline->firstWhere('type', 'ActivityCompleted');

        $this->assertIsArray($originalStarted);
        $this->assertIsArray($originalCompleted);

        $originalAttemptId = $originalStarted['activity']['attempt_id'];
        $originalStartedAt = $originalStarted['activity']['started_at'];
        $originalClosedAt = $originalCompleted['activity']['closed_at'];

        $activity->forceFill([
            'status' => 'failed',
            'attempt_count' => 99,
            'current_attempt_id' => '01JTIMELINEDRIFTATTEMPT001',
            'started_at' => now()->addDay(),
            'closed_at' => now()->addDays(2),
            'last_heartbeat_at' => now()->addDays(3),
        ])->save();

        $timeline = collect(HistoryTimeline::forRun($run->fresh()));
        $started = $timeline->firstWhere('type', 'ActivityStarted');
        $completed = $timeline->firstWhere('type', 'ActivityCompleted');

        $this->assertIsArray($started);
        $this->assertIsArray($completed);
        $this->assertSame('running', $started['activity_status']);
        $this->assertSame('running', $started['activity']['status']);
        $this->assertSame($originalAttemptId, $started['activity']['attempt_id']);
        $this->assertSame(1, $started['activity']['attempt_count']);
        $this->assertSame($originalStartedAt, $started['activity']['started_at']);
        $this->assertNull($started['activity']['closed_at']);
        $this->assertSame('completed', $completed['activity_status']);
        $this->assertSame('completed', $completed['activity']['status']);
        $this->assertSame($originalAttemptId, $completed['activity']['attempt_id']);
        $this->assertSame(1, $completed['activity']['attempt_count']);
        $this->assertSame($originalClosedAt, $completed['activity']['closed_at']);
    }

    public function testRunDetailReadsProjectedTimelineEntriesWhenAvailable(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'timeline-projection-source');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->completed());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        WorkflowTimelineEntry::query()
            ->where('workflow_run_id', $runId)
            ->delete();

        $fallbackDetail = RunDetailView::forRun($run->fresh());

        $this->assertSame('live_fallback', $fallbackDetail['timeline_projection_source']);

        RunSummaryProjector::project($run->fresh());

        $this->assertSame(
            $run->historyEvents()->count(),
            WorkflowTimelineEntry::query()
                ->where('workflow_run_id', $runId)
                ->count(),
        );

        /** @var ActivityExecution $activity */
        $activity = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();

        $activity->forceFill([
            'activity_type' => 'drifted.activity',
            'activity_class' => 'App\\Activities\\DriftedActivity',
        ])->save();

        $projectedDetail = RunDetailView::forRun($run->fresh());
        $scheduled = collect($projectedDetail['timeline'])->firstWhere('type', 'ActivityScheduled');

        $this->assertSame('workflow_run_timeline_entries', $projectedDetail['timeline_projection_source']);
        $this->assertIsArray($scheduled);
        $this->assertSame('Scheduled TestGreetingActivity.', $scheduled['summary']);
        $this->assertSame(TestGreetingActivity::class, $scheduled['activity_type']);
        $this->assertSame(TestGreetingActivity::class, $scheduled['activity']['class']);
    }

    public function testTimelineKeepsTimerSnapshotsWhenTimerRowDrifts(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'timeline-timer-snapshot-drift');
        $workflow->start(5);
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->drainReadyTasks();

        /** @var WorkflowTask $timerTask */
        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Timer->value)
            ->firstOrFail();

        if ($timerTask->status === TaskStatus::Ready) {
            $timerTask->forceFill([
                'available_at' => now()
                    ->subSecond(),
            ])->save();

            $this->drainReadyTasks();
        }

        $this->assertTrue($workflow->refresh()->completed());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();

        $originalTimeline = collect(HistoryTimeline::forRun($run));
        $originalScheduled = $originalTimeline->firstWhere('type', 'TimerScheduled');
        $originalFired = $originalTimeline->firstWhere('type', 'TimerFired');

        $this->assertIsArray($originalScheduled);
        $this->assertIsArray($originalFired);

        $originalFireAt = $originalScheduled['timer']['fire_at'];
        $originalFiredAt = $originalFired['timer']['fired_at'];

        $timer->forceFill([
            'sequence' => 99,
            'delay_seconds' => 86400,
            'fire_at' => now()->addDay(),
            'fired_at' => now()->addDays(2),
        ])->save();

        $timeline = collect(HistoryTimeline::forRun($run->fresh()));
        $scheduled = $timeline->firstWhere('type', 'TimerScheduled');
        $fired = $timeline->firstWhere('type', 'TimerFired');

        $this->assertIsArray($scheduled);
        $this->assertIsArray($fired);
        $this->assertSame(1, $scheduled['timer']['sequence']);
        $this->assertSame(5, $scheduled['delay_seconds']);
        $this->assertSame(5, $scheduled['timer']['delay_seconds']);
        $this->assertSame($originalFireAt, $scheduled['timer']['fire_at']);
        $this->assertNull($scheduled['timer']['fired_at']);
        $this->assertSame(1, $fired['timer']['sequence']);
        $this->assertSame(5, $fired['delay_seconds']);
        $this->assertSame(5, $fired['timer']['delay_seconds']);
        $this->assertSame($originalFireAt, $fired['timer']['fire_at']);
        $this->assertSame($originalFiredAt, $fired['timer']['fired_at']);
    }

    public function testTimelineIncludesTypedChildWorkflowEntriesForCompletedParentRun(): void
    {
        $workflow = WorkflowStub::make(TestParentChildWorkflow::class, 'timeline-child');
        $workflow->start('Taylor');
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($parentRunId);
        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->sole();

        $timeline = HistoryTimeline::forRun($run);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ChildWorkflowScheduled',
            'ChildRunStarted',
            'ChildRunCompleted',
            'WorkflowCompleted',
        ], array_column($timeline, 'type'));

        $this->assertSame('child', $timeline[2]['kind']);
        $this->assertSame('child_workflow_run', $timeline[2]['source_kind']);
        $this->assertSame($link->child_workflow_run_id, $timeline[2]['source_id']);
        $this->assertSame('Scheduled child workflow test-child-greeting-workflow.', $timeline[2]['summary']);
        $this->assertSame($link->id, $timeline[2]['child_call_id']);
        $this->assertSame($link->child_workflow_instance_id, $timeline[2]['child_workflow_instance_id']);
        $this->assertSame($link->child_workflow_run_id, $timeline[2]['child_workflow_run_id']);
        $this->assertSame('test-child-greeting-workflow', $timeline[2]['child_workflow_type']);
        $this->assertSame(TestChildGreetingWorkflow::class, $timeline[2]['child_workflow_class']);
        $this->assertSame($link->id, $timeline[2]['child']['child_call_id']);
        $this->assertSame($link->child_workflow_run_id, $timeline[2]['child']['run_id']);
        $this->assertSame(TestChildGreetingWorkflow::class, $timeline[2]['child']['class']);
        $this->assertNull($timeline[2]['child']['status']);

        $this->assertSame('Child workflow test-child-greeting-workflow completed.', $timeline[4]['summary']);
        $this->assertSame('child', $timeline[4]['kind']);
        $this->assertSame($link->id, $timeline[4]['child_call_id']);
        $this->assertSame('completed', $timeline[4]['child_status']);
        $this->assertSame('completed', $timeline[4]['child']['status']);
    }

    public function testTimelineIncludesTypedRepairCommandEntryWhenRepairRecreatesTask(): void
    {
        Queue::fake();

        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'id' => 'timeline-repair',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        $result = WorkflowStub::loadRun($run->id)->attemptRepair();
        /** @var WorkflowTask $repairedTask */
        $repairedTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->sole();
        WorkflowTask::query()
            ->whereKey($repairedTask->id)
            ->update([
                'status' => TaskStatus::Completed->value,
                'attempt_count' => 2,
            ]);

        $timeline = HistoryTimeline::forRun($run->fresh());

        $this->assertSame(['RepairRequested'], array_column($timeline, 'type'));
        $this->assertSame('command', $timeline[0]['kind']);
        $this->assertSame('workflow_command', $timeline[0]['source_kind']);
        $this->assertSame($result->commandId(), $timeline[0]['source_id']);
        $this->assertSame('Repair recreated workflow task.', $timeline[0]['summary']);
        $this->assertSame('repair', $timeline[0]['command_type']);
        $this->assertSame('accepted', $timeline[0]['command_status']);
        $this->assertSame('repair_dispatched', $timeline[0]['command_outcome']);
        $this->assertSame($result->commandId(), $timeline[0]['command']['id']);
        $this->assertSame('repair', $timeline[0]['command']['type']);
        $this->assertSame('repair_dispatched', $timeline[0]['command']['outcome']);
        $this->assertSame('workflow', $timeline[0]['task']['type']);
        $this->assertSame('ready', $timeline[0]['task']['status']);
        $this->assertNull($timeline[0]['task']['attempt_count']);
    }

    public function testTimelineIncludesTypedSideEffectEntriesForWaitingRun(): void
    {
        Queue::fake();

        TestSideEffectWorkflow::resetCounter();

        $workflow = WorkflowStub::make(TestSideEffectWorkflow::class, 'timeline-side-effect');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);

        $timeline = HistoryTimeline::forRun($run);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SideEffectRecorded',
            'SignalWaitOpened',
        ], array_column($timeline, 'type'));

        $this->assertSame('side_effect', $timeline[2]['kind']);
        $this->assertSame('workflow_run', $timeline[2]['source_kind']);
        $this->assertSame($runId, $timeline[2]['source_id']);
        $this->assertSame('Recorded side effect.', $timeline[2]['summary']);
        $this->assertSame(1, $timeline[2]['workflow_sequence']);
        $this->assertNull($timeline[2]['command']);
        $this->assertSame('workflow', $timeline[2]['task']['type']);
        $this->assertSame('completed', $timeline[2]['task']['status']);
        $this->assertNull($timeline[2]['activity']);
        $this->assertNull($timeline[2]['timer']);

        $this->assertSame('signal', $timeline[3]['kind']);
        $this->assertSame('Waiting for signal finish.', $timeline[3]['summary']);
        $this->assertSame(2, $timeline[3]['workflow_sequence']);
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

    private function drainReadyTasks(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->orderBy('created_at')
                ->first();

            if ($task === null) {
                return;
            }

            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }

    private function runNextReadyTask(): void
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->first();

        if ($task === null) {
            $this->fail('Expected a ready workflow task.');
        }

        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };

        $this->app->call([$job, 'handle']);
    }
}
