<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use ReflectionException;
use Tests\Fixtures\V2\TestAsyncGeneratorCallbackWorkflow;
use Tests\Fixtures\V2\TestAsyncWorkflow;
use Tests\Fixtures\V2\TestBroadFailureCatchWorkflow;
use Tests\Fixtures\V2\TestConfiguredContinueSignalWorkflow;
use Tests\Fixtures\V2\TestConfiguredGreetingActivity;
use Tests\Fixtures\V2\TestConfiguredGreetingWorkflow;
use Tests\Fixtures\V2\TestContinueAsNewWorkflow;
use Tests\Fixtures\V2\TestFailingWorkflow;
use Tests\Fixtures\V2\TestFiberParallelWorkflow;
use Tests\Fixtures\V2\TestFiberSignalWorkflow;
use Tests\Fixtures\V2\TestGeneratorWorkflow;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestHandledFailureWorkflow;
use Tests\Fixtures\V2\TestHeartbeatActivity;
use Tests\Fixtures\V2\TestHeartbeatWorkflow;
use Tests\Fixtures\V2\TestHistoryReplayedChildWorkflow;
use Tests\Fixtures\V2\TestMixedParallelFailureWorkflow;
use Tests\Fixtures\V2\TestMixedParallelWorkflow;
use Tests\Fixtures\V2\TestNestedParallelActivityWorkflow;
use Tests\Fixtures\V2\TestParallelActivityFailureWorkflow;
use Tests\Fixtures\V2\TestParallelActivityWorkflow;
use Tests\Fixtures\V2\TestParallelChildFailureWorkflow;
use Tests\Fixtures\V2\TestParallelChildTerminalOutcomeWorkflow;
use Tests\Fixtures\V2\TestParallelChildWorkflow;
use Tests\Fixtures\V2\TestParallelMultipleActivityFailureWorkflow;
use Tests\Fixtures\V2\TestParentChildWorkflow;
use Tests\Fixtures\V2\TestParentFailingChildWorkflow;
use Tests\Fixtures\V2\TestParentWaitingOnChildWorkflow;
use Tests\Fixtures\V2\TestParentWaitingOnContinuingChildWorkflow;
use Tests\Fixtures\V2\TestReclaimDuringExecutionActivity;
use Tests\Fixtures\V2\TestRetryWorkflow;
use Tests\Fixtures\V2\TestSignalOrderingWorkflow;
use Tests\Fixtures\V2\TestSignalPayloadWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\Fixtures\V2\TestUpdateWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\ActivityTaskBridge;
use Workflow\V2\AsyncWorkflow;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\FailureSnapshots;
use Workflow\V2\Support\ActivityCancellation;
use Workflow\V2\Support\ActivityLease;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\RunSummarySortKey;
use Workflow\V2\Support\SelectedRunLocator;
use Workflow\V2\Support\WorkflowInstanceId;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class V2WorkflowTest extends TestCase
{
    public function testFiberWorkflowUsesStraightLineHelpersAndStillSupportsQueryReplay(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestFiberSignalWorkflow::class, 'fiber-straight-line');
        $workflow->start('Taylor');

        $this->drainReadyTasks();

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame([
            'stage' => 'waiting-for-approval',
            'approved_by' => null,
        ], $workflow->currentState());

        $signal = $workflow->signal('approved-by', 'Jordan');

        $this->assertTrue($signal->accepted());
        $this->assertSame('signal_received', $signal->outcome());

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'greeting' => 'Hello, Taylor!',
            'approved_by' => 'Jordan',
            'workflow_id' => 'fiber-straight-line',
            'run_id' => $workflow->runId(),
        ], $workflow->output());
    }

    public function testFiberWorkflowCanAwaitParallelBuilders(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestFiberParallelWorkflow::class, 'fiber-parallel-builders');
        $workflow->start('Taylor', 'Abigail', 'Selena');

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $output = $workflow->output();

        $this->assertSame('completed', $output['stage'] ?? null);
        $this->assertSame('fiber-parallel-builders', $output['workflow_id'] ?? null);
        $this->assertSame($workflow->runId(), $output['run_id'] ?? null);
        $this->assertSame('Hello, Taylor!', $output['results'][0] ?? null);
        $this->assertSame('Hello, Abigail!', $output['results'][1][0] ?? null);
        $this->assertSame('Hello, Selena!', $output['results'][1][1]['greeting'] ?? null);
        $this->assertIsString($output['results'][1][1]['workflow_id'] ?? null);
        $this->assertIsString($output['results'][1][1]['run_id'] ?? null);
        $this->assertSame([
            'stage' => 'completed',
        ], $workflow->currentState());
    }

    public function testNamedWorkflowExecutionRejectsGeneratorStyleAuthoring(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGeneratorWorkflow::class, 'generator-style-workflow');
        $workflow->start('Taylor');

        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->failed());

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->latest('created_at')
            ->firstOrFail();

        $this->assertStringContainsString(TestGeneratorWorkflow::class, $failure->message);
        $this->assertStringContainsString('must use straight-line helpers and must not yield', $failure->message);
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => HistoryEventType::ActivityScheduled->value,
        ]);
        $this->assertSame(['StartAccepted', 'WorkflowStarted', 'WorkflowFailed'], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testQueryReplayRejectsActivityHistoryShapeDrift(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestParallelActivityWorkflow::class, 'activity-query-history-shape-drift');
        $workflow->start('Ada', 'Grace');

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, [
            'timer_id' => 'timer-from-older-definition',
            'sequence' => 1,
            'delay_seconds' => 60,
            'fire_at' => now()
                ->addMinute()
                ->toJSON(),
        ]);

        $this->expectException(HistoryEventShapeMismatchException::class);
        $this->expectExceptionMessage('recorded [TimerScheduled]');
        $this->expectExceptionMessage('current workflow yielded activity');

        $workflow->refresh()
            ->currentState();
    }

    public function testWorkflowWorkerBlocksReplayWhenActivityHistoryShapeDrifts(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'activity-worker-history-shape-drift');
        $workflow->start('Taylor');

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        WorkflowHistoryEvent::record($run, HistoryEventType::ChildWorkflowScheduled, [
            'sequence' => 1,
            'child_call_id' => 'child-from-older-definition',
            'child_workflow_class' => TestTimerWorkflow::class,
            'child_workflow_type' => 'test-timer-workflow',
            'child_workflow_instance_id' => 'child-from-older-definition',
            'child_workflow_run_id' => 'child-run-from-older-definition',
        ]);

        $this->runReadyTaskForRun($workflow->runId(), TaskType::Workflow);

        $this->assertFalse($workflow->refresh()->failed());
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => HistoryEventType::WorkflowFailed->value,
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertTrue($task->payload['replay_blocked'] ?? false);
        $this->assertSame('history_shape_mismatch', $task->payload['replay_blocked_reason'] ?? null);
        $this->assertSame(1, $task->payload['replay_blocked_workflow_sequence'] ?? null);
        $this->assertSame('activity', $task->payload['replay_blocked_expected_history_shape'] ?? null);
        $this->assertSame(['ChildWorkflowScheduled'], $task->payload['replay_blocked_recorded_event_types'] ?? null);
        $this->assertStringContainsString('recorded [ChildWorkflowScheduled]', (string) $task->last_error);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($workflow->runId()));

        $this->assertSame('workflow_replay_blocked', $detail['liveness_state']);
        $this->assertStringContainsString('history recorded [ChildWorkflowScheduled]', $detail['liveness_reason']);
        $this->assertSame('replay_blocked', $detail['tasks'][0]['transport_state']);
        $this->assertSame('activity', $detail['tasks'][0]['replay_blocked_expected_history_shape']);
        $this->assertSame(['ChildWorkflowScheduled'], $detail['tasks'][0]['replay_blocked_recorded_event_types']);
    }

    public function testWorkflowWorkerBlocksReplayWhenParallelActivityBarrierTopologyDrifts(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(
            TestNestedParallelActivityWorkflow::class,
            'parallel-topology-worker-drift',
        );
        $workflow->start('Taylor', 'Abigail', 'Selena');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);
        $this->replaceActivityScheduledParallelPath($runId, 2, [[
            'parallel_group_id' => 'parallel-activities:1:3',
            'parallel_group_kind' => 'activity',
            'parallel_group_base_sequence' => 1,
            'parallel_group_size' => 3,
            'parallel_group_index' => 1,
        ]]);

        $this->runReadyActivityTaskForSequence($runId, 1);
        $this->runReadyActivityTaskForSequence($runId, 2);
        $this->runReadyActivityTaskForSequence($runId, 3);
        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        $this->assertFalse($workflow->refresh()->failed());
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $runId,
            'event_type' => HistoryEventType::WorkflowFailed->value,
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Failed->value)
            ->firstOrFail();

        $this->assertTrue($task->payload['replay_blocked'] ?? false);
        $this->assertSame('history_shape_mismatch', $task->payload['replay_blocked_reason'] ?? null);
        $this->assertSame(2, $task->payload['replay_blocked_workflow_sequence'] ?? null);
        $this->assertSame(
            'parallel all barrier matching current topology',
            $task->payload['replay_blocked_expected_history_shape'] ?? null,
        );
        $this->assertSame(
            ['ActivityScheduled', 'ActivityStarted', 'ActivityCompleted'],
            $task->payload['replay_blocked_recorded_event_types'] ?? null,
        );
        $this->assertStringContainsString('parallel all barrier matching current topology', (string) $task->last_error);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));

        $this->assertSame('workflow_replay_blocked', $detail['liveness_state']);
        $this->assertStringContainsString(
            'history recorded [ActivityScheduled, ActivityStarted, ActivityCompleted]',
            $detail['liveness_reason'],
        );
        $this->assertSame('replay_blocked', $detail['tasks'][0]['transport_state']);
        $this->assertSame(
            'parallel all barrier matching current topology',
            $detail['tasks'][0]['replay_blocked_expected_history_shape'],
        );
    }

    public function testWorkflowWorkerBlocksReplayWhenParallelActivityHistoryLacksGroupMetadata(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(
            TestParallelActivityWorkflow::class,
            'parallel-missing-group-metadata-worker-drift',
        );
        $workflow->start('Taylor', 'Abigail');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);
        $this->runReadyActivityTaskForSequence($runId, 1);
        $this->runReadyActivityTaskForSequence($runId, 2);
        $this->removeActivityHistoryParallelMetadata($runId, 1);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        $this->assertFalse($workflow->refresh()->completed());
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $runId,
            'event_type' => HistoryEventType::WorkflowCompleted->value,
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Failed->value)
            ->firstOrFail();

        $this->assertTrue($task->payload['replay_blocked'] ?? false);
        $this->assertSame('history_shape_mismatch', $task->payload['replay_blocked_reason'] ?? null);
        $this->assertSame(1, $task->payload['replay_blocked_workflow_sequence'] ?? null);
        $this->assertSame(
            'parallel all barrier matching current topology',
            $task->payload['replay_blocked_expected_history_shape'] ?? null,
        );
        $this->assertSame(
            ['ActivityScheduled', 'ActivityStarted', 'ActivityCompleted'],
            $task->payload['replay_blocked_recorded_event_types'] ?? null,
        );

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));

        $this->assertSame('workflow_replay_blocked', $detail['liveness_state']);
        $this->assertSame('replay_blocked', $detail['tasks'][0]['transport_state']);
        $this->assertSame(
            'parallel all barrier matching current topology',
            $detail['tasks'][0]['replay_blocked_expected_history_shape'],
        );
        $this->assertSame(
            ['ActivityScheduled', 'ActivityStarted', 'ActivityCompleted'],
            $detail['tasks'][0]['replay_blocked_recorded_event_types'],
        );
    }

    public function testWorkflowCompletesWithDistinctInstanceAndRunIds(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class);
        $instanceId = $workflow->id();

        $this->assertSame('reserved', $workflow->status());
        $this->assertNull($workflow->runId());

        $result = $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);
        $this->assertNotSame($instanceId, $runId);
        $this->assertTrue($result->accepted());
        $this->assertSame('started_new', $result->outcome());
        $this->assertTrue($result->startedNew());
        $this->assertSame($instanceId, $result->instanceId());
        $this->assertSame($runId, $result->runId());

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();
        /** @var WorkflowHistoryEvent $activityScheduled */
        $activityScheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'ActivityScheduled')
            ->firstOrFail();
        /** @var WorkflowHistoryEvent $activityStarted */
        $activityStarted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'ActivityStarted')
            ->firstOrFail();
        /** @var WorkflowHistoryEvent $activityCompleted */
        $activityCompleted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'ActivityCompleted')
            ->firstOrFail();

        $this->assertSame([
            'greeting' => 'Hello, Taylor!',
            'workflow_id' => $instanceId,
            'run_id' => $runId,
        ], $workflow->output());
        $this->assertSame(1, $execution->attempt_count);
        $this->assertNotNull($execution->current_attempt_id);
        $this->assertSame(0, $activityScheduled->payload['activity']['attempt_count'] ?? null);
        $this->assertNull($activityScheduled->payload['activity']['attempt_id'] ?? null);
        $this->assertSame(1, $activityStarted->payload['activity']['attempt_count'] ?? null);
        $this->assertSame($execution->current_attempt_id, $activityStarted->payload['activity']['attempt_id'] ?? null);
        $this->assertSame(1, $activityCompleted->payload['activity']['attempt_count'] ?? null);
        $this->assertSame(
            $execution->current_attempt_id,
            $activityCompleted->payload['activity']['attempt_id'] ?? null
        );

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ActivityScheduled',
            'ActivityStarted',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $startAccepted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'StartAccepted')
            ->first();

        $this->assertNotNull($startAccepted);
        $this->assertSame($result->commandId(), $startAccepted->workflow_command_id);

        $this->assertDatabaseHas('workflow_run_summaries', [
            'id' => $runId,
            'workflow_instance_id' => $instanceId,
            'status' => 'completed',
            'status_bucket' => 'completed',
            'engine_source' => 'v2',
        ]);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $instanceId,
            'workflow_run_id' => $runId,
            'command_type' => 'start',
            'source' => 'php',
            'status' => 'accepted',
            'outcome' => 'started_new',
        ]);
    }

    public function testWorkflowActivityHeartbeatPersistsAttemptMetadata(): void
    {
        Queue::fake();

        $expectedProgress = [
            'message' => 'Polling remote job',
            'current' => 1,
            'total' => 3,
            'unit' => 'steps',
            'details' => [
                'phase' => 'poll',
                'remote_state' => 'running',
            ],
        ];

        $workflow = WorkflowStub::make(TestHeartbeatWorkflow::class, 'heartbeat-runtime');
        $workflow->start();

        $this->drainReadyTasks();
        $workflow->refresh();

        $this->assertTrue(
            $workflow->completed(),
            json_encode([
                'status' => $workflow->status(),
                'output' => $workflow->output(),
            ], JSON_THROW_ON_ERROR),
        );

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();
        /** @var ActivityAttempt $attempt */
        $attempt = ActivityAttempt::query()
            ->where('activity_execution_id', $execution->id)
            ->firstOrFail();

        $this->assertNotNull($execution->last_heartbeat_at);
        $this->assertSame($execution->current_attempt_id, $attempt->id);
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertSame('completed', $attempt->status->value);
        $this->assertSame(
            $execution->last_heartbeat_at?->jsonSerialize(),
            $attempt->last_heartbeat_at?->jsonSerialize()
        );
        $this->assertNotNull($attempt->closed_at);
        $this->assertSame([
            'workflow_id' => $workflow->id(),
            'run_id' => $workflow->runId(),
            'activity_id' => $execution->id,
            'attempt_id' => $execution->current_attempt_id,
            'attempt_count' => 1,
        ], $workflow->output());

        /** @var WorkflowHistoryEvent $heartbeat */
        $heartbeat = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded->value)
            ->sole();

        $this->assertSame($execution->id, $heartbeat->payload['activity_execution_id'] ?? null);
        $this->assertSame($attempt->id, $heartbeat->payload['activity_attempt_id'] ?? null);
        $this->assertSame($execution->last_heartbeat_at?->toJSON(), $heartbeat->payload['heartbeat_at'] ?? null);
        $this->assertSame($expectedProgress, $heartbeat->payload['progress'] ?? null);
        $this->assertSame(
            $execution->last_heartbeat_at?->toJSON(),
            $heartbeat->payload['activity']['last_heartbeat_at'] ?? null
        );

        $export = $workflow->historyExport();

        $this->assertSame($expectedProgress, $export['activities'][0]['last_heartbeat_progress'] ?? null);
        $this->assertSame(
            $expectedProgress,
            $export['activities'][0]['attempts'][0]['last_heartbeat_progress'] ?? null
        );

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ActivityScheduled',
            'ActivityStarted',
            'ActivityHeartbeatRecorded',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testTypedActivityHistoryBlocksMutableCompletedExecutionWithoutTerminalHistory(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');

        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'activity-history-authority');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);
        $this->runReadyTaskForRun($runId, TaskType::Activity);

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();

        WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityCompleted->value)
            ->delete();

        $execution->forceFill([
            'status' => ActivityStatus::Completed->value,
            'result' => Serializer::serialize('MUTATED'),
            'closed_at' => now(),
        ])->save();

        $state = (new QueryStateReplayer())->replayState(WorkflowRun::query()->findOrFail($runId));

        $this->assertSame(1, $state->sequence);
        $this->assertInstanceOf(ActivityCall::class, $state->current);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        $workflow->refresh();

        $this->assertFalse($workflow->completed());
        $this->assertSame('waiting', WorkflowRun::query()->findOrFail($runId)->status->value);
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $runId,
            'event_type' => HistoryEventType::WorkflowCompleted->value,
        ]);

        $detail = RunDetailView::forRun(WorkflowRun::query()->findOrFail($runId));

        $this->assertSame('activity', $detail['wait_kind']);
        $this->assertSame('activity_running_without_task', $detail['liveness_state']);
        $this->assertSame($execution->id, $detail['activities'][0]['id']);
        $this->assertSame('running', $detail['activities'][0]['status']);
        $this->assertNull($detail['activities'][0]['result']);
        $this->assertNull($detail['activities'][0]['closed_at']);
        $this->assertSame('open', $detail['waits'][0]['status']);
        $this->assertSame('running', $detail['waits'][0]['source_status']);
        $this->assertSame([], collect($detail['tasks'])
            ->filter(static fn (array $task): bool => ($task['is_open'] ?? false) === true)
            ->values()
            ->all());
    }

    public function testReplayBlocksTerminalActivityProjectionWithoutTypedStepHistory(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');

        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'activity-row-only-terminal-history');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);
        $this->runReadyTaskForRun($runId, TaskType::Activity);

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();

        WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('event_type', [
                HistoryEventType::ActivityScheduled->value,
                HistoryEventType::ActivityStarted->value,
                HistoryEventType::ActivityCompleted->value,
            ])
            ->delete();

        $execution->forceFill([
            'status' => ActivityStatus::Completed->value,
            'result' => Serializer::serialize('MUTATED'),
            'closed_at' => now(),
        ])->save();

        try {
            (new QueryStateReplayer())->replayState(WorkflowRun::query()->findOrFail($runId));
            $this->fail('Expected row-only terminal activity replay to be blocked.');
        } catch (HistoryEventShapeMismatchException $exception) {
            $this->assertSame(1, $exception->workflowSequence);
            $this->assertSame('activity', $exception->expectedHistoryShape);
            $this->assertSame(['no typed history'], $exception->recordedEventTypes);
        }

        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Failed->value)
            ->firstOrFail();

        $this->assertFalse($workflow->refresh()->completed());
        $this->assertTrue($task->payload['replay_blocked'] ?? false);
        $this->assertSame('history_shape_mismatch', $task->payload['replay_blocked_reason'] ?? null);
        $this->assertSame('activity', $task->payload['replay_blocked_expected_history_shape'] ?? null);
        $this->assertSame(['no typed history'], $task->payload['replay_blocked_recorded_event_types'] ?? null);
        $this->assertStringContainsString('recorded [no typed history]', (string) $task->last_error);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));

        $this->assertSame('workflow_replay_blocked', $detail['liveness_state']);
        $this->assertStringContainsString('history recorded [no typed history]', $detail['liveness_reason']);
        $this->assertSame('unsupported', $detail['activities'][0]['status']);
        $this->assertSame('unsupported_terminal_without_history', $detail['activities'][0]['history_authority']);
        $this->assertTrue($detail['activities'][0]['diagnostic_only']);
        $this->assertSame(
            'terminal_activity_row_without_typed_history',
            $detail['activities'][0]['history_unsupported_reason'],
        );
        $this->assertSame('completed', $detail['activities'][0]['row_status']);
        $this->assertNull($detail['activities'][0]['result']);
        $this->assertSame('unsupported', $detail['waits'][0]['status']);
        $this->assertSame('completed', $detail['waits'][0]['source_status']);
        $this->assertTrue($detail['waits'][0]['diagnostic_only']);
        $this->assertNull($detail['waits'][0]['resume_source_kind']);
        $this->assertNull($detail['waits'][0]['resume_source_id']);
        $this->assertSame(
            'terminal_activity_row_without_typed_history',
            $detail['waits'][0]['history_unsupported_reason'],
        );
        $this->assertSame('replay_blocked', $detail['tasks'][0]['transport_state']);
        $this->assertSame(['no typed history'], $detail['tasks'][0]['replay_blocked_recorded_event_types']);
        $this->assertFalse(collect($detail['tasks'])->contains(
            static fn (array $row): bool => ($row['task_missing'] ?? false) === true
        ));
    }

    public function testRowOnlyCancelledActivityWithoutTypedHistoryIsMarkedUnsupported(): void
    {
        $attemptId = (string) Str::ulid();

        $instance = WorkflowInstance::query()->create([
            'id' => 'row-only-activity-cancelled',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'started_at' => now()
                ->subMinutes(3),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Cancelled->value,
            'closed_reason' => 'cancelled',
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(3),
            'closed_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => TestGreetingActivity::class,
            'status' => ActivityStatus::Cancelled->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'attempt_count' => 1,
            'current_attempt_id' => $attemptId,
            'started_at' => now()
                ->subMinutes(2),
            'closed_at' => now()
                ->subMinute(),
        ]);

        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $export = HistoryExport::forRun($run->fresh(['historyEvents', 'activityExecutions.attempts']));

        $this->assertSame('unsupported', $detail['activities'][0]['status']);
        $this->assertSame('unsupported_terminal_without_history', $detail['activities'][0]['history_authority']);
        $this->assertTrue($detail['activities'][0]['diagnostic_only']);
        $this->assertSame(
            'terminal_activity_row_without_typed_history',
            $detail['activities'][0]['history_unsupported_reason'],
        );
        $this->assertSame('cancelled', $detail['activities'][0]['row_status']);
        $this->assertSame($attemptId, $detail['activities'][0]['attempt_id']);
        $this->assertCount(1, $detail['activities'][0]['attempts']);
        $this->assertSame($attemptId, $detail['activities'][0]['attempts'][0]['id']);
        $this->assertSame('cancelled', $detail['activities'][0]['attempts'][0]['status']);
        $this->assertNull($detail['activities'][0]['closed_at']);
        $this->assertNull($detail['activities'][0]['result']);
        $this->assertSame('unsupported', $detail['waits'][0]['status']);
        $this->assertSame('cancelled', $detail['waits'][0]['source_status']);
        $this->assertTrue($detail['waits'][0]['diagnostic_only']);
        $this->assertNull($detail['waits'][0]['resume_source_kind']);
        $this->assertNull($detail['waits'][0]['resume_source_id']);
        $this->assertSame(
            'terminal_activity_row_without_typed_history',
            $detail['waits'][0]['history_unsupported_reason'],
        );
        $this->assertCount(0, $detail['tasks']);

        $this->assertSame('unsupported', $export['activities'][0]['status']);
        $this->assertSame('unsupported_terminal_without_history', $export['activities'][0]['history_authority']);
        $this->assertTrue($export['activities'][0]['diagnostic_only']);
        $this->assertSame(
            'terminal_activity_row_without_typed_history',
            $export['activities'][0]['history_unsupported_reason'],
        );
        $this->assertSame('cancelled', $export['activities'][0]['row_status']);
        $this->assertSame($detail['activities'][0]['attempt_id'], $export['activities'][0]['current_attempt_id']);
        $this->assertCount(1, $export['activities'][0]['attempts']);
        $this->assertSame($detail['activities'][0]['attempts'][0]['id'], $export['activities'][0]['attempts'][0]['id']);
        $this->assertSame(
            $detail['activities'][0]['attempts'][0]['status'],
            $export['activities'][0]['attempts'][0]['status']
        );
        $this->assertSame(
            $run->activityExecutions()
                ->firstOrFail()
->id,
            $export['activities'][0]['attempts'][0]['activity_execution_id']
        );
        $this->assertNull($export['activities'][0]['closed_at']);
        $this->assertTrue($export['waits'][0]['diagnostic_only']);
        $this->assertNull($export['waits'][0]['resume_source_kind']);
        $this->assertNull($export['waits'][0]['resume_source_id']);
    }

    public function testReplayBlocksFiredTimerProjectionWithoutTypedStepHistory(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');

        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-09 15:00:00'));

        try {
            $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'timer-row-only-terminal-history');
            $workflow->start(5);
            $runId = $workflow->runId();

            $this->assertNotNull($runId);

            $this->runReadyTaskForRun($runId, TaskType::Workflow);

            /** @var WorkflowTimer $timer */
            $timer = WorkflowTimer::query()
                ->where('workflow_run_id', $runId)
                ->firstOrFail();
            $timerId = $timer->id;

            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->whereIn('event_type', [
                    HistoryEventType::TimerScheduled->value,
                    HistoryEventType::TimerFired->value,
                ])
                ->delete();

            $timer->forceFill([
                'status' => TimerStatus::Fired->value,
                'fired_at' => now()
                    ->addSeconds(5),
            ])->save();

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()->findOrFail($runId);

            WorkflowTask::query()->create([
                'workflow_run_id' => $runId,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now()
                    ->addSeconds(5),
                'payload' => [],
                'connection' => $run->connection,
                'queue' => $run->queue,
                'compatibility' => $run->compatibility,
            ]);

            try {
                (new QueryStateReplayer())->replayState(WorkflowRun::query()->findOrFail($runId));
                $this->fail('Expected row-only fired timer replay to be blocked.');
            } catch (HistoryEventShapeMismatchException $exception) {
                $this->assertSame(1, $exception->workflowSequence);
                $this->assertSame('timer', $exception->expectedHistoryShape);
                $this->assertSame(['no typed history'], $exception->recordedEventTypes);
            }

            Carbon::setTestNow(now()->addSeconds(5));

            $this->runReadyTaskForRun($runId, TaskType::Workflow);

            /** @var WorkflowTask $task */
            $task = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Failed->value)
                ->firstOrFail();

            $this->assertFalse($workflow->refresh()->completed());
            $this->assertTrue($task->payload['replay_blocked'] ?? false);
            $this->assertSame('history_shape_mismatch', $task->payload['replay_blocked_reason'] ?? null);
            $this->assertSame('timer', $task->payload['replay_blocked_expected_history_shape'] ?? null);
            $this->assertSame(['no typed history'], $task->payload['replay_blocked_recorded_event_types'] ?? null);
            $this->assertStringContainsString('recorded [no typed history]', (string) $task->last_error);

            $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));
            $replayBlockedTask = collect($detail['tasks'])
                ->first(static fn (array $task): bool => ($task['transport_state'] ?? null) === 'replay_blocked');
            $timerDetail = collect($detail['timers'])
                ->first(static fn (array $timer): bool => ($timer['id'] ?? null) === $timerId);
            $timerWait = collect($detail['waits'])
                ->first(static fn (array $wait): bool => ($wait['kind'] ?? null) === 'timer');

            $this->assertSame('workflow_replay_blocked', $detail['liveness_state']);
            $this->assertStringContainsString('history recorded [no typed history]', $detail['liveness_reason']);
            $this->assertIsArray($timerDetail);
            $this->assertSame('unsupported', $timerDetail['status']);
            $this->assertSame('fired', $timerDetail['source_status']);
            $this->assertSame('fired', $timerDetail['row_status']);
            $this->assertSame('unsupported_terminal_without_history', $timerDetail['history_authority']);
            $this->assertSame(
                'terminal_timer_row_without_typed_history',
                $timerDetail['history_unsupported_reason'],
            );
            $this->assertSame([], $timerDetail['history_event_types']);
            $this->assertNull($timerDetail['fired_at']);
            $this->assertIsArray($timerWait);
            $this->assertSame('unsupported', $timerWait['status']);
            $this->assertSame('fired', $timerWait['source_status']);
            $this->assertTrue($timerWait['diagnostic_only']);
            $this->assertNull($timerWait['resume_source_kind']);
            $this->assertNull($timerWait['resume_source_id']);
            $this->assertSame(
                'terminal_timer_row_without_typed_history',
                $timerWait['history_unsupported_reason'],
            );
            $this->assertIsArray($replayBlockedTask);
            $this->assertSame(['no typed history'], $replayBlockedTask['replay_blocked_recorded_event_types']);
            $this->assertFalse(collect($detail['tasks'])->contains(
                static fn (array $row): bool => ($row['task_missing'] ?? false) === true
            ));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testReplayBlocksTerminalChildProjectionWithoutTypedParentStepHistory(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');

        Queue::fake();

        $workflow = WorkflowStub::make(TestHistoryReplayedChildWorkflow::class, 'child-row-only-terminal-history');
        $workflow->start('Taylor');
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->sole();

        $childRunId = $link->child_workflow_run_id;

        $this->assertIsString($childRunId);

        $this->runReadyTaskForRun($childRunId, TaskType::Workflow);
        $this->runReadyTaskForRun($childRunId, TaskType::Activity);
        $this->runReadyTaskForRun($childRunId, TaskType::Workflow);

        $this->assertSame('completed', WorkflowRun::query()->findOrFail($childRunId)->status->value);
        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $parentRunId,
            'event_type' => HistoryEventType::ChildRunCompleted->value,
        ]);

        WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->whereIn('event_type', [
                HistoryEventType::ChildWorkflowScheduled->value,
                HistoryEventType::ChildRunStarted->value,
                HistoryEventType::ChildRunCompleted->value,
                HistoryEventType::ChildRunFailed->value,
                HistoryEventType::ChildRunCancelled->value,
                HistoryEventType::ChildRunTerminated->value,
            ])
            ->delete();

        try {
            (new QueryStateReplayer())->replayState(WorkflowRun::query()->findOrFail($parentRunId));
            $this->fail('Expected link-only terminal child replay to be blocked.');
        } catch (HistoryEventShapeMismatchException $exception) {
            $this->assertSame(1, $exception->workflowSequence);
            $this->assertSame('child workflow', $exception->expectedHistoryShape);
            $this->assertSame(['no typed history'], $exception->recordedEventTypes);
        }

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Failed->value)
            ->firstOrFail();

        $this->assertFalse($workflow->refresh()->completed());
        $this->assertTrue($task->payload['replay_blocked'] ?? false);
        $this->assertSame('history_shape_mismatch', $task->payload['replay_blocked_reason'] ?? null);
        $this->assertSame('child workflow', $task->payload['replay_blocked_expected_history_shape'] ?? null);
        $this->assertSame(['no typed history'], $task->payload['replay_blocked_recorded_event_types'] ?? null);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($parentRunId));
        $replayBlockedTask = collect($detail['tasks'])
            ->first(static fn (array $task): bool => ($task['transport_state'] ?? null) === 'replay_blocked');
        $childWait = collect($detail['waits'])
            ->first(static fn (array $wait): bool => ($wait['kind'] ?? null) === 'child');

        $this->assertSame('workflow_replay_blocked', $detail['liveness_state']);
        $this->assertStringContainsString('history recorded [no typed history]', $detail['liveness_reason']);
        $this->assertIsArray($childWait);
        $this->assertSame('unsupported', $childWait['status']);
        $this->assertSame('completed', $childWait['source_status']);
        $this->assertTrue($childWait['diagnostic_only']);
        $this->assertNull($childWait['resume_source_kind']);
        $this->assertNull($childWait['resume_source_id']);
        $this->assertSame($childRunId, $childWait['child_workflow_run_id']);
        $this->assertSame('unsupported_terminal_without_history', $childWait['history_authority']);
        $this->assertSame(
            'terminal_child_link_without_typed_parent_history',
            $childWait['history_unsupported_reason'],
        );
        $this->assertIsArray($replayBlockedTask);
        $this->assertSame(['no typed history'], $replayBlockedTask['replay_blocked_recorded_event_types']);
        $this->assertFalse(collect($detail['tasks'])->contains(
            static fn (array $row): bool => ($row['task_missing'] ?? false) === true
        ));
    }

    public function testActivityRetriesBeforeResumingWorkflow(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-09 12:00:00'));

        try {
            $workflow = WorkflowStub::make(TestRetryWorkflow::class, 'activity-retry-runtime');
            $workflow->start('Taylor');
            $runId = $workflow->runId();

            $this->assertNotNull($runId);

            $this->runReadyTaskForRun($runId, TaskType::Workflow);
            $this->runReadyTaskForRun($runId, TaskType::Activity);

            /** @var ActivityExecution $execution */
            $execution = ActivityExecution::query()
                ->where('workflow_run_id', $runId)
                ->firstOrFail();
            /** @var ActivityAttempt $firstAttempt */
            $firstAttempt = ActivityAttempt::query()
                ->where('activity_execution_id', $execution->id)
                ->where('attempt_number', 1)
                ->firstOrFail();
            /** @var WorkflowTask $retryTask */
            $retryTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Activity->value)
                ->where('status', TaskStatus::Ready->value)
                ->firstOrFail();

            $this->assertSame(ActivityStatus::Pending, $execution->refresh()->status);
            $this->assertSame('failed', $firstAttempt->status->value);
            $this->assertSame(1, $execution->attempt_count);
            $this->assertSame([
                'snapshot_version' => 1,
                'max_attempts' => 2,
                'backoff_seconds' => [5],
            ], $execution->retry_policy);
            $this->assertSame(1, $retryTask->attempt_count);
            $this->assertSame($execution->id, $retryTask->payload['activity_execution_id'] ?? null);
            $this->assertSame($firstAttempt->workflow_task_id, $retryTask->payload['retry_of_task_id'] ?? null);
            $this->assertSame($firstAttempt->id, $retryTask->payload['retry_after_attempt_id'] ?? null);
            $this->assertSame(1, $retryTask->payload['retry_after_attempt'] ?? null);
            $this->assertSame(5, $retryTask->payload['retry_backoff_seconds'] ?? null);
            $this->assertSame(2, $retryTask->payload['max_attempts'] ?? null);
            $this->assertSame($execution->retry_policy, $retryTask->payload['retry_policy'] ?? null);
            $this->assertSame(Carbon::parse('2026-04-09 12:00:05')->toJSON(), $retryTask->available_at?->toJSON());

            /** @var WorkflowHistoryEvent $scheduled */
            $scheduled = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::ActivityScheduled->value)
                ->sole();

            $this->assertSame($execution->retry_policy, $scheduled->payload['activity']['retry_policy'] ?? null);

            /** @var WorkflowHistoryEvent $retryScheduled */
            $retryScheduled = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::ActivityRetryScheduled->value)
                ->sole();

            $this->assertSame($execution->id, $retryScheduled->payload['activity_execution_id'] ?? null);
            $this->assertSame($retryTask->id, $retryScheduled->payload['retry_task_id'] ?? null);
            $this->assertSame($firstAttempt->workflow_task_id, $retryScheduled->payload['retry_of_task_id'] ?? null);
            $this->assertSame($firstAttempt->id, $retryScheduled->payload['retry_after_attempt_id'] ?? null);
            $this->assertSame(1, $retryScheduled->payload['retry_after_attempt'] ?? null);
            $this->assertSame(5, $retryScheduled->payload['retry_backoff_seconds'] ?? null);
            $this->assertSame(2, $retryScheduled->payload['max_attempts'] ?? null);
            $this->assertSame($execution->retry_policy, $retryScheduled->payload['retry_policy'] ?? null);
            $this->assertSame('retry me', $retryScheduled->payload['message'] ?? null);
            $this->assertSame('pending', $retryScheduled->payload['activity']['status'] ?? null);
            $this->assertSame($execution->retry_policy, $retryScheduled->payload['activity']['retry_policy'] ?? null);

            $detail = RunDetailView::forRun(WorkflowRun::query()->findOrFail($runId));

            $this->assertSame('pending', $detail['activities'][0]['status']);
            $this->assertSame(1, $detail['activities'][0]['attempt_count']);
            $this->assertSame($execution->retry_policy, $detail['activities'][0]['retry_policy']);
            $this->assertSame('failed', $detail['activities'][0]['attempts'][0]['status']);
            $this->assertSame('ActivityRetryScheduled', $detail['timeline'][4]['type']);
            $this->assertSame('Scheduled retry 2 for TestRetryActivity.', $detail['timeline'][4]['summary']);
            $this->assertSame($retryTask->id, $detail['timeline'][4]['retry_task_id']);
            $this->assertSame($execution->retry_policy, $detail['timeline'][4]['activity']['retry_policy']);
            $this->assertSame('scheduled', $detail['tasks'][0]['transport_state']);
            $this->assertSame(1, $detail['tasks'][0]['retry_after_attempt']);
            $this->assertSame(2, $detail['tasks'][0]['retry_max_attempts']);
            $this->assertSame($execution->retry_policy, $detail['tasks'][0]['retry_policy']);

            Carbon::setTestNow(Carbon::parse('2026-04-09 12:00:05'));

            $this->runReadyTaskForRun($runId, TaskType::Activity);
            $this->runReadyTaskForRun($runId, TaskType::Workflow);

            $workflow->refresh();
            $execution->refresh();

            $this->assertTrue($workflow->completed());
            $this->assertSame(ActivityStatus::Completed, $execution->status);
            $this->assertSame(2, $execution->attempt_count);
            $this->assertNull($execution->exception);

            $attempts = ActivityAttempt::query()
                ->where('activity_execution_id', $execution->id)
                ->orderBy('attempt_number')
                ->get();

            $this->assertCount(2, $attempts);
            $this->assertSame('failed', $attempts[0]->status->value);
            $this->assertSame('completed', $attempts[1]->status->value);
            $this->assertSame($execution->current_attempt_id, $attempts[1]->id);

            $this->assertSame([
                'workflow_id' => $workflow->id(),
                'run_id' => $runId,
                'activity' => [
                    'message' => 'Hello, Taylor!',
                    'activity_id' => $execution->id,
                    'attempt_id' => $execution->current_attempt_id,
                    'attempt_count' => 2,
                ],
            ], $workflow->output());

            $this->assertSame([
                'StartAccepted',
                'WorkflowStarted',
                'ActivityScheduled',
                'ActivityStarted',
                'ActivityRetryScheduled',
                'ActivityStarted',
                'ActivityCompleted',
                'WorkflowCompleted',
            ], WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->orderBy('sequence')
                ->pluck('event_type')
                ->map(static fn ($eventType) => $eventType->value)
                ->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testActivityRetrySchedulingUsesDurablePolicySnapshot(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-09 12:00:00'));

        try {
            $workflow = WorkflowStub::make(TestRetryWorkflow::class, 'activity-retry-policy-snapshot');
            $workflow->start('Taylor');
            $runId = $workflow->runId();

            $this->assertNotNull($runId);

            $this->runReadyTaskForRun($runId, TaskType::Workflow);

            /** @var ActivityExecution $execution */
            $execution = ActivityExecution::query()
                ->where('workflow_run_id', $runId)
                ->firstOrFail();

            $execution->forceFill([
                'retry_policy' => [
                    'snapshot_version' => 1,
                    'max_attempts' => 2,
                    'backoff_seconds' => [17],
                ],
            ])->save();

            $this->runReadyTaskForRun($runId, TaskType::Activity);

            /** @var WorkflowTask $retryTask */
            $retryTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Activity->value)
                ->where('status', TaskStatus::Ready->value)
                ->firstOrFail();
            /** @var WorkflowHistoryEvent $retryScheduled */
            $retryScheduled = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::ActivityRetryScheduled->value)
                ->sole();

            $this->assertSame(17, $retryTask->payload['retry_backoff_seconds'] ?? null);
            $this->assertSame(17, $retryScheduled->payload['retry_backoff_seconds'] ?? null);
            $this->assertSame($execution->refresh()->retry_policy, $retryScheduled->payload['retry_policy'] ?? null);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testActivityTaskBridgeClaimsHeartbeatsAndCompletesAttemptWithoutExecutingPhpActivity(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'activity-bridge-complete');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $claim = ActivityTaskBridge::claim($task->id, 'external-worker-1');

        $this->assertIsArray($claim);
        $this->assertSame($task->id, $claim['task_id']);
        $this->assertSame($runId, $claim['workflow_run_id']);
        $this->assertSame('activity-bridge-complete', $claim['workflow_instance_id']);
        $this->assertSame('external-worker-1', $claim['lease_owner']);
        $this->assertSame(1, $claim['attempt_number']);
        $this->assertSame(['Taylor'], Serializer::unserialize($claim['arguments']));

        /** @var ActivityAttempt $startedAttempt */
        $startedAttempt = ActivityAttempt::query()
            ->whereKey($claim['activity_attempt_id'])
            ->firstOrFail();

        $task->refresh();

        $this->assertSame('running', $startedAttempt->status->value);
        $this->assertSame($task->id, $startedAttempt->workflow_task_id);
        $this->assertSame('external-worker-1', $startedAttempt->lease_owner);
        $this->assertSame(
            $task->lease_expires_at?->jsonSerialize(),
            $startedAttempt->lease_expires_at?->jsonSerialize()
        );

        $progress = [
            'message' => 'Streaming response',
            'current' => 2,
            'total' => 5,
            'unit' => 'chunks',
            'details' => [
                'phase' => 'download',
            ],
        ];

        $this->assertTrue(ActivityTaskBridge::heartbeat($claim['activity_attempt_id'], $progress));

        $outcome = ActivityTaskBridge::complete($claim['activity_attempt_id'], 'Hello from bridge!');

        $this->assertTrue($outcome['recorded']);
        $this->assertNull($outcome['reason']);
        $this->assertNotNull($outcome['next_task_id']);

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();
        /** @var ActivityAttempt $attempt */
        $attempt = ActivityAttempt::query()
            ->whereKey($claim['activity_attempt_id'])
            ->firstOrFail();

        $this->assertSame(ActivityStatus::Completed, $execution->status);
        $this->assertSame('completed', $attempt->status->value);
        $this->assertNotNull($attempt->last_heartbeat_at);
        $this->assertSame('external-worker-1', $attempt->lease_owner);

        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityStarted->value)
            ->sole();
        /** @var WorkflowHistoryEvent $completed */
        $completed = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityCompleted->value)
            ->sole();

        $this->assertSame($claim['activity_attempt_id'], $started->payload['activity_attempt_id'] ?? null);
        $this->assertSame(1, $started->payload['attempt_number'] ?? null);
        $this->assertSame($claim['activity_attempt_id'], $started->payload['activity']['attempt_id'] ?? null);
        $this->assertSame(1, $started->payload['activity']['attempt_count'] ?? null);

        /** @var WorkflowHistoryEvent $heartbeat */
        $heartbeat = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded->value)
            ->sole();

        $this->assertSame($progress, $heartbeat->payload['progress'] ?? null);

        $this->assertSame($claim['activity_attempt_id'], $completed->payload['activity_attempt_id'] ?? null);
        $this->assertSame(1, $completed->payload['attempt_number'] ?? null);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        $workflow->refresh();

        $this->assertTrue(
            $workflow->completed(),
            json_encode([
                'status' => $workflow->status(),
                'output' => $workflow->output(),
            ], JSON_THROW_ON_ERROR),
        );
        $this->assertSame([
            'greeting' => 'Hello from bridge!',
            'workflow_id' => 'activity-bridge-complete',
            'run_id' => $runId,
        ], $workflow->output());
    }

    public function testActivityTaskBridgeUsesSnappedRetryPolicyForExternalFailures(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-09 12:00:00'));

        try {
            $workflow = WorkflowStub::make(TestRetryWorkflow::class, 'activity-bridge-retry');
            $workflow->start('Taylor');
            $runId = $workflow->runId();

            $this->assertNotNull($runId);

            $this->runReadyTaskForRun($runId, TaskType::Workflow);

            /** @var WorkflowTask $firstTask */
            $firstTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Activity->value)
                ->where('status', TaskStatus::Ready->value)
                ->firstOrFail();

            $firstClaim = ActivityTaskBridge::claim($firstTask->id, 'external-worker-1');

            $this->assertIsArray($firstClaim);

            $failed = ActivityTaskBridge::fail($firstClaim['activity_attempt_id'], [
                'class' => \RuntimeException::class,
                'message' => 'external retry me',
                'code' => 7,
            ]);

            $this->assertTrue($failed['recorded']);
            $this->assertNotNull($failed['next_task_id']);

            /** @var ActivityExecution $execution */
            $execution = ActivityExecution::query()
                ->where('workflow_run_id', $runId)
                ->firstOrFail();
            /** @var WorkflowTask $retryTask */
            $retryTask = WorkflowTask::query()
                ->whereKey($failed['next_task_id'])
                ->firstOrFail();

            $this->assertSame(ActivityStatus::Pending, $execution->status);
            $this->assertSame([
                'snapshot_version' => 1,
                'max_attempts' => 2,
                'backoff_seconds' => [5],
            ], $execution->retry_policy);
            $this->assertSame($execution->id, $retryTask->payload['activity_execution_id'] ?? null);
            $this->assertSame(
                $firstClaim['activity_attempt_id'],
                $retryTask->payload['retry_after_attempt_id'] ?? null
            );
            $this->assertSame(1, $retryTask->payload['retry_after_attempt'] ?? null);
            $this->assertSame(5, $retryTask->payload['retry_backoff_seconds'] ?? null);
            $this->assertSame(2, $retryTask->payload['max_attempts'] ?? null);

            /** @var WorkflowHistoryEvent $retryScheduled */
            $retryScheduled = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::ActivityRetryScheduled->value)
                ->sole();

            $this->assertSame('external retry me', $retryScheduled->payload['message'] ?? null);
            $this->assertSame($execution->retry_policy, $retryScheduled->payload['retry_policy'] ?? null);

            Carbon::setTestNow(Carbon::parse('2026-04-09 12:00:05'));

            $secondClaim = ActivityTaskBridge::claim($retryTask->id, 'external-worker-2');

            $this->assertIsArray($secondClaim);
            $this->assertSame(2, $secondClaim['attempt_number']);

            $completed = ActivityTaskBridge::complete($secondClaim['activity_attempt_id'], [
                'message' => 'Hello, Taylor!',
                'activity_id' => $execution->id,
                'attempt_id' => $secondClaim['activity_attempt_id'],
                'attempt_count' => 2,
            ]);

            $this->assertTrue($completed['recorded']);
            $this->assertNotNull($completed['next_task_id']);

            $this->runReadyTaskForRun($runId, TaskType::Workflow);

            $workflow->refresh();
            $execution->refresh();

            $this->assertTrue($workflow->completed());
            $this->assertSame(ActivityStatus::Completed, $execution->status);
            $this->assertSame(2, $execution->attempt_count);
            $this->assertSame([
                'workflow_id' => 'activity-bridge-retry',
                'run_id' => $runId,
                'activity' => [
                    'message' => 'Hello, Taylor!',
                    'activity_id' => $execution->id,
                    'attempt_id' => $secondClaim['activity_attempt_id'],
                    'attempt_count' => 2,
                ],
            ], $workflow->output());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testActivityTaskBridgeHeartbeatReportsCancellationAndClosesAttempt(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'activity-bridge-cancel');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $claim = ActivityTaskBridge::claim($task->id, 'external-worker-cancel');

        $this->assertIsArray($claim);

        $runningStatus = ActivityTaskBridge::status($claim['activity_attempt_id']);

        $this->assertTrue($runningStatus['can_continue']);
        $this->assertFalse($runningStatus['cancel_requested']);
        $this->assertNull($runningStatus['reason']);
        $this->assertFalse($runningStatus['heartbeat_recorded']);
        $this->assertSame('waiting', $runningStatus['run_status']);
        $this->assertSame('running', $runningStatus['activity_status']);
        $this->assertSame('running', $runningStatus['attempt_status']);
        $this->assertSame('leased', $runningStatus['task_status']);

        $cancelled = $workflow->cancel();

        $this->assertTrue($cancelled->accepted());
        $this->assertSame('cancelled', $cancelled->outcome());

        $cancelStatus = ActivityTaskBridge::heartbeatStatus($claim['activity_attempt_id']);

        $this->assertFalse($cancelStatus['can_continue']);
        $this->assertTrue($cancelStatus['cancel_requested']);
        $this->assertSame('run_cancelled', $cancelStatus['reason']);
        $this->assertFalse($cancelStatus['heartbeat_recorded']);
        $this->assertSame('cancelled', $cancelStatus['run_status']);
        $this->assertSame('cancelled', $cancelStatus['activity_status']);
        $this->assertSame('cancelled', $cancelStatus['attempt_status']);
        $this->assertSame('cancelled', $cancelStatus['task_status']);
        $this->assertNull($cancelStatus['lease_expires_at']);

        /** @var ActivityAttempt $attempt */
        $attempt = ActivityAttempt::query()
            ->whereKey($claim['activity_attempt_id'])
            ->firstOrFail();
        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->whereKey($claim['activity_execution_id'])
            ->firstOrFail();
        /** @var WorkflowTask $claimedTask */
        $claimedTask = WorkflowTask::query()
            ->whereKey($claim['task_id'])
            ->firstOrFail();

        $this->assertSame('cancelled', $attempt->status->value);
        $this->assertSame('cancelled', $execution->status->value);
        $this->assertSame('cancelled', $claimedTask->status->value);
        $this->assertNotNull($attempt->closed_at);

        $activityCancelled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityCancelled->value)
            ->firstOrFail();

        $this->assertSame($execution->id, $activityCancelled->payload['activity_execution_id']);
        $this->assertSame($attempt->id, $activityCancelled->payload['activity_attempt_id']);
        $this->assertSame('cancelled', $activityCancelled->payload['activity']['status'] ?? null);
        $this->assertSame('cancelled', $activityCancelled->payload['activity_attempt']['status'] ?? null);

        $detail = RunDetailView::forRun(
            WorkflowRun::query()
                ->with(['summary', 'historyEvents', 'activityExecutions.attempts'])
                ->findOrFail($runId)
        );

        $this->assertSame('cancelled', $detail['activities'][0]['status']);
        $this->assertSame('typed_history', $detail['activities'][0]['history_authority']);
        $this->assertNotNull(collect($detail['timeline'])->firstWhere('type', 'ActivityCancelled'));

        $lateCompletion = ActivityTaskBridge::complete($claim['activity_attempt_id'], 'too late');

        $this->assertFalse($lateCompletion['recorded']);
        $this->assertSame('stale_attempt', $lateCompletion['reason']);
    }

    public function testActivityCancelledHistoryIsTerminalForQueryAndWorkerReplay(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestBroadFailureCatchWorkflow::class, 'activity-cancelled-terminal-history');
        $workflow->start('order-123');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->where('sequence', 1)
            ->firstOrFail();
        /** @var WorkflowTask $activityTask */
        $activityTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        ActivityCancellation::record($run, $execution, $activityTask);

        $state = $workflow->refresh()
            ->currentState();

        $this->assertSame('waiting-for-resume', $state['stage']);
        $this->assertSame(\RuntimeException::class, $state['caught']['class']);
        $this->assertSame('Activity cancelled', $state['caught']['message']);

        WorkflowTask::query()->create([
            'workflow_run_id' => $runId,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => [
                'workflow_wait_kind' => 'activity',
                'activity_execution_id' => $execution->id,
            ],
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
        ]);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $runId,
            'event_type' => HistoryEventType::SignalWaitOpened->value,
        ]);

        $detail = RunDetailView::forRun(WorkflowRun::query()->with('summary')->findOrFail($runId));

        $this->assertSame('signal', $detail['wait_kind']);
        $this->assertSame('waiting-for-resume', $workflow->refresh()->currentState()['stage']);
    }

    public function testActivityHeartbeatRenewsCurrentAttemptLease(): void
    {
        $startedAt = Carbon::parse('2026-04-08 12:00:00');
        Carbon::setTestNow($startedAt);

        try {
            $runId = (string) Str::ulid();
            $executionId = (string) Str::ulid();
            $taskId = (string) Str::ulid();
            $attemptId = (string) Str::ulid();

            $instance = WorkflowInstance::query()->create([
                'id' => 'heartbeat-lease-instance',
                'workflow_class' => TestHeartbeatWorkflow::class,
                'workflow_type' => 'test-heartbeat-workflow',
                'run_count' => 1,
            ]);

            $run = WorkflowRun::query()->create([
                'id' => $runId,
                'workflow_instance_id' => $instance->id,
                'run_number' => 1,
                'workflow_class' => TestHeartbeatWorkflow::class,
                'workflow_type' => 'test-heartbeat-workflow',
                'status' => RunStatus::Running->value,
                'compatibility' => 'build-heartbeat',
                'payload_codec' => config('workflows.serializer'),
                'connection' => 'redis',
                'queue' => 'default',
                'started_at' => $startedAt,
                'last_progress_at' => $startedAt,
            ]);

            $instance->forceFill([
                'current_run_id' => $run->id,
                'started_at' => $startedAt,
            ])->save();

            $execution = ActivityExecution::query()->create([
                'id' => $executionId,
                'workflow_run_id' => $run->id,
                'sequence' => 1,
                'activity_class' => TestHeartbeatActivity::class,
                'activity_type' => TestHeartbeatActivity::class,
                'status' => ActivityStatus::Running->value,
                'connection' => 'redis',
                'queue' => 'default',
                'attempt_count' => 1,
                'current_attempt_id' => $attemptId,
                'started_at' => $startedAt,
            ]);

            $attempt = ActivityAttempt::query()->create([
                'id' => $attemptId,
                'workflow_run_id' => $run->id,
                'activity_execution_id' => $execution->id,
                'workflow_task_id' => $taskId,
                'attempt_number' => 1,
                'status' => 'running',
                'lease_owner' => 'lease-owner-heartbeat',
                'started_at' => $startedAt,
                'lease_expires_at' => ActivityLease::expiresAt(),
            ]);

            $task = WorkflowTask::query()->create([
                'id' => $taskId,
                'workflow_run_id' => $run->id,
                'task_type' => TaskType::Activity->value,
                'status' => TaskStatus::Leased->value,
                'payload' => [
                    'activity_execution_id' => $execution->id,
                ],
                'connection' => 'redis',
                'queue' => 'default',
                'compatibility' => 'build-heartbeat',
                'leased_at' => $startedAt,
                'lease_owner' => 'lease-owner-heartbeat',
                'lease_expires_at' => ActivityLease::expiresAt(),
                'attempt_count' => 1,
            ]);

            RunSummaryProjector::project($run->fresh([
                'instance',
                'tasks',
                'activityExecutions',
                'timers',
                'failures',
                'historyEvents',
            ]));

            $heartbeatAt = $startedAt->copy()
                ->addMinutes(2);
            $leaseExpiresAt = $heartbeatAt->copy()
                ->addMinutes(ActivityLease::DURATION_MINUTES);
            Carbon::setTestNow($heartbeatAt);

            $expectedProgress = [
                'message' => 'Polling remote job',
                'current' => 1,
                'total' => 3,
                'unit' => 'steps',
                'details' => [
                    'phase' => 'poll',
                    'remote_state' => 'running',
                ],
            ];

            $activity = new TestHeartbeatActivity($execution->fresh(), $run->fresh(), $task->id);
            $activity->heartbeat($expectedProgress);

            $this->assertSame($attemptId, $activity->attemptId());
            $this->assertSame(1, $activity->attemptCount());

            $execution->refresh();
            $attempt->refresh();
            $task->refresh();

            /** @var WorkflowRunSummary $summary */
            $summary = WorkflowRunSummary::query()->findOrFail($run->id);

            $this->assertSame($heartbeatAt->jsonSerialize(), $execution->last_heartbeat_at?->jsonSerialize());
            $this->assertSame($heartbeatAt->jsonSerialize(), $attempt->last_heartbeat_at?->jsonSerialize());
            $this->assertSame($leaseExpiresAt->jsonSerialize(), $attempt->lease_expires_at?->jsonSerialize());
            $this->assertSame($leaseExpiresAt->jsonSerialize(), $task->lease_expires_at?->jsonSerialize());
            $this->assertSame($leaseExpiresAt->jsonSerialize(), $summary->next_task_lease_expires_at?->jsonSerialize());

            /** @var WorkflowHistoryEvent $heartbeat */
            $heartbeat = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded->value)
                ->sole();

            $this->assertSame($execution->id, $heartbeat->payload['activity_execution_id'] ?? null);
            $this->assertSame($attempt->id, $heartbeat->payload['activity_attempt_id'] ?? null);
            $this->assertSame($heartbeatAt->toJSON(), $heartbeat->payload['heartbeat_at'] ?? null);
            $this->assertSame($leaseExpiresAt->toJSON(), $heartbeat->payload['lease_expires_at'] ?? null);
            $this->assertSame($expectedProgress, $heartbeat->payload['progress'] ?? null);
            $this->assertSame($heartbeatAt->toJSON(), $heartbeat->payload['activity']['last_heartbeat_at'] ?? null);
            $this->assertSame($task->id, $heartbeat->workflow_task_id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testStaleActivityHeartbeatDoesNotMutateReclaimedCurrentAttempt(): void
    {
        $startedAt = Carbon::parse('2026-04-08 12:00:00');
        Carbon::setTestNow($startedAt);

        try {
            $runId = (string) Str::ulid();
            $executionId = (string) Str::ulid();
            $oldTaskId = (string) Str::ulid();
            $newTaskId = (string) Str::ulid();
            $oldAttemptId = (string) Str::ulid();
            $newAttemptId = (string) Str::ulid();

            $instance = WorkflowInstance::query()->create([
                'id' => 'heartbeat-stale-attempt-instance',
                'workflow_class' => TestHeartbeatWorkflow::class,
                'workflow_type' => 'test-heartbeat-workflow',
                'run_count' => 1,
            ]);

            $run = WorkflowRun::query()->create([
                'id' => $runId,
                'workflow_instance_id' => $instance->id,
                'run_number' => 1,
                'workflow_class' => TestHeartbeatWorkflow::class,
                'workflow_type' => 'test-heartbeat-workflow',
                'status' => RunStatus::Running->value,
                'compatibility' => 'build-heartbeat',
                'payload_codec' => config('workflows.serializer'),
                'connection' => 'redis',
                'queue' => 'default',
                'started_at' => $startedAt,
                'last_progress_at' => $startedAt,
            ]);

            $instance->forceFill([
                'current_run_id' => $run->id,
                'started_at' => $startedAt,
            ])->save();

            $execution = ActivityExecution::query()->create([
                'id' => $executionId,
                'workflow_run_id' => $run->id,
                'sequence' => 1,
                'activity_class' => TestHeartbeatActivity::class,
                'activity_type' => TestHeartbeatActivity::class,
                'status' => ActivityStatus::Running->value,
                'connection' => 'redis',
                'queue' => 'default',
                'attempt_count' => 1,
                'current_attempt_id' => $oldAttemptId,
                'started_at' => $startedAt,
            ]);

            ActivityAttempt::query()->create([
                'id' => $oldAttemptId,
                'workflow_run_id' => $run->id,
                'activity_execution_id' => $execution->id,
                'workflow_task_id' => $oldTaskId,
                'attempt_number' => 1,
                'status' => 'running',
                'lease_owner' => 'old-heartbeat-owner',
                'started_at' => $startedAt,
                'lease_expires_at' => ActivityLease::expiresAt(),
            ]);

            $oldTask = WorkflowTask::query()->create([
                'id' => $oldTaskId,
                'workflow_run_id' => $run->id,
                'task_type' => TaskType::Activity->value,
                'status' => TaskStatus::Leased->value,
                'payload' => [
                    'activity_execution_id' => $execution->id,
                ],
                'connection' => 'redis',
                'queue' => 'default',
                'compatibility' => 'build-heartbeat',
                'leased_at' => $startedAt,
                'lease_owner' => 'old-heartbeat-owner',
                'lease_expires_at' => ActivityLease::expiresAt(),
                'attempt_count' => 1,
            ]);

            $activity = new TestHeartbeatActivity($execution->fresh(), $run->fresh(), $oldTask->id);

            $reclaimedAt = $startedAt->copy()
                ->addMinutes(6);
            Carbon::setTestNow($reclaimedAt);

            ActivityAttempt::query()
                ->whereKey($oldAttemptId)
                ->update([
                    'status' => 'expired',
                    'lease_expires_at' => null,
                    'closed_at' => $reclaimedAt,
                ]);

            $oldTask->forceFill([
                'status' => TaskStatus::Completed,
                'lease_expires_at' => null,
            ])->save();

            $execution->forceFill([
                'attempt_count' => 2,
                'current_attempt_id' => $newAttemptId,
                'last_heartbeat_at' => null,
            ])->save();

            ActivityAttempt::query()->create([
                'id' => $newAttemptId,
                'workflow_run_id' => $run->id,
                'activity_execution_id' => $execution->id,
                'workflow_task_id' => $newTaskId,
                'attempt_number' => 2,
                'status' => 'running',
                'lease_owner' => 'new-heartbeat-owner',
                'started_at' => $reclaimedAt,
                'lease_expires_at' => ActivityLease::expiresAt(),
            ]);

            WorkflowTask::query()->create([
                'id' => $newTaskId,
                'workflow_run_id' => $run->id,
                'task_type' => TaskType::Activity->value,
                'status' => TaskStatus::Leased->value,
                'payload' => [
                    'activity_execution_id' => $execution->id,
                ],
                'connection' => 'redis',
                'queue' => 'default',
                'compatibility' => 'build-heartbeat',
                'leased_at' => $reclaimedAt,
                'lease_owner' => 'new-heartbeat-owner',
                'lease_expires_at' => ActivityLease::expiresAt(),
                'attempt_count' => 2,
            ]);

            $lateHeartbeatAt = $reclaimedAt->copy()
                ->addMinute();
            Carbon::setTestNow($lateHeartbeatAt);

            $activity->heartbeat();

            /** @var ActivityExecution $freshExecution */
            $freshExecution = ActivityExecution::query()->findOrFail($execution->id);
            /** @var ActivityAttempt $newAttempt */
            $newAttempt = ActivityAttempt::query()->findOrFail($newAttemptId);

            $this->assertNull($freshExecution->last_heartbeat_at);
            $this->assertNull($newAttempt->last_heartbeat_at);
            $this->assertSame(0, WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded->value)
                ->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testActivityHeartbeatBackfillsLegacyCurrentAttemptIdentity(): void
    {
        $startedAt = Carbon::parse('2026-04-08 12:00:00');
        Carbon::setTestNow($startedAt);

        try {
            $runId = (string) Str::ulid();
            $executionId = (string) Str::ulid();
            $taskId = (string) Str::ulid();

            $instance = WorkflowInstance::query()->create([
                'id' => 'heartbeat-legacy-attempt-instance',
                'workflow_class' => TestHeartbeatWorkflow::class,
                'workflow_type' => 'test-heartbeat-workflow',
                'run_count' => 1,
            ]);

            $run = WorkflowRun::query()->create([
                'id' => $runId,
                'workflow_instance_id' => $instance->id,
                'run_number' => 1,
                'workflow_class' => TestHeartbeatWorkflow::class,
                'workflow_type' => 'test-heartbeat-workflow',
                'status' => RunStatus::Running->value,
                'compatibility' => 'build-heartbeat',
                'payload_codec' => config('workflows.serializer'),
                'connection' => 'redis',
                'queue' => 'default',
                'started_at' => $startedAt,
                'last_progress_at' => $startedAt,
            ]);

            $instance->forceFill([
                'current_run_id' => $run->id,
                'started_at' => $startedAt,
            ])->save();

            $execution = ActivityExecution::query()->create([
                'id' => $executionId,
                'workflow_run_id' => $run->id,
                'sequence' => 1,
                'activity_class' => TestHeartbeatActivity::class,
                'activity_type' => TestHeartbeatActivity::class,
                'status' => ActivityStatus::Running->value,
                'connection' => 'redis',
                'queue' => 'default',
                'attempt_count' => 0,
                'started_at' => $startedAt,
            ]);

            $task = WorkflowTask::query()->create([
                'id' => $taskId,
                'workflow_run_id' => $run->id,
                'task_type' => TaskType::Activity->value,
                'status' => TaskStatus::Leased->value,
                'payload' => [
                    'activity_execution_id' => $execution->id,
                ],
                'connection' => 'redis',
                'queue' => 'default',
                'compatibility' => 'build-heartbeat',
                'leased_at' => $startedAt,
                'lease_owner' => 'legacy-heartbeat-owner',
                'lease_expires_at' => ActivityLease::expiresAt(),
                'attempt_count' => 1,
            ]);

            RunSummaryProjector::project($run->fresh([
                'instance',
                'tasks',
                'activityExecutions',
                'timers',
                'failures',
                'historyEvents',
            ]));

            $heartbeatAt = $startedAt->copy()
                ->addMinutes(2);
            $leaseExpiresAt = $heartbeatAt->copy()
                ->addMinutes(ActivityLease::DURATION_MINUTES);
            Carbon::setTestNow($heartbeatAt);

            $activity = new TestHeartbeatActivity($execution->fresh(), $run->fresh(), $task->id);
            $activity->heartbeat();

            $execution->refresh();
            $task->refresh();

            /** @var ActivityAttempt $attempt */
            $attempt = ActivityAttempt::query()
                ->where('activity_execution_id', $execution->id)
                ->firstOrFail();

            /** @var WorkflowRunSummary $summary */
            $summary = WorkflowRunSummary::query()->findOrFail($run->id);

            $this->assertNotNull($execution->current_attempt_id);
            $this->assertSame($execution->current_attempt_id, $activity->attemptId());
            $this->assertSame($execution->current_attempt_id, $attempt->id);
            $this->assertSame(1, $execution->attempt_count);
            $this->assertSame(1, $task->attempt_count);
            $this->assertSame(1, $activity->attemptCount());
            $this->assertSame(1, $attempt->attempt_number);
            $this->assertSame('running', $attempt->status->value);
            $this->assertSame($task->id, $attempt->workflow_task_id);
            $this->assertSame('legacy-heartbeat-owner', $attempt->lease_owner);
            $this->assertSame($heartbeatAt->jsonSerialize(), $execution->last_heartbeat_at?->jsonSerialize());
            $this->assertSame($heartbeatAt->jsonSerialize(), $attempt->last_heartbeat_at?->jsonSerialize());
            $this->assertSame($leaseExpiresAt->jsonSerialize(), $attempt->lease_expires_at?->jsonSerialize());
            $this->assertSame($leaseExpiresAt->jsonSerialize(), $task->lease_expires_at?->jsonSerialize());
            $this->assertSame($leaseExpiresAt->jsonSerialize(), $summary->next_task_lease_expires_at?->jsonSerialize());

            /** @var WorkflowHistoryEvent $heartbeat */
            $heartbeat = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded->value)
                ->sole();

            $this->assertSame($execution->id, $heartbeat->payload['activity_execution_id'] ?? null);
            $this->assertSame($attempt->id, $heartbeat->payload['activity_attempt_id'] ?? null);
            $this->assertSame($heartbeatAt->toJSON(), $heartbeat->payload['heartbeat_at'] ?? null);
            $this->assertSame($task->id, $heartbeat->workflow_task_id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testParentCompletesAfterChildContinuesAsNewWithoutWorkflowLinks(): void
    {
        Queue::fake();
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');

        $instanceId = 'child-continue-history';

        $workflow = WorkflowStub::make(TestParentWaitingOnContinuingChildWorkflow::class, $instanceId);
        $workflow->start(0, 1);

        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $deadline = microtime(true) + 10;

        while (WorkflowRun::query()
            ->where('workflow_type', 'test-continue-as-new-workflow')
            ->count() < 2) {
            if (microtime(true) >= $deadline) {
                $this->fail('Timed out waiting for the child workflow to continue as new.');
            }

            $this->runNextReadyTask();
        }

        /** @var WorkflowHistoryEvent $childStarted */
        $childStarted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ChildRunStarted')
            ->orderBy('sequence')
            ->firstOrFail();
        $childInstanceId = $childStarted->payload['child_workflow_instance_id'] ?? null;
        $childCallId = $childStarted->payload['child_call_id'] ?? null;

        $this->assertIsString($childInstanceId);
        $this->assertIsString($childCallId);

        /** @var WorkflowRun $currentChildRun */
        $currentChildRun = WorkflowRun::query()
            ->where('workflow_instance_id', $childInstanceId)
            ->orderByDesc('run_number')
            ->firstOrFail();

        WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->delete();

        $this->drainReadyTasks();
        $workflow->refresh();

        $this->assertTrue($workflow->completed());
        $this->assertSame(2, WorkflowRun::query() ->where('workflow_instance_id', $childInstanceId) ->count());
        $this->assertSame([
            'parent_workflow_id' => $instanceId,
            'parent_run_id' => $parentRunId,
            'child' => [
                'count' => 1,
                'workflow_id' => $childInstanceId,
                'run_id' => $currentChildRun->id,
            ],
        ], $workflow->output());

        $childStarts = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ChildRunStarted')
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $childStarts);
        $this->assertSame($childCallId, $childStarts[0]->payload['child_call_id'] ?? null);
        $this->assertSame($childCallId, $childStarts[1]->payload['child_call_id'] ?? null);
        $this->assertSame($currentChildRun->id, $childStarts[1]->payload['child_workflow_run_id'] ?? null);
        $this->assertSame($currentChildRun->run_number, $childStarts[1]->payload['child_run_number'] ?? null);

        /** @var WorkflowHistoryEvent $childCompleted */
        $childCompleted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ChildRunCompleted')
            ->orderByDesc('sequence')
            ->firstOrFail();
        /** @var WorkflowCommand $currentChildStart */
        $currentChildStart = WorkflowCommand::query()
            ->where('workflow_run_id', $currentChildRun->id)
            ->where('command_type', 'start')
            ->sole();

        $this->assertSame($currentChildRun->id, $childCompleted->payload['child_workflow_run_id'] ?? null);
        $this->assertSame($childCallId, $childCompleted->payload['child_call_id'] ?? null);
        $this->assertSame($childCallId, $currentChildStart->commandContext()['workflow']['child_call_id'] ?? null);
    }

    public function testLoadSelectionCanPinHistoricalRunWithinOneInstance(): void
    {
        Queue::fake();
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');

        $workflow = WorkflowStub::make(TestContinueAsNewWorkflow::class, 'selection-instance');
        $workflow->start(0, 1);

        $this->drainReadyTasks();
        $workflow->refresh();

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'selection-instance')
            ->orderBy('run_number')
            ->get();

        /** @var WorkflowRun $historicalRun */
        $historicalRun = $runs[0];
        /** @var WorkflowRun $currentRun */
        $currentRun = $runs[1];

        $selected = WorkflowStub::loadSelection('selection-instance', $historicalRun->id);

        $this->assertSame('selection-instance', $selected->id());
        $this->assertSame($historicalRun->id, $selected->runId());
        $this->assertSame($currentRun->id, $selected->currentRunId());
        $this->assertFalse($selected->currentRunIsSelected());
        $this->assertSame('completed', $selected->status());

        $current = WorkflowStub::loadSelection('selection-instance');

        $this->assertSame('selection-instance', $current->id());
        $this->assertSame($currentRun->id, $current->runId());
        $this->assertSame($currentRun->id, $current->currentRunId());
        $this->assertTrue($current->currentRunIsSelected());

        $this->assertSame(
            $historicalRun->id,
            SelectedRunLocator::forInstanceIdOrFail('selection-instance', $historicalRun->id)->id,
        );
        $this->assertSame($currentRun->id, SelectedRunLocator::forInstanceIdOrFail('selection-instance')->id);
        $this->assertSame($currentRun->id, SelectedRunLocator::forIdOrFail('selection-instance')->id);
        $this->assertSame($historicalRun->id, SelectedRunLocator::forIdOrFail($historicalRun->id)->id);
    }

    public function testSelectedRunLocatorPrefersContinueAsNewLineageWhenCurrentRunPointerIsMissing(): void
    {
        Queue::fake();
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');

        $workflow = WorkflowStub::make(TestContinueAsNewWorkflow::class, 'selection-locator-lineage');
        $workflow->start(0, 1);

        $this->drainReadyTasks();
        $workflow->refresh();

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'selection-locator-lineage')
            ->orderBy('run_number')
            ->get();

        /** @var WorkflowRun $historicalRun */
        $historicalRun = $runs[0];
        /** @var WorkflowRun $continuedRun */
        $continuedRun = $runs[1];

        WorkflowRun::query()->create([
            'workflow_instance_id' => $historicalRun->workflow_instance_id,
            'run_number' => $continuedRun->run_number + 1,
            'workflow_class' => $continuedRun->workflow_class,
            'workflow_type' => $continuedRun->workflow_type,
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([999, 1000]),
            'connection' => $continuedRun->connection,
            'queue' => $continuedRun->queue,
            'started_at' => now()
                ->addMinute(),
            'last_progress_at' => now()
                ->addMinute(),
        ]);

        WorkflowInstance::query()
            ->findOrFail($historicalRun->workflow_instance_id)
            ->forceFill([
                'current_run_id' => null,
            ])
            ->save();

        $resolved = SelectedRunLocator::forInstanceIdOrFail('selection-locator-lineage');

        $this->assertSame($continuedRun->id, $resolved->id);
    }

    public function testPhpApiCommandsRecordDurableCommandContext(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'command-context-php');
        $result = $workflow->attemptStart('Taylor');

        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::query()->findOrFail($result->commandId());

        $this->assertSame('php', $command->source);
        $this->assertSame('PHP API', $command->callerLabel());
        $this->assertSame('not_applicable', $command->authStatus());
        $this->assertSame('none', $command->authMethod());
        $this->assertSame('php', $command->commandContext()['caller']['type'] ?? null);
    }

    public function testAttemptStartReturnsRejectedResultForDuplicateStart(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class);
        $accepted = $workflow->attemptStart();

        $this->assertTrue($accepted->accepted());
        $this->assertSame('started_new', $accepted->outcome());

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $rejected = $workflow->attemptStart();

        $this->assertTrue($rejected->rejected());
        $this->assertTrue($rejected->rejectedDuplicate());
        $this->assertSame('rejected_duplicate', $rejected->outcome());
        $this->assertSame('instance_already_started', $rejected->rejectionReason());
        $this->assertSame($workflow->id(), $rejected->instanceId());
        $this->assertSame($workflow->runId(), $rejected->runId());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $rejected->commandId(),
            'workflow_instance_id' => $workflow->id(),
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'start',
            'status' => 'rejected',
            'outcome' => 'rejected_duplicate',
            'rejection_reason' => 'instance_already_started',
        ]);

        $this->assertSame(
            ['StartAccepted', 'WorkflowStarted', 'SignalWaitOpened', 'StartRejected'],
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $workflow->runId())
                ->orderBy('sequence')
                ->pluck('event_type')
                ->map(static fn ($eventType) => $eventType->value)
                ->all()
        );
    }

    public function testAttemptStartCanReturnExistingActiveRunWhenRequested(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'order-123');

        $accepted = $workflow->attemptStart();

        $this->assertTrue($accepted->accepted());
        $this->assertSame('started_new', $accepted->outcome());

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $reused = $workflow->attemptStart(StartOptions::returnExistingActive());

        $this->assertTrue($reused->accepted());
        $this->assertTrue($reused->returnedExistingActive());
        $this->assertSame('returned_existing_active', $reused->outcome());
        $this->assertNull($reused->rejectionReason());
        $this->assertSame('order-123', $reused->instanceId());
        $this->assertSame($accepted->runId(), $reused->runId());
        $this->assertNull($reused->requestedRunId());
        $this->assertSame($accepted->runId(), $reused->resolvedRunId());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $reused->commandId(),
            'workflow_instance_id' => 'order-123',
            'workflow_run_id' => $accepted->runId(),
            'command_type' => 'start',
            'status' => 'accepted',
            'outcome' => 'returned_existing_active',
        ]);

        $this->assertSame(
            ['StartAccepted', 'WorkflowStarted', 'SignalWaitOpened', 'StartAccepted'],
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $accepted->runId())
                ->orderBy('sequence')
                ->pluck('event_type')
                ->map(static fn ($eventType) => $eventType->value)
                ->all()
        );
    }

    public function testStartOptionsPersistVisibilityFieldsOnRunSummaryAndStartHistory(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'visible-order');
        $result = $workflow->attemptStart(
            'Taylor',
            StartOptions::withVisibility(
                businessKey: 'order-123',
                labels: [
                    'region' => 'us-east',
                    'tenant' => 'acme',
                ],
            )->withMemo([
                'customer' => [
                    'name' => 'Taylor',
                    'vip' => true,
                ],
                'line_items' => [123, 456],
            ]),
        );

        $runId = $workflow->runId();

        $this->assertTrue($result->accepted());
        $this->assertIsString($runId);
        $this->assertSame('order-123', $workflow->businessKey());
        $this->assertSame([
            'region' => 'us-east',
            'tenant' => 'acme',
        ], $workflow->visibilityLabels());
        $this->assertSame([
            'customer' => [
                'name' => 'Taylor',
                'vip' => true,
            ],
            'line_items' => [123, 456],
        ], $workflow->memo());

        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->findOrFail('visible-order');
        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        /** @var WorkflowRunSummary $summary */
        $summary = WorkflowRunSummary::query()->findOrFail($runId);
        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();

        $this->assertSame('order-123', $instance->business_key);
        $this->assertSame([
            'region' => 'us-east',
            'tenant' => 'acme',
        ], $instance->visibility_labels);
        $this->assertSame([
            'customer' => [
                'name' => 'Taylor',
                'vip' => true,
            ],
            'line_items' => [123, 456],
        ], $instance->memo);
        $this->assertSame('order-123', $run->business_key);
        $this->assertSame([
            'region' => 'us-east',
            'tenant' => 'acme',
        ], $run->visibility_labels);
        $this->assertSame([
            'customer' => [
                'name' => 'Taylor',
                'vip' => true,
            ],
            'line_items' => [123, 456],
        ], $run->memo);
        $this->assertSame('order-123', $summary->business_key);
        $this->assertSame([
            'region' => 'us-east',
            'tenant' => 'acme',
        ], $summary->visibility_labels);
        $this->assertSame('order-123', $started->payload['business_key'] ?? null);
        $this->assertSame([
            'region' => 'us-east',
            'tenant' => 'acme',
        ], $started->payload['visibility_labels'] ?? null);
        $this->assertSame([
            'customer' => [
                'name' => 'Taylor',
                'vip' => true,
            ],
            'line_items' => [123, 456],
        ], $started->payload['memo'] ?? null);

        $detail = RunDetailView::forRun($run->fresh(['summary', 'instance.runs.summary']));
        $export = $workflow->historyExport();

        $this->assertSame('order-123', $detail['business_key']);
        $this->assertSame([
            'region' => 'us-east',
            'tenant' => 'acme',
        ], $detail['visibility_labels']);
        $this->assertSame([
            'customer' => [
                'name' => 'Taylor',
                'vip' => true,
            ],
            'line_items' => [123, 456],
        ], $detail['memo']);
        $this->assertSame('order-123', $export['workflow']['business_key']);
        $this->assertSame([
            'region' => 'us-east',
            'tenant' => 'acme',
        ], $export['workflow']['visibility_labels']);
        $this->assertSame([
            'customer' => [
                'name' => 'Taylor',
                'vip' => true,
            ],
            'line_items' => [123, 456],
        ], $export['workflow']['memo']);
        $this->assertSame('order-123', $export['summary']['business_key']);
        $this->assertSame([
            'region' => 'us-east',
            'tenant' => 'acme',
        ], $export['summary']['visibility_labels']);
    }

    public function testMakeRejectsBlankCallerSuppliedInstanceId(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(WorkflowInstanceId::requirementMessage());

        WorkflowStub::make(TestGreetingWorkflow::class, '   ');
    }

    public function testMakeRejectsOverlongCallerSuppliedInstanceIdWithoutCreatingReservation(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(WorkflowInstanceId::requirementMessage());

        try {
            WorkflowStub::make(TestGreetingWorkflow::class, str_repeat('a', WorkflowInstanceId::MAX_LENGTH + 1));
        } finally {
            $this->assertSame(0, WorkflowInstance::query()->count());
            $this->assertSame(0, WorkflowCommand::query()->count());
        }
    }

    public function testMakeRejectsCallerSuppliedInstanceIdWithUnsupportedCharacters(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(WorkflowInstanceId::requirementMessage());

        WorkflowStub::make(TestGreetingWorkflow::class, 'order/123');
    }

    public function testMakeAcceptsLongRouteSafeCallerSuppliedInstanceId(): void
    {
        config()->set('queue.default', 'redis');

        $instanceId = 'tenant.alpha:' . str_repeat('a', WorkflowInstanceId::MAX_LENGTH - strlen('tenant.alpha:'));

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, $instanceId);
        $result = $workflow->start('Taylor');

        $this->assertSame($instanceId, $workflow->id());
        $this->assertSame($instanceId, $result->instanceId());
        $this->assertDatabaseHas('workflow_instances', [
            'id' => $instanceId,
        ]);
    }

    public function testConfiguredTypeMapPersistsWorkflowAliasOnStartedRuns(): void
    {
        $this->configureGreetingTypeMaps();

        $workflow = WorkflowStub::make(TestConfiguredGreetingWorkflow::class);
        $result = $workflow->start('Taylor');

        $runId = $result->runId();

        $this->assertNotNull($runId);
        $this->assertDatabaseHas('workflow_runs', [
            'id' => $runId,
            'workflow_type' => 'config-greeting-workflow',
        ]);
    }

    public function testRunSummaryProjectsStableSortContract(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'sort-contract');
        $result = $workflow->start('Taylor');
        $runId = $result->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        $summary = $workflow->summary();

        $this->assertNotNull($summary);
        $this->assertNotNull($summary->sort_timestamp);
        $this->assertSame($run->started_at?->toJSON(), $summary->sort_timestamp?->toJSON());
        $this->assertSame(
            RunSummarySortKey::key($run->started_at, $run->created_at, $run->updated_at, $runId),
            $summary->sort_key,
        );
    }

    public function testWorkflowCanContinueAsNewAcrossRuns(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestContinueAsNewWorkflow::class, 'continue-instance');
        $started = $workflow->start(
            0,
            2,
            StartOptions::withVisibility(businessKey: 'order-continue', labels: [
                'tenant' => 'acme',
            ],)->withMemo([
                'customer' => [
                    'name' => 'Taylor',
                    'tier' => 'gold',
                ],
            ]),
        );
        $firstRunId = $started->runId();

        $this->assertNotNull($firstRunId);

        $this->drainReadyTasks();
        $workflow->refresh();

        $finalRunId = $workflow->runId();

        $this->assertNotNull($finalRunId);
        $this->assertTrue($workflow->completed());
        $this->assertNotSame($firstRunId, $finalRunId);
        $this->assertSame('continue-instance', $workflow->id());
        $this->assertSame([
            'count' => 2,
            'workflow_id' => 'continue-instance',
            'run_id' => $finalRunId,
        ], $workflow->output());

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'continue-instance')
            ->orderBy('run_number')
            ->get();

        $this->assertCount(3, $runs);
        $this->assertSame([1, 2, 3], $runs->pluck('run_number')->all());
        $this->assertSame(['order-continue', 'order-continue', 'order-continue'], $runs->pluck('business_key')->all());
        $this->assertSame([
            [
                'tenant' => 'acme',
            ],
            [
                'tenant' => 'acme',
            ],
            [
                'tenant' => 'acme',
            ],
        ], $runs->map(static fn (WorkflowRun $run): ?array => $run->visibility_labels)
            ->all());
        $this->assertSame([
            [
                'customer' => [
                    'name' => 'Taylor',
                    'tier' => 'gold',
                ],
            ],
            [
                'customer' => [
                    'name' => 'Taylor',
                    'tier' => 'gold',
                ],
            ],
            [
                'customer' => [
                    'name' => 'Taylor',
                    'tier' => 'gold',
                ],
            ],
        ], $runs->map(static fn (WorkflowRun $run): ?array => $run->memo)
            ->all());
        $this->assertSame(['completed', 'completed', 'completed'], $runs->pluck('status')->map(
            static fn (RunStatus $status): string => $status->value
        )->all());
        $this->assertSame(['continued', 'continued', 'completed'], $runs->pluck('closed_reason')->all());

        $this->assertDatabaseHas('workflow_instances', [
            'id' => 'continue-instance',
            'current_run_id' => $finalRunId,
            'run_count' => 3,
        ]);

        $this->assertSame(2, WorkflowLink::query()->count());
        $this->assertDatabaseHas('workflow_links', [
            'link_type' => 'continue_as_new',
            'parent_workflow_instance_id' => 'continue-instance',
            'parent_workflow_run_id' => $runs[0]->id,
            'child_workflow_instance_id' => 'continue-instance',
            'child_workflow_run_id' => $runs[1]->id,
            'is_primary_parent' => true,
        ]);
        $this->assertDatabaseHas('workflow_links', [
            'link_type' => 'continue_as_new',
            'parent_workflow_instance_id' => 'continue-instance',
            'parent_workflow_run_id' => $runs[1]->id,
            'child_workflow_instance_id' => 'continue-instance',
            'child_workflow_run_id' => $runs[2]->id,
            'is_primary_parent' => true,
        ]);

        /** @var WorkflowCommand $secondRunStart */
        $secondRunStart = WorkflowCommand::query()
            ->where('workflow_run_id', $runs[1]->id)
            ->where('command_type', 'start')
            ->sole();
        /** @var WorkflowCommand $thirdRunStart */
        $thirdRunStart = WorkflowCommand::query()
            ->where('workflow_run_id', $runs[2]->id)
            ->where('command_type', 'start')
            ->sole();

        $this->assertSame('workflow', $secondRunStart->source);
        $this->assertSame('Workflow', $secondRunStart->callerLabel());
        $this->assertSame([
            'parent_instance_id' => 'continue-instance',
            'parent_run_id' => $runs[0]->id,
            'sequence' => 2,
        ], $secondRunStart->commandContext()['workflow']);
        $this->assertSame(1, $secondRunStart->command_sequence);

        $this->assertSame('workflow', $thirdRunStart->source);
        $this->assertSame('Workflow', $thirdRunStart->callerLabel());
        $this->assertSame([
            'parent_instance_id' => 'continue-instance',
            'parent_run_id' => $runs[1]->id,
            'sequence' => 2,
        ], $thirdRunStart->commandContext()['workflow']);
        $this->assertSame(1, $thirdRunStart->command_sequence);

        /** @var WorkflowHistoryEvent $thirdRunStarted */
        $thirdRunStarted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runs[2]->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->sole();

        $this->assertSame('order-continue', $thirdRunStarted->payload['business_key'] ?? null);
        $this->assertSame([
            'tenant' => 'acme',
        ], $thirdRunStarted->payload['visibility_labels'] ?? null);
        $this->assertSame([
            'customer' => [
                'name' => 'Taylor',
                'tier' => 'gold',
            ],
        ], $thirdRunStarted->payload['memo'] ?? null);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ActivityScheduled',
            'ActivityStarted',
            'ActivityCompleted',
            'WorkflowContinuedAsNew',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runs[0]->id)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ActivityScheduled',
            'ActivityStarted',
            'ActivityCompleted',
            'WorkflowContinuedAsNew',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runs[1]->id)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ActivityScheduled',
            'ActivityStarted',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runs[2]->id)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testWorkflowCanWaitForChildWorkflowAndCompleteWithChildOutput(): void
    {
        $workflow = WorkflowStub::make(TestParentChildWorkflow::class, 'parent-child-instance');
        $started = $workflow->start('Taylor');
        $parentRunId = $started->runId();

        $this->assertNotNull($parentRunId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->sole();

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);

        $this->assertSame(1, $link->sequence);
        $this->assertSame('completed', $childRun->status->value);
        $this->assertNotSame('parent-child-instance', $link->child_workflow_instance_id);
        $this->assertNotSame($parentRunId, $childRun->id);
        $this->assertSame([
            'parent_workflow_id' => 'parent-child-instance',
            'parent_run_id' => $parentRunId,
            'child' => [
                'greeting' => 'Hello, Taylor!',
                'workflow_id' => $link->child_workflow_instance_id,
                'run_id' => $childRun->id,
            ],
        ], $workflow->output());

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ChildWorkflowScheduled',
            'ChildRunStarted',
            'ChildRunCompleted',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
        /** @var WorkflowHistoryEvent $childScheduled */
        $childScheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ChildWorkflowScheduled')
            ->sole();
        /** @var WorkflowHistoryEvent $childCompleted */
        $childCompleted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ChildRunCompleted')
            ->sole();

        /** @var WorkflowCommand $childStart */
        $childStart = WorkflowCommand::query()
            ->where('workflow_run_id', $childRun->id)
            ->where('command_type', 'start')
            ->sole();

        $this->assertSame('workflow', $childStart->source);
        $this->assertSame('Workflow', $childStart->callerLabel());
        $this->assertSame([
            'parent_instance_id' => 'parent-child-instance',
            'parent_run_id' => $parentRunId,
            'sequence' => 1,
            'child_call_id' => $link->id,
        ], $childStart->commandContext()['workflow']);
        $this->assertSame(1, $childStart->command_sequence);
        $this->assertSame($link->id, $childScheduled->payload['child_call_id'] ?? null);
        $this->assertSame($link->id, $childCompleted->payload['child_call_id'] ?? null);
        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ActivityScheduled',
            'ActivityStarted',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $childRun->id)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $this->assertDatabaseHas('workflow_links', [
            'id' => $link->id,
            'link_type' => 'child_workflow',
            'parent_workflow_instance_id' => 'parent-child-instance',
            'parent_workflow_run_id' => $parentRunId,
            'child_workflow_instance_id' => $link->child_workflow_instance_id,
            'child_workflow_run_id' => $childRun->id,
            'sequence' => 1,
            'is_primary_parent' => true,
        ]);
    }

    public function testWorkflowSummaryProjectsChildWaitAndHealthyRepairNoOp(): void
    {
        $workflow = WorkflowStub::make(TestParentWaitingOnChildWorkflow::class, 'parent-child-waiting');
        $workflow->start(60);
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'child');

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->sole();

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);
        $summary = $workflow->summary();

        $this->assertSame('waiting', $workflow->status());
        $this->assertNotNull($summary);
        $this->assertSame('child', $summary->wait_kind);
        $this->assertSame(
            sprintf('Waiting for child workflow %s', $childRun->workflow_type),
            $summary->wait_reason,
        );
        $this->assertNull($summary->next_task_id);
        $this->assertSame('waiting_for_child', $summary->liveness_state);
        $this->assertSame(
            sprintf('Waiting for child workflow %s.', $childRun->workflow_type),
            $summary->liveness_reason,
        );
        $this->assertSame('waiting', $childRun->status->value);

        $result = WorkflowStub::loadRun($parentRunId)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair_not_needed', $result->outcome());
    }

    public function testRepairRecreatesMissingParentResumeTaskFromChildResolutionHistory(): void
    {
        Queue::fake();
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');

        $workflow = WorkflowStub::make(TestParentChildWorkflow::class, 'parent-child-resolution-repair');
        $workflow->start('Taylor');
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->sole();

        $this->runReadyTaskForRun($link->child_workflow_run_id, TaskType::Workflow);
        $this->runReadyTaskForRun($link->child_workflow_run_id, TaskType::Activity);
        $this->runReadyTaskForRun($link->child_workflow_run_id, TaskType::Workflow);

        /** @var WorkflowHistoryEvent $childCompleted */
        $childCompleted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)
            ->sole();

        $this->assertSame($link->id, $childCompleted->payload['child_call_id'] ?? null);
        $this->assertSame($link->child_workflow_run_id, $childCompleted->payload['child_workflow_run_id'] ?? null);

        WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->delete();

        /** @var WorkflowRun $parentRun */
        $parentRun = WorkflowRun::query()->findOrFail($parentRunId);
        $summary = RunSummaryProjector::project(
            $parentRun->fresh([
                'instance',
                'tasks',
                'activityExecutions',
                'timers',
                'failures',
                'historyEvents',
                'childLinks.childRun.instance.currentRun',
                'childLinks.childRun.failures',
                'childLinks.childRun.historyEvents',
            ])
        );

        $this->assertSame('child', $summary->wait_kind);
        $this->assertSame('child:' . $link->id, $summary->open_wait_id);
        $this->assertSame('child_workflow_run', $summary->resume_source_kind);
        $this->assertSame($link->child_workflow_run_id, $summary->resume_source_id);
        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertSame(
            'Child workflow test-child-greeting-workflow is resolved without an open workflow task.',
            $summary->liveness_reason,
        );

        $detail = RunDetailView::forRun($parentRun->fresh(['summary']));
        $childWait = collect($detail['waits'])->firstWhere('kind', 'child');
        $missingTask = collect($detail['tasks'])->firstWhere('workflow_wait_kind', 'child');

        $this->assertIsArray($childWait);
        $this->assertSame('resolved', $childWait['status']);
        $this->assertFalse($childWait['task_backed']);
        $this->assertIsArray($missingTask);
        $this->assertTrue($missingTask['task_missing']);
        $this->assertSame('missing', $missingTask['transport_state']);
        $this->assertSame('child', $missingTask['workflow_wait_kind']);
        $this->assertSame('child:' . $link->id, $missingTask['workflow_open_wait_id']);
        $this->assertSame('child_workflow_run', $missingTask['workflow_resume_source_kind']);
        $this->assertSame($link->child_workflow_run_id, $missingTask['workflow_resume_source_id']);
        $this->assertSame($link->id, $missingTask['child_call_id']);
        $this->assertSame($link->child_workflow_run_id, $missingTask['child_workflow_run_id']);

        $repair = WorkflowStub::loadRun($parentRunId)->attemptRepair();

        $this->assertTrue($repair->accepted());
        $this->assertSame('repair_dispatched', $repair->outcome());

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertSame('child', $task->payload['workflow_wait_kind'] ?? null);
        $this->assertSame('child:' . $link->id, $task->payload['open_wait_id'] ?? null);
        $this->assertSame('child_workflow_run', $task->payload['resume_source_kind'] ?? null);
        $this->assertSame($link->child_workflow_run_id, $task->payload['resume_source_id'] ?? null);
        $this->assertSame($link->id, $task->payload['child_call_id'] ?? null);
        $this->assertSame($link->child_workflow_run_id, $task->payload['child_workflow_run_id'] ?? null);

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame($link->child_workflow_run_id, $workflow->output()['child']['run_id'] ?? null);
    }

    public function testTaskWatchdogRecreatesMissingParentResumeTaskFromChildResolutionHistory(): void
    {
        Queue::fake();
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');

        $workflow = WorkflowStub::make(TestParentChildWorkflow::class, 'parent-child-resolution-watchdog');
        $workflow->start('Taylor');
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->sole();

        $this->runReadyTaskForRun($link->child_workflow_run_id, TaskType::Workflow);
        $this->runReadyTaskForRun($link->child_workflow_run_id, TaskType::Activity);
        $this->runReadyTaskForRun($link->child_workflow_run_id, TaskType::Workflow);

        WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->delete();

        /** @var WorkflowRun $parentRun */
        $parentRun = WorkflowRun::query()->findOrFail($parentRunId);
        $summary = RunSummaryProjector::project(
            $parentRun->fresh([
                'instance',
                'tasks',
                'activityExecutions',
                'timers',
                'failures',
                'historyEvents',
                'childLinks.childRun.instance.currentRun',
                'childLinks.childRun.failures',
                'childLinks.childRun.historyEvents',
            ])
        );

        $this->assertSame('child', $summary->wait_kind);
        $this->assertSame('child:' . $link->id, $summary->open_wait_id);
        $this->assertSame('child_workflow_run', $summary->resume_source_kind);
        $this->assertSame($link->child_workflow_run_id, $summary->resume_source_id);
        $this->assertSame('repair_needed', $summary->liveness_state);

        $this->wakeTaskWatchdog();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertSame('child', $task->payload['workflow_wait_kind'] ?? null);
        $this->assertSame('child:' . $link->id, $task->payload['open_wait_id'] ?? null);
        $this->assertSame('child_workflow_run', $task->payload['resume_source_kind'] ?? null);
        $this->assertSame($link->child_workflow_run_id, $task->payload['resume_source_id'] ?? null);
        $this->assertSame($link->id, $task->payload['child_call_id'] ?? null);
        $this->assertSame($link->child_workflow_run_id, $task->payload['child_workflow_run_id'] ?? null);
        $this->assertSame(1, $task->repair_count);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($parentRunId);

        $this->assertSame('workflow-task', $updatedSummary->wait_kind);
        $this->assertSame('workflow_task_ready', $updatedSummary->liveness_state);
        $this->assertSame($task->id, $updatedSummary->next_task_id);

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame($link->child_workflow_run_id, $workflow->output()['child']['run_id'] ?? null);
    }

    public function testChildWorkflowFailurePropagatesToParentRun(): void
    {
        $workflow = WorkflowStub::make(TestParentFailingChildWorkflow::class, 'parent-child-failure');
        $workflow->start();
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->failed());

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->sole();

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);
        /** @var WorkflowFailure $parentFailure */
        $parentFailure = WorkflowFailure::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('propagation_kind', 'terminal')
            ->firstOrFail();

        $this->assertSame('failed', $childRun->status->value);
        $this->assertSame('failed', $workflow->status());
        $this->assertNull($workflow->output());
        $this->assertStringContainsString('boom', $parentFailure->message);
        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ChildWorkflowScheduled',
            'ChildRunStarted',
            'ChildRunFailed',
            'WorkflowFailed',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testParallelChildAllWaitsForLastSuccessfulChildBeforeResumingParent(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestParallelChildWorkflow::class, 'parallel-child-all');
        $workflow->start(0, 0);
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();

        $links = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $links);
        $this->assertSame([1, 2], $links->pluck('sequence')->all());

        /** @var WorkflowHistoryEvent $firstChildScheduled */
        $firstChildScheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ChildWorkflowScheduled')
            ->get()
            ->sole(static fn (WorkflowHistoryEvent $event): bool => ($event->payload['sequence'] ?? null) === 1);

        $this->assertSame('parallel-children:1:2', $firstChildScheduled->payload['parallel_group_id'] ?? null);
        $this->assertSame(1, $firstChildScheduled->payload['parallel_group_base_sequence'] ?? null);
        $this->assertSame(2, $firstChildScheduled->payload['parallel_group_size'] ?? null);
        $this->assertSame(0, $firstChildScheduled->payload['parallel_group_index'] ?? null);

        $firstChildRunId = $links[0]->child_workflow_run_id;
        $secondChildRunId = $links[1]->child_workflow_run_id;

        $this->assertIsString($firstChildRunId);
        $this->assertIsString($secondChildRunId);

        $this->runReadyTaskForRun($firstChildRunId, TaskType::Workflow);

        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ChildRunCompleted')
            ->count());
        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());

        $this->runReadyTaskForRun($secondChildRunId, TaskType::Workflow);

        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());
        /** @var WorkflowHistoryEvent $firstChildCompleted */
        $firstChildCompleted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ChildRunCompleted')
            ->get()
            ->sole(static fn (WorkflowHistoryEvent $event): bool => ($event->payload['sequence'] ?? null) === 1);

        $this->assertSame('parallel-children:1:2', $firstChildCompleted->payload['parallel_group_id'] ?? null);
        $this->assertSame(0, $firstChildCompleted->payload['parallel_group_index'] ?? null);
        $this->assertSame('completed', $workflow->output()['stage'] ?? null);
        $this->assertSame($firstChildRunId, $workflow->output()['children'][0]['run_id'] ?? null);
        $this->assertSame($secondChildRunId, $workflow->output()['children'][1]['run_id'] ?? null);
    }

    public function testParallelChildAllResumesParentImmediatelyOnFirstChildFailure(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestParallelChildFailureWorkflow::class, 'parallel-child-failure');
        $workflow->start(60);
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();

        $links = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $links);

        $failingChildRunId = $links[0]->child_workflow_run_id;
        $slowChildRunId = $links[1]->child_workflow_run_id;

        $this->assertIsString($failingChildRunId);
        $this->assertIsString($slowChildRunId);

        $this->runReadyTaskForRun($failingChildRunId, TaskType::Workflow);
        $this->runReadyTaskForRun($failingChildRunId, TaskType::Activity);
        $this->runReadyTaskForRun($failingChildRunId, TaskType::Workflow);

        /** @var WorkflowRun $slowChildRun */
        $slowChildRun = WorkflowRun::query()->findOrFail($slowChildRunId);

        $this->assertSame('pending', $slowChildRun->status->value);
        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'stage' => 'caught-child-failure',
            'message' => 'boom',
        ], $workflow->output());
    }

    public function testParallelChildAllResumesParentImmediatelyOnCancelledChildAndIgnoresLateSiblingClosure(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(
            TestParallelChildTerminalOutcomeWorkflow::class,
            'parallel-child-cancelled-outcome',
        );
        $workflow->start(60, 0);
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();

        $links = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $links);

        $cancelledChildRunId = $links[0]->child_workflow_run_id;
        $lateSiblingRunId = $links[1]->child_workflow_run_id;

        $this->assertIsString($cancelledChildRunId);
        $this->assertIsString($lateSiblingRunId);

        $cancelled = WorkflowStub::loadRun($cancelledChildRunId)->cancel();

        $this->assertTrue($cancelled->accepted());
        $this->assertSame('cancelled', $cancelled->outcome());
        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());

        $output = $workflow->output();

        $this->assertSame('caught-child-outcome', $output['stage'] ?? null);
        $this->assertStringContainsString('closed as cancelled', $output['message'] ?? '');
        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', HistoryEventType::WorkflowCompleted->value)
            ->count());

        $this->runReadyTaskForRun($lateSiblingRunId, TaskType::Workflow);

        $this->assertSame($output, $workflow->refresh()->output());
        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', HistoryEventType::WorkflowCompleted->value)
            ->count());
    }

    public function testParallelChildAllResumesParentImmediatelyOnTerminatedChild(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(
            TestParallelChildTerminalOutcomeWorkflow::class,
            'parallel-child-terminated-outcome',
        );
        $workflow->start(60, 0);
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();

        $links = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $links);

        $terminatedChildRunId = $links[0]->child_workflow_run_id;

        $this->assertIsString($terminatedChildRunId);

        $terminated = WorkflowStub::loadRun($terminatedChildRunId)->terminate();

        $this->assertTrue($terminated->accepted());
        $this->assertSame('terminated', $terminated->outcome());
        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());

        $output = $workflow->output();

        $this->assertSame('caught-child-outcome', $output['stage'] ?? null);
        $this->assertStringContainsString('closed as terminated', $output['message'] ?? '');
    }

    public function testParallelActivityAllWaitsForLastSuccessfulActivityBeforeResumingParent(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestParallelActivityWorkflow::class, 'parallel-activity-all');
        $workflow->start('Taylor', 'Abigail');
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();

        $activities = ActivityExecution::query()
            ->where('workflow_run_id', $parentRunId)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $activities);
        $this->assertSame([1, 2], $activities->pluck('sequence')->all());

        /** @var WorkflowHistoryEvent $firstActivityScheduled */
        $firstActivityScheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ActivityScheduled')
            ->get()
            ->sole(static fn (WorkflowHistoryEvent $event): bool => ($event->payload['sequence'] ?? null) === 1);

        $this->assertSame('parallel-activities:1:2', $firstActivityScheduled->payload['parallel_group_id'] ?? null);
        $this->assertSame('activity', $firstActivityScheduled->payload['parallel_group_kind'] ?? null);
        $this->assertSame(1, $firstActivityScheduled->payload['parallel_group_base_sequence'] ?? null);
        $this->assertSame(2, $firstActivityScheduled->payload['parallel_group_size'] ?? null);
        $this->assertSame(0, $firstActivityScheduled->payload['parallel_group_index'] ?? null);

        $this->runReadyTaskForRun($parentRunId, TaskType::Activity);

        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());

        $this->runReadyTaskForRun($parentRunId, TaskType::Activity);

        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());

        /** @var WorkflowHistoryEvent $firstActivityCompleted */
        $firstActivityCompleted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ActivityCompleted')
            ->get()
            ->sole(static fn (WorkflowHistoryEvent $event): bool => ($event->payload['sequence'] ?? null) === 1);

        $this->assertSame('parallel-activities:1:2', $firstActivityCompleted->payload['parallel_group_id'] ?? null);
        $this->assertSame('activity', $firstActivityCompleted->payload['parallel_group_kind'] ?? null);
        $this->assertSame(0, $firstActivityCompleted->payload['parallel_group_index'] ?? null);
        $this->assertSame('completed', $workflow->output()['stage'] ?? null);
        $this->assertSame(['Hello, Taylor!', 'Hello, Abigail!'], $workflow->output()['results'] ?? null);
    }

    public function testNestedParallelActivityAllWaitsForOuterActivityBeforeResumingParentAndPreservesNestedResults(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestNestedParallelActivityWorkflow::class, 'nested-parallel-activity-all');
        $workflow->start('Taylor', 'Abigail', 'Selena');
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();

        $activities = ActivityExecution::query()
            ->where('workflow_run_id', $parentRunId)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(3, $activities);
        $this->assertSame([1, 2, 3], $activities->pluck('sequence')->all());

        /** @var WorkflowHistoryEvent $firstActivityScheduled */
        $firstActivityScheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ActivityScheduled')
            ->get()
            ->sole(static fn (WorkflowHistoryEvent $event): bool => ($event->payload['sequence'] ?? null) === 1);

        /** @var WorkflowHistoryEvent $secondActivityScheduled */
        $secondActivityScheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ActivityScheduled')
            ->get()
            ->sole(static fn (WorkflowHistoryEvent $event): bool => ($event->payload['sequence'] ?? null) === 2);

        /** @var WorkflowHistoryEvent $thirdActivityScheduled */
        $thirdActivityScheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ActivityScheduled')
            ->get()
            ->sole(static fn (WorkflowHistoryEvent $event): bool => ($event->payload['sequence'] ?? null) === 3);

        $this->assertSame('parallel-activities:1:3', $firstActivityScheduled->payload['parallel_group_id'] ?? null);
        $this->assertSame([
            [
                'parallel_group_id' => 'parallel-activities:1:3',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 3,
                'parallel_group_index' => 0,
            ],
        ], $firstActivityScheduled->payload['parallel_group_path'] ?? null);

        $this->assertSame('parallel-activities:2:2', $secondActivityScheduled->payload['parallel_group_id'] ?? null);
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
        ], $secondActivityScheduled->payload['parallel_group_path'] ?? null);

        $this->assertSame('parallel-activities:2:2', $thirdActivityScheduled->payload['parallel_group_id'] ?? null);
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
        ], $thirdActivityScheduled->payload['parallel_group_path'] ?? null);

        $this->runReadyActivityTaskForSequence($parentRunId, 2);
        $this->runReadyActivityTaskForSequence($parentRunId, 3);

        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());

        $this->runReadyActivityTaskForSequence($parentRunId, 1);

        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());

        /** @var WorkflowHistoryEvent $secondActivityCompleted */
        $secondActivityCompleted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ActivityCompleted')
            ->get()
            ->sole(static fn (WorkflowHistoryEvent $event): bool => ($event->payload['sequence'] ?? null) === 2);

        $this->assertSame('parallel-activities:2:2', $secondActivityCompleted->payload['parallel_group_id'] ?? null);
        $this->assertSame(
            $secondActivityScheduled->payload['parallel_group_path'] ?? null,
            $secondActivityCompleted->payload['parallel_group_path'] ?? null
        );
        $this->assertSame('completed', $workflow->output()['stage'] ?? null);
        $this->assertSame([
            'Hello, Taylor!',
            ['Hello, Abigail!', 'Hello, Selena!'],
        ], $workflow->output()['results'] ?? null);
    }

    public function testParallelActivityAllResumesParentImmediatelyOnFirstActivityFailure(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestParallelActivityFailureWorkflow::class, 'parallel-activity-failure');
        $workflow->start('Taylor');
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();
        $this->runReadyTaskForRun($parentRunId, TaskType::Activity);

        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'stage' => 'caught-activity-failure',
            'message' => 'boom',
        ], $workflow->output());
    }

    public function testParallelActivityAllUsesEarliestRecordedFailureWhenMultipleMembersFailBeforeResume(): void
    {
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-09 12:00:00'));

        try {
            $workflow = WorkflowStub::make(
                TestParallelMultipleActivityFailureWorkflow::class,
                'parallel-multiple-activity-failure',
            );
            $workflow->start();
            $parentRunId = $workflow->runId();

            $this->assertNotNull($parentRunId);

            $this->runNextReadyTask();

            Carbon::setTestNow(Carbon::parse('2026-04-09 12:00:01'));
            $this->runReadyActivityTaskForSequence($parentRunId, 2);

            Carbon::setTestNow(Carbon::parse('2026-04-09 12:00:02'));
            $this->runReadyActivityTaskForSequence($parentRunId, 1);

            $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame([
                'stage' => 'caught-activity-failure',
                'message' => 'second failure',
            ], $workflow->output());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAsyncHelperRunsStraightLineClosureAsDurableChildWorkflow(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestAsyncWorkflow::class, 'async-child-workflow');
        $workflow->start('Taylor');

        $this->drainReadyTasks();
        $workflow->refresh();

        $this->assertTrue($workflow->completed());
        $this->assertSame([
            'workflow_id' => 'async-child-workflow',
            'run_id' => $workflow->runId(),
            'async' => [
                'greeting' => 'Hello, Taylor!',
                'in_console' => true,
            ],
        ], $workflow->output());

        /** @var WorkflowRun $parentRun */
        $parentRun = WorkflowRun::query()->findOrFail($workflow->runId());

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRun->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowRun $asyncRun */
        $asyncRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);

        $this->assertSame(AsyncWorkflow::class, $asyncRun->workflow_class);
        $this->assertSame('durable-workflow.async', $asyncRun->workflow_type);
        $this->assertSame(RunStatus::Completed, $asyncRun->status);

        /** @var WorkflowHistoryEvent $childScheduled */
        $childScheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('event_type', 'ChildWorkflowScheduled')
            ->firstOrFail();

        $this->assertSame($link->id, $childScheduled->payload['child_call_id'] ?? null);
        $this->assertSame($asyncRun->id, $childScheduled->payload['child_workflow_run_id'] ?? null);
        $this->assertSame('durable-workflow.async', $childScheduled->payload['child_workflow_type'] ?? null);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ActivityScheduled',
            'ActivityStarted',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $asyncRun->id)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testAsyncHelperRejectsGeneratorCallbacksBeforeSchedulingAsyncChildWorkflow(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestAsyncGeneratorCallbackWorkflow::class, 'async-generator-callback');
        $workflow->start('Taylor');

        $this->drainReadyTasks();
        $workflow->refresh();
        $this->assertTrue($workflow->failed());
        $this->assertNull($workflow->output());

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->latest('created_at')
            ->firstOrFail();

        $this->assertStringContainsString(
            'Workflow v2 async() callbacks must use straight-line helpers and must not yield.',
            $failure->message,
        );
        $childLinkCount = WorkflowLink::query()
            ->where('parent_workflow_run_id', $workflow->runId())
            ->count();
        $hasAsyncChildRun = WorkflowRun::query()
            ->where('workflow_type', 'durable-workflow.async')
            ->exists();
        $eventTypes = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all();

        $this->assertSame(0, $childLinkCount);
        $this->assertFalse($hasAsyncChildRun);
        $this->assertSame(['StartAccepted', 'WorkflowStarted', 'WorkflowFailed'], $eventTypes);
    }

    public function testMixedAllWaitsForChildWhenActivityCompletesFirst(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestMixedParallelWorkflow::class, 'mixed-all-activity-first');
        $workflow->start('Taylor', 0);
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();

        /** @var WorkflowHistoryEvent $activityScheduled */
        $activityScheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ActivityScheduled')
            ->firstOrFail();

        /** @var WorkflowHistoryEvent $childScheduled */
        $childScheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', 'ChildWorkflowScheduled')
            ->firstOrFail();

        $this->assertSame('parallel-calls:1:2', $activityScheduled->payload['parallel_group_id'] ?? null);
        $this->assertSame('mixed', $activityScheduled->payload['parallel_group_kind'] ?? null);
        $this->assertSame('parallel-calls:1:2', $childScheduled->payload['parallel_group_id'] ?? null);
        $this->assertSame('mixed', $childScheduled->payload['parallel_group_kind'] ?? null);

        $this->runReadyTaskForRun($parentRunId, TaskType::Activity);

        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());

        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        $this->runReadyTaskForRun($link->child_workflow_run_id, TaskType::Workflow);

        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('completed', $workflow->output()['stage'] ?? null);
        $this->assertSame('Hello, Taylor!', $workflow->output()['results'][0] ?? null);
        $this->assertSame($link->child_workflow_run_id, $workflow->output()['results'][1]['run_id'] ?? null);
    }

    public function testMixedAllWaitsForActivityWhenChildCompletesFirst(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestMixedParallelWorkflow::class, 'mixed-all-child-first');
        $workflow->start('Taylor', 0);
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();

        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        $this->runReadyTaskForRun($link->child_workflow_run_id, TaskType::Workflow);

        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());

        $this->runReadyTaskForRun($parentRunId, TaskType::Activity);

        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('completed', $workflow->output()['stage'] ?? null);
        $this->assertSame('Hello, Taylor!', $workflow->output()['results'][0] ?? null);
        $this->assertSame($link->child_workflow_run_id, $workflow->output()['results'][1]['run_id'] ?? null);
    }

    public function testMixedAllResumesParentImmediatelyOnActivityFailure(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestMixedParallelFailureWorkflow::class, 'mixed-all-failure');
        $workflow->start(60);
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();
        $this->runReadyTaskForRun($parentRunId, TaskType::Activity);

        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'stage' => 'caught-mixed-failure',
            'message' => 'boom',
        ], $workflow->output());
    }

    public function testMakeCanReuseReservedInstanceWhenDurableTypeMatchesConfiguredAlias(): void
    {
        $this->configureGreetingTypeMaps();

        WorkflowInstance::query()->create([
            'id' => '01J0000000000000000000099',
            'workflow_class' => 'Legacy\\GreetingWorkflow',
            'workflow_type' => 'config-greeting-workflow',
            'run_count' => 0,
            'reserved_at' => now()
                ->subMinute(),
        ]);

        $workflow = WorkflowStub::make(TestConfiguredGreetingWorkflow::class, '01J0000000000000000000099');

        $this->assertSame('01J0000000000000000000099', $workflow->id());
        $this->assertNull($workflow->runId());
        $this->assertDatabaseHas('workflow_instances', [
            'id' => '01J0000000000000000000099',
            'workflow_class' => TestConfiguredGreetingWorkflow::class,
            'workflow_type' => 'config-greeting-workflow',
        ]);
    }

    public function testWorkflowExecutorCanResolveConfiguredTypeWhenStoredWorkflowClassHasDrifted(): void
    {
        $this->configureGreetingTypeMaps();

        $instance = WorkflowInstance::query()->create([
            'workflow_class' => 'Legacy\\GreetingWorkflow',
            'workflow_type' => 'config-greeting-workflow',
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
            'workflow_class' => 'Legacy\\GreetingWorkflow',
            'workflow_type' => 'config-greeting-workflow',
            'status' => RunStatus::Running->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'payload' => [],
            'available_at' => now()
                ->subSeconds(5),
            'leased_at' => now()
                ->subSeconds(2),
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        $nextTask = app(\Workflow\V2\Support\WorkflowExecutor::class)->run(
            $run->fresh(['instance', 'activityExecutions', 'timers', 'failures', 'tasks']),
            $task->fresh(),
        );

        $this->assertInstanceOf(WorkflowTask::class, $nextTask);
        $this->assertSame(TaskType::Activity, $nextTask->task_type);
        $this->assertSame('waiting', $run->fresh()->status->value);
        $this->assertSame('completed', $task->fresh()->status->value);
        $this->assertDatabaseHas('activity_executions', [
            'workflow_run_id' => $run->id,
            'activity_class' => TestConfiguredGreetingActivity::class,
            'activity_type' => 'config-greeting-activity',
        ]);
    }

    public function testStartCanResolveConfiguredTypeWhenReservedInstanceClassHasDrifted(): void
    {
        $this->configureGreetingTypeMaps();

        $instance = WorkflowInstance::query()->create([
            'id' => 'configured-start-from-load',
            'workflow_class' => 'Legacy\\GreetingWorkflow',
            'workflow_type' => 'config-greeting-workflow',
            'run_count' => 0,
            'reserved_at' => now()
                ->subMinute(),
        ]);

        try {
            WorkflowStub::load($instance->id)->attemptStart('Taylor');
        } catch (ReflectionException $exception) {
            $this->fail(
                'Starting a reserved configured-type workflow should not reflect the stale class: ' . $exception->getMessage()
            );
        }

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()
            ->where('workflow_instance_id', $instance->id)
            ->firstOrFail();

        $this->assertSame(TestConfiguredGreetingWorkflow::class, $instance->fresh()->workflow_class);
        $this->assertSame(TestConfiguredGreetingWorkflow::class, $run->workflow_class);
        $this->assertSame('config-greeting-workflow', $run->workflow_type);
    }

    public function testContinueAsNewPersistsResolvedWorkflowClassAndContractsAfterConfiguredTypeFallback(): void
    {
        $this->configureContinueSignalTypeMap();

        $instance = WorkflowInstance::query()->create([
            'id' => 'configured-continue-signal',
            'workflow_class' => 'Legacy\\ContinueSignalWorkflow',
            'workflow_type' => 'config-continue-signal-workflow',
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
            'workflow_class' => 'Legacy\\ContinueSignalWorkflow',
            'workflow_type' => 'config-continue-signal-workflow',
            'status' => RunStatus::Running->value,
            'arguments' => Serializer::serialize([0]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'payload' => [],
            'available_at' => now()
                ->subSeconds(5),
            'leased_at' => now()
                ->subSeconds(2),
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        $nextTask = app(\Workflow\V2\Support\WorkflowExecutor::class)->run(
            $run->fresh(['instance', 'activityExecutions', 'timers', 'failures', 'tasks', 'commands', 'historyEvents']),
            $task->fresh(),
        );

        $this->assertInstanceOf(WorkflowTask::class, $nextTask);
        $this->assertSame(TaskType::Workflow, $nextTask->task_type);

        /** @var WorkflowRun $continuedRun */
        $continuedRun = WorkflowRun::query()
            ->where('workflow_instance_id', $instance->id)
            ->where('run_number', 2)
            ->firstOrFail();

        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $continuedRun->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();

        $this->assertSame(TestConfiguredContinueSignalWorkflow::class, $instance->fresh()->workflow_class);
        $this->assertSame(TestConfiguredContinueSignalWorkflow::class, $continuedRun->workflow_class);
        $this->assertSame(
            \Workflow\V2\Support\WorkflowDefinition::fingerprint(TestConfiguredContinueSignalWorkflow::class),
            $started->payload['workflow_definition_fingerprint'] ?? null,
        );
        $this->assertSame(['current-count'], $started->payload['declared_queries'] ?? null);
        $this->assertSame('current-count', $started->payload['declared_query_contracts'][0]['name'] ?? null);
        $this->assertSame(['name-provided'], $started->payload['declared_signals'] ?? null);
        $this->assertSame('name-provided', $started->payload['declared_signal_contracts'][0]['name'] ?? null);
        $this->assertSame(['mark-approved'], $started->payload['declared_updates'] ?? null);
        $this->assertSame('mark-approved', $started->payload['declared_update_contracts'][0]['name'] ?? null);

        $this->app->call([new RunWorkflowTask($nextTask->id), 'handle']);

        $continuedRun->refresh();

        $this->assertSame('waiting', $continuedRun->status->value);

        WorkflowRun::query()->whereKey($continuedRun->id)->update([
            'workflow_class' => 'Missing\\Workflow\\ConfiguredContinueSignalWorkflow',
        ]);

        $signal = WorkflowStub::loadRun($continuedRun->id)->attemptSignal('name-provided', 'Taylor');
        $detail = RunDetailView::forRun($continuedRun->fresh());

        $this->assertTrue($signal->accepted());
        $this->assertSame('signal_received', $signal->outcome());
        $this->assertSame('durable_history', $detail['declared_contract_source']);
        $this->assertSame(['current-count'], $detail['declared_queries']);
        $this->assertSame(['name-provided'], $detail['declared_signals']);
        $this->assertSame(['mark-approved'], $detail['declared_updates']);
    }

    public function testRunActivityTaskCanResolveConfiguredTypeWhenStoredActivityClassHasDrifted(): void
    {
        Queue::fake();
        $this->configureGreetingTypeMaps();

        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestConfiguredGreetingWorkflow::class,
            'workflow_type' => 'config-greeting-workflow',
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
            'workflow_class' => TestConfiguredGreetingWorkflow::class,
            'workflow_type' => 'config-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'Legacy\\GreetingActivity',
            'activity_type' => 'config-greeting-activity',
            'status' => ActivityStatus::Pending->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'available_at' => now(),
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        (new RunActivityTask($task->id))->handle();

        $this->assertSame('completed', $execution->fresh()->status->value);
        $this->assertSame('Hello, Taylor!', $execution->fresh()->activityResult());
        $this->assertSame('completed', $task->fresh()->status->value);
        $this->assertDatabaseHas('workflow_tasks', [
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
        ]);
    }

    public function testStartStillThrowsForDuplicateStartWhileRecordingRejectedCommand(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class);
        $workflow->start('Taylor');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf('Workflow instance [%s] has already started.', $workflow->id()));

        try {
            $workflow->start('Jordan');
        } finally {
            $this->assertSame(2, WorkflowCommand::query()->count());
            $this->assertSame(
                'rejected',
                WorkflowCommand::query()->latest('created_at')->firstOrFail()->status->value
            );
        }
    }

    public function testWorkflowFailureIsProjected(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestFailingWorkflow::class);
        $workflow->start();

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->failed());

        $failure = WorkflowFailure::query()
            ->where('source_kind', 'activity_execution')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($failure);
        $this->assertSame('boom', $failure->message);
        $this->assertFalse($failure->handled);
        $this->assertSame('failed', $workflow->status());

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ActivityScheduled',
            'ActivityStarted',
            'ActivityFailed',
            'WorkflowFailed',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testWorkflowCanHandleActivityFailureAndContinue(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestHandledFailureWorkflow::class);
        $workflow->start();

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());

        $summary = WorkflowRunSummary::query()->findOrFail($workflow->runId());

        $this->assertSame('Hello, Recovered!', $workflow->output());
        $this->assertSame('completed', $summary->status);
        $this->assertSame(1, WorkflowFailure::query()->where('handled', true)->count());

        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('source_kind', 'activity_execution')
            ->firstOrFail();

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ActivityScheduled',
            'ActivityStarted',
            'ActivityFailed',
            'FailureHandled',
            'ActivityScheduled',
            'ActivityStarted',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $handledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::FailureHandled->value)
            ->sole();

        $this->assertSame($failure->id, $handledEvent->payload['failure_id'] ?? null);
        $this->assertSame('activity_execution', $handledEvent->payload['source_kind'] ?? null);
        $this->assertSame($failure->source_id, $handledEvent->payload['source_id'] ?? null);
        $this->assertSame('activity', $handledEvent->payload['propagation_kind'] ?? null);
        $this->assertTrue($handledEvent->payload['handled'] ?? false);
    }

    public function testWorkflowCanWaitForTimerAndResume(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestTimerWorkflow::class);
        $workflow->start(3);

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        /** @var WorkflowTask $workflowTask */
        $workflowTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->sole();

        $this->app->call([new RunWorkflowTask($workflowTask->id), 'handle']);
        $workflow->refresh();

        $summary = $workflow->summary();

        $this->assertSame('waiting', $workflow->status());
        $this->assertSame('timer', $summary?->wait_kind);
        $this->assertNotNull($summary?->wait_deadline_at);
        $this->assertNotNull($summary?->next_task_id);
        $this->assertSame('timer', $summary?->next_task_type);
        $this->assertSame('ready', $summary?->next_task_status);
        $this->assertSame('timer_scheduled', $summary?->liveness_state);
        $this->assertNotNull($summary?->liveness_reason);

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $runId)
            ->where('sequence', 1)
            ->first();

        $this->assertNotNull($timer);
        $this->assertSame('pending', $timer->status->value);
        $this->assertSame(3, $timer->delay_seconds);

        sleep(4);
        $this->drainReadyTasks();
        $workflow->refresh();

        $this->assertTrue($workflow->completed());

        $this->assertSame([
            'waited' => true,
            'workflow_id' => $workflow->id(),
            'run_id' => $runId,
        ], $workflow->output());

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'TimerScheduled',
            'TimerFired',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testWorkflowCanWaitForSignalAndResumeAfterSignalCommand(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'signal-instance');
        $workflow->start();

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->drainReadyTasks();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'signal');

        $summary = $workflow->summary();

        $this->assertSame('signal', $summary?->wait_kind);
        $this->assertSame('Waiting for signal name-provided', $summary?->wait_reason);
        $this->assertNull($summary?->next_task_id);
        $this->assertSame('waiting_for_signal', $summary?->liveness_state);
        $this->assertSame('Waiting for signal name-provided.', $summary?->liveness_reason);

        $this->assertSame(['StartAccepted', 'WorkflowStarted', 'SignalWaitOpened'], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $result = $workflow->signal('name-provided', 'Taylor');

        $this->assertTrue($result->accepted());
        $this->assertSame('signal', $result->type());
        $this->assertSame('signal_received', $result->outcome());
        $this->assertSame('signal-instance', $result->instanceId());
        $this->assertSame($runId, $result->runId());
        $this->assertSame(2, $result->commandSequence());

        /** @var WorkflowSignal $signal */
        $signal = WorkflowSignal::query()
            ->where('workflow_command_id', $result->commandId())
            ->sole();
        /** @var WorkflowTask $signalTask */
        $signalTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertSame([
            'workflow_wait_kind' => 'signal',
            'open_wait_id' => 'signal-application:' . $signal->id,
            'resume_source_kind' => 'workflow_signal',
            'resume_source_id' => $signal->id,
            'workflow_signal_id' => $signal->id,
            'workflow_command_id' => $result->commandId(),
        ], $signalTask->payload);

        $this->drainReadyTasks();

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $command = WorkflowCommand::query()->findOrFail($result->commandId());
        $signal->refresh();

        $this->assertNotNull($command->applied_at);
        $this->assertSame(2, $command->command_sequence);
        $this->assertSame('name-provided', $command->targetName());
        $this->assertSame('name-provided', $signal->signal_name);
        $this->assertSame('applied', $signal->status->value);
        $this->assertSame('signal_received', $signal->outcome?->value);
        $this->assertSame(2, $signal->command_sequence);
        $this->assertSame(1, $signal->workflow_sequence);
        $this->assertNotNull($signal->signal_wait_id);
        $this->assertSame(['Taylor'], $signal->signalArguments());
        $this->assertSame([
            'name' => 'Taylor',
            'greeting' => 'Hello, Taylor!',
            'workflow_id' => 'signal-instance',
            'run_id' => $runId,
        ], $workflow->output());

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'SignalReceived',
            'MessageCursorAdvanced',
            'SignalApplied',
            'ActivityScheduled',
            'ActivityStarted',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $signalEvents = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('event_type', ['SignalReceived', 'SignalApplied'])
            ->orderBy('sequence')
            ->get();

        $this->assertSame([$signal->id, $signal->id], $signalEvents
            ->map(static fn (WorkflowHistoryEvent $event): ?string => $event->payload['signal_id'] ?? null)
            ->all());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        $detail = RunDetailView::forRun($run);

        $this->assertSame('selected_run', $detail['signals_scope']);
        $this->assertSame($signal->id, $detail['signals'][0]['id']);
        $this->assertSame('applied', $detail['signals'][0]['status']);
        $this->assertSame('name-provided', $detail['signals'][0]['name']);
        $this->assertSame($signal->id, $detail['commands'][1]['signal_id']);
        $this->assertSame('applied', $detail['commands'][1]['signal_status']);
        $this->assertNull($detail['signals'][0]['current_task_id']);
        $this->assertFalse($detail['signals'][0]['task_missing']);
        $this->assertSame([$signalEvents[1]->workflow_task_id], $detail['signals'][0]['task_ids']);
        $this->assertNull($detail['commands'][1]['current_task_id']);
        $this->assertFalse($detail['commands'][1]['task_missing']);
        $this->assertSame([$signalEvents[1]->workflow_task_id], $detail['commands'][1]['task_ids']);
    }

    public function testSignalWithStartStartsNewRunAndOrdersSignalBeforeFirstWorkflowStep(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'signal-with-start-instance');
        $result = $workflow->signalWithStart('name-provided', ['Taylor']);
        $runId = $workflow->runId();

        $this->assertTrue($result->accepted());
        $this->assertTrue($result->startedNew());
        $this->assertSame('signal_received', $result->outcome());
        $this->assertSame('signal-with-start-instance', $result->instanceId());
        $this->assertSame($runId, $result->runId());
        $this->assertSame(1, $result->startCommandSequence());
        $this->assertSame(2, $result->commandSequence());
        $this->assertIsString($result->startCommandId());
        $this->assertIsString($result->intakeGroupId());

        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->count());

        $this->assertSame(
            ['start', 'signal'],
            WorkflowCommand::query()
                ->where('workflow_run_id', $runId)
                ->orderBy('command_sequence')
                ->pluck('command_type')
                ->map(static fn ($commandType) => $commandType->value)
                ->all(),
        );

        $this->drainReadyTasks();

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->get();

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalReceived',
            'SignalWaitOpened',
            'SignalApplied',
            'ActivityScheduled',
            'ActivityStarted',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], $events
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $signalReceived = $events->firstWhere('event_type', HistoryEventType::SignalReceived);
        $signalWaitOpened = $events->firstWhere('event_type', HistoryEventType::SignalWaitOpened);

        $this->assertInstanceOf(WorkflowHistoryEvent::class, $signalReceived);
        $this->assertInstanceOf(WorkflowHistoryEvent::class, $signalWaitOpened);
        $this->assertSame(
            $signalReceived->payload['signal_wait_id'] ?? null,
            $signalWaitOpened->payload['signal_wait_id'] ?? null,
        );

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertSame('signal_with_start', $detail['commands'][0]['context']['intake']['mode']);
        $this->assertSame($result->intakeGroupId(), $detail['commands'][0]['context']['intake']['group_id']);
        $this->assertSame($result->intakeGroupId(), $detail['commands'][1]['context']['intake']['group_id']);
        $this->assertSame('started_new', $detail['commands'][0]['outcome']);
        $this->assertSame('signal_received', $detail['commands'][1]['outcome']);
        $this->assertSame([
            'name' => 'Taylor',
            'greeting' => 'Hello, Taylor!',
            'workflow_id' => 'signal-with-start-instance',
            'run_id' => $runId,
        ], $workflow->output());
    }

    public function testAttemptSignalWithStartRejectsUnknownSignalWithoutStartingNewRun(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'signal-with-start-unknown');
        $result = $workflow->attemptSignalWithStart('missing-signal', ['Taylor']);

        $this->assertTrue($result->rejected());
        $this->assertSame('rejected_unknown_signal', $result->outcome());
        $this->assertSame('unknown_signal', $result->rejectionReason());
        $this->assertNull($result->startCommandId());
        $this->assertNull($workflow->runId());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'signal-with-start-unknown',
            'workflow_run_id' => null,
            'command_type' => 'signal',
            'status' => 'rejected',
            'outcome' => 'rejected_unknown_signal',
            'rejection_reason' => 'unknown_signal',
        ]);

        $this->assertDatabaseMissing('workflow_commands', [
            'workflow_instance_id' => 'signal-with-start-unknown',
            'command_type' => 'start',
        ]);
        $this->assertSame(0, WorkflowRun::query()->count());
    }

    public function testSignalCommandCanAcceptSingleAssociativePayloadViaArraySafeHelper(): void
    {
        $workflow = WorkflowStub::make(TestSignalPayloadWorkflow::class, 'signal-payload-instance');
        $workflow->start();

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'signal');

        $result = $workflow->attemptSignalWithArguments('payload-provided', [
            'approved' => true,
            'source' => 'waterline',
        ]);

        $this->assertTrue($result->accepted());
        $this->assertSame('signal_received', $result->outcome());

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::query()->findOrFail($result->commandId());

        $this->assertSame([
            [
                'approved' => true,
                'source' => 'waterline',
            ],
        ], $command->payloadArguments());
        $this->assertSame([
            'payload' => [
                'approved' => true,
                'source' => 'waterline',
            ],
            'workflow_id' => 'signal-payload-instance',
            'run_id' => $runId,
        ], $workflow->output());
    }

    public function testSignalCommandCanAcceptNamedArgumentsViaDeclaredSignalContract(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'signal-contract-instance');
        $workflow->start();

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'signal');

        $result = $workflow->attemptSignalWithArguments('name-provided', [
            'name' => 'Taylor',
        ]);

        $this->assertTrue($result->accepted());
        $this->assertSame('signal_received', $result->outcome());

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::query()->findOrFail($result->commandId());

        $this->assertSame(['Taylor'], $command->payloadArguments());
        $this->assertSame([
            'approved' => false,
            'events' => ['started', 'signal:Taylor'],
            'workflow_id' => 'signal-contract-instance',
            'run_id' => $runId,
        ], $workflow->output());
    }

    public function testSignalCommandRejectsInvalidNamedArgumentsAgainstDeclaredContract(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'signal-contract-invalid');
        $workflow->start();

        $this->drainReadyTasks();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'signal');

        $result = $workflow->attemptSignalWithArguments('name-provided', [
            'nickname' => 'Taylor',
        ]);

        $this->assertTrue($result->rejected());
        $this->assertTrue($result->rejectedInvalidArguments());
        $this->assertSame('rejected_invalid_arguments', $result->outcome());
        $this->assertSame('invalid_signal_arguments', $result->rejectionReason());
        $this->assertSame([
            'name' => ['The name argument is required.'],
            'nickname' => ['Unknown argument [nickname].'],
        ], $result->validationErrors());
        $this->assertSame('waiting', $workflow->refresh()->status());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'signal-contract-invalid',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'signal',
            'status' => 'rejected',
            'outcome' => 'rejected_invalid_arguments',
            'rejection_reason' => 'invalid_signal_arguments',
        ]);

        /** @var WorkflowSignal $signal */
        $signal = WorkflowSignal::query()
            ->where('workflow_command_id', $result->commandId())
            ->sole();

        $this->assertSame('name-provided', $signal->signal_name);
        $this->assertSame('rejected', $signal->status->value);
        $this->assertSame('rejected_invalid_arguments', $signal->outcome?->value);
        $this->assertSame('invalid_signal_arguments', $signal->rejection_reason);
        $this->assertSame([
            'name' => ['The name argument is required.'],
            'nickname' => ['Unknown argument [nickname].'],
        ], $signal->normalizedValidationErrors());
    }

    public function testSignalCommandRejectsNamedArgumentsWhenLegacyContractNeedsBackfillAndDefinitionIsUnavailable(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'signal-contract-unavailable');
        $workflow->start();

        $this->drainReadyTasks();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'signal');

        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->sole();

        $started->forceFill([
            'payload' => [
                'workflow_class' => TestUpdateWorkflow::class,
                'workflow_type' => 'test-update-workflow',
                'workflow_instance_id' => $workflow->id(),
                'workflow_run_id' => $workflow->runId(),
                'declared_signals' => ['name-provided'],
                'declared_updates' => ['approve', 'explode'],
            ],
        ])->save();

        WorkflowRun::query()->whereKey($workflow->runId())->update([
            'workflow_class' => 'Missing\\Workflow\\TestUpdateWorkflow',
            'workflow_type' => 'missing-update-workflow',
        ]);

        $result = $workflow->attemptSignalWithArguments('name-provided', [
            'name' => 'Taylor',
        ]);

        $this->assertTrue($result->rejected());
        $this->assertTrue($result->rejectedInvalidArguments());
        $this->assertSame('rejected_invalid_arguments', $result->outcome());
        $this->assertSame('invalid_signal_arguments', $result->rejectionReason());
        $this->assertSame([
            'arguments' => ['Named arguments require a durable or loadable workflow signal contract.'],
        ], $result->validationErrors());

        /** @var WorkflowSignal $signal */
        $signal = WorkflowSignal::query()
            ->where('workflow_command_id', $result->commandId())
            ->sole();

        $this->assertSame('rejected_invalid_arguments', $signal->outcome?->value);
        $this->assertSame('invalid_signal_arguments', $signal->rejection_reason);
        $this->assertSame([
            'arguments' => ['Named arguments require a durable or loadable workflow signal contract.'],
        ], $signal->normalizedValidationErrors());
    }

    public function testSignalCommandStillAcceptsPositionalArgumentsWhenLegacyContractNeedsBackfillAndDefinitionIsUnavailable(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'signal-contract-unavailable-positional');
        $workflow->start();

        $this->drainReadyTasks();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'signal');

        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->sole();

        $started->forceFill([
            'payload' => [
                'workflow_class' => TestUpdateWorkflow::class,
                'workflow_type' => 'test-update-workflow',
                'workflow_instance_id' => $workflow->id(),
                'workflow_run_id' => $workflow->runId(),
                'declared_signals' => ['name-provided'],
                'declared_updates' => ['approve', 'explode'],
            ],
        ])->save();

        WorkflowRun::query()->whereKey($workflow->runId())->update([
            'workflow_class' => 'Missing\\Workflow\\TestUpdateWorkflow',
            'workflow_type' => 'missing-update-workflow',
        ]);

        $result = $workflow->attemptSignal('name-provided', 'Taylor');

        $this->assertTrue($result->accepted());
        $this->assertSame('signal_received', $result->outcome());
    }

    public function testSignalCommandRejectsTypeMismatchedArgumentsAgainstDeclaredContract(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'signal-contract-type-mismatch');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'signal');

        $result = $workflow->attemptSignalWithArguments('name-provided', [
            'name' => 123,
        ]);

        $this->assertTrue($result->rejected());
        $this->assertTrue($result->rejectedInvalidArguments());
        $this->assertSame('rejected_invalid_arguments', $result->outcome());
        $this->assertSame('invalid_signal_arguments', $result->rejectionReason());
        $this->assertSame([
            'name' => ['The name argument must be of type string.'],
        ], $result->validationErrors());
        $this->assertSame('waiting', $workflow->refresh()->status());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'signal-contract-type-mismatch',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'signal',
            'status' => 'rejected',
            'outcome' => 'rejected_invalid_arguments',
            'rejection_reason' => 'invalid_signal_arguments',
        ]);
    }

    public function testSignalCommandsUseDurableCommandSequenceOrder(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalOrderingWorkflow::class, 'signal-order-instance');
        $started = $workflow->start();
        $runId = $started->runId();

        $this->assertNotNull($runId);
        $this->assertSame(1, $started->commandSequence());

        $this->drainReadyTasks();
        $workflow->refresh();

        $this->assertSame('waiting', $workflow->status());

        $first = $workflow->signal('message', 'first');
        $second = $workflow->signal('message', 'second');

        $this->assertSame(2, $first->commandSequence());
        $this->assertSame(3, $second->commandSequence());

        $this->drainReadyTasks();
        $workflow->refresh();

        $this->assertTrue($workflow->completed());
        $this->assertSame([
            'messages' => ['first', 'second'],
            'workflow_id' => 'signal-order-instance',
            'run_id' => $runId,
        ], $workflow->output());

        $this->assertSame([1, 2, 3], WorkflowCommand::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('command_sequence')
            ->pluck('command_sequence')
            ->all());

        $this->assertSame([$first->commandId(), $second->commandId()], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'SignalApplied')
            ->orderBy('sequence')
            ->pluck('workflow_command_id')
            ->all());
    }

    public function testBufferedSameNamedSignalsKeepDurableWaitIdsBeforeLaterWaitsOpen(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalOrderingWorkflow::class, 'signal-buffered-wait-ids');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->drainReadyTasks();
        $workflow->refresh();

        $first = $workflow->signal('message', 'first');
        $second = $workflow->signal('message', 'second');

        $receivedWaitIds = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'SignalReceived')
            ->orderBy('sequence')
            ->get()
            ->mapWithKeys(static fn (WorkflowHistoryEvent $event): array => [
                $event->workflow_command_id => $event->payload['signal_wait_id'] ?? null,
            ]);

        $this->assertSame([$first->commandId(), $second->commandId()], $receivedWaitIds->keys()->all());
        $this->assertCount(
            2,
            array_filter($receivedWaitIds->all(), static fn (?string $waitId): bool => is_string($waitId))
        );
        $this->assertNotSame($receivedWaitIds[$first->commandId()], $receivedWaitIds[$second->commandId()]);

        $this->drainReadyTasks();
        $workflow->refresh();

        $this->assertTrue($workflow->completed());

        $openedWaitIds = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'SignalWaitOpened')
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): ?string => $event->payload['signal_wait_id'] ?? null)
            ->all();

        $appliedWaitIds = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'SignalApplied')
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): ?string => $event->payload['signal_wait_id'] ?? null)
            ->all();

        $this->assertSame(array_values($receivedWaitIds->all()), $openedWaitIds);
        $this->assertSame($openedWaitIds, $appliedWaitIds);
        $this->assertSame([
            'messages' => ['first', 'second'],
            'workflow_id' => 'signal-buffered-wait-ids',
            'run_id' => $runId,
        ], $workflow->output());
    }

    public function testRepeatedSameNamedSignalWaitsUseDurableWaitIds(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalOrderingWorkflow::class, 'signal-wait-ids');
        $started = $workflow->start();
        $runId = $started->runId();

        $this->assertNotNull($runId);

        $this->drainReadyTasks();
        $workflow->refresh();

        $openedWaits = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'SignalWaitOpened')
            ->orderBy('sequence')
            ->get();

        $this->assertCount(1, $openedWaits);

        $firstWaitId = $openedWaits[0]->payload['signal_wait_id'] ?? null;

        $this->assertIsString($firstWaitId);

        $first = $workflow->signal('message', 'first');

        $firstReceived = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'SignalReceived')
            ->where('workflow_command_id', $first->commandId())
            ->sole();

        $this->assertSame($firstWaitId, $firstReceived->payload['signal_wait_id'] ?? null);

        $this->drainReadyTasks();
        $workflow->refresh();

        $this->assertSame('waiting', $workflow->status());

        $openedWaits = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'SignalWaitOpened')
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $openedWaits);

        $secondWaitId = $openedWaits[1]->payload['signal_wait_id'] ?? null;

        $this->assertIsString($secondWaitId);
        $this->assertNotSame($firstWaitId, $secondWaitId);

        $second = $workflow->signal('message', 'second');

        $secondReceived = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'SignalReceived')
            ->where('workflow_command_id', $second->commandId())
            ->sole();

        $this->assertSame($secondWaitId, $secondReceived->payload['signal_wait_id'] ?? null);

        $this->drainReadyTasks();
        $workflow->refresh();

        $this->assertTrue($workflow->completed());

        $this->assertSame([$firstWaitId, $secondWaitId], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'SignalApplied')
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): ?string => $event->payload['signal_wait_id'] ?? null)
            ->all());

        $this->assertSame([
            'messages' => ['first', 'second'],
            'workflow_id' => 'signal-wait-ids',
            'run_id' => $runId,
        ], $workflow->output());
    }

    public function testRunSummaryProjectsLeasedWorkflowTaskLiveness(): void
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 0,
            'reserved_at' => now()
                ->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Running->value,
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(10),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
            'run_count' => 1,
            'started_at' => $run->started_at,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'payload' => [],
            'available_at' => now()
                ->subSeconds(20),
            'leased_at' => now()
                ->subSeconds(10),
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures'])
        );

        $this->assertSame('workflow-task', $summary->wait_kind);
        $this->assertSame('Workflow task leased to worker', $summary->wait_reason);
        $this->assertSame($task->id, $summary->next_task_id);
        $this->assertSame('workflow', $summary->next_task_type);
        $this->assertSame('leased', $summary->next_task_status);
        $this->assertSame('workflow_task_leased', $summary->liveness_state);
        $this->assertSame($task->lease_expires_at?->toJSON(), $summary->next_task_lease_expires_at?->toJSON());
    }

    public function testRunSummaryFlagsRepairNeededWhenResumeSourceIsMissing(): void
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 0,
            'reserved_at' => now()
                ->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(15),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
            'run_count' => 1,
            'started_at' => $run->started_at,
        ])->save();

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures'])
        );

        $this->assertNull($summary->wait_kind);
        $this->assertNull($summary->next_task_id);
        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertSame('Run is non-terminal but has no durable next-resume source.', $summary->liveness_reason);
    }

    public function testRepairRecreatesMissingWorkflowTaskForRepairNeededRun(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-workflow-instance',
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

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('repair_needed', $summary->liveness_state);

        $result = WorkflowStub::loadRun($run->id)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair', $result->type());
        $this->assertSame('repair_dispatched', $result->outcome());
        $this->assertSame($instance->id, $result->instanceId());
        $this->assertSame($run->id, $result->runId());

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->sole();

        $this->assertSame(TaskType::Workflow, $task->task_type);
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame([], $task->payload);
        $this->assertSame(1, $task->repair_count);
        $this->assertSame('redis', $task->connection);
        $this->assertSame('default', $task->queue);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('workflow-task', $updatedSummary->wait_kind);
        $this->assertSame('workflow_task_ready', $updatedSummary->liveness_state);
        $this->assertSame($task->id, $updatedSummary->next_task_id);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_type' => 'repair',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'repair_dispatched',
        ]);
    }

    public function testRunSummaryFlagsRepairNeededWhenWorkflowTaskLeaseExpires(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-expired-wf-inst',
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

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSeconds(20),
            'leased_at' => now()
                ->subSeconds(20),
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()
                ->subSecond(),
            'last_dispatched_at' => now()
                ->subSeconds(20),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('workflow-task', $summary->wait_kind);
        $this->assertSame($task->id, $summary->next_task_id);
        $this->assertSame('leased', $summary->next_task_status);
        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertStringContainsString('lease expired', $summary->liveness_reason);
    }

    public function testRepairReusesExpiredWorkflowTaskInsteadOfCreatingDuplicate(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-existing-wf-task',
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

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSeconds(25),
            'leased_at' => now()
                ->subSeconds(25),
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()
                ->subSecond(),
            'last_dispatched_at' => now()
                ->subSeconds(25),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('repair_needed', $summary->liveness_state);

        $result = WorkflowStub::loadRun($run->id)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair_dispatched', $result->outcome());
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertNull($task->leased_at);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
        $this->assertSame(1, $task->repair_count);
        $this->assertNotNull($task->last_dispatched_at);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('workflow-task', $updatedSummary->wait_kind);
        $this->assertSame('workflow_task_ready', $updatedSummary->liveness_state);
        $this->assertSame($task->id, $updatedSummary->next_task_id);
    }

    public function testRepairDoesNotCreateTaskForRowOnlyPendingTimerFallback(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-timer-instance',
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
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
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([30]),
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

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'status' => 'pending',
            'delay_seconds' => 30,
            'fire_at' => now()
                ->addSeconds(25),
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertNull($summary->wait_kind);
        $this->assertSame('workflow_replay_blocked', $summary->liveness_state);
        $this->assertSame(
            sprintf(
                'Timer %s is visible only from an older mutable row without typed timer history. This row is diagnostic-only and does not satisfy the durable resume-path invariant.',
                $timer->id,
            ),
            $summary->liveness_reason,
        );

        $result = WorkflowStub::loadRun($run->id)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair_not_needed', $result->outcome());
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());

        Queue::assertNothingPushed();

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertNull($updatedSummary->wait_kind);
        $this->assertSame('workflow_replay_blocked', $updatedSummary->liveness_state);
        $this->assertNull($updatedSummary->next_task_id);
    }

    public function testRepairReturnsAcceptedNoOpForHealthySignalWait(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'repair-signal');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        $runId = $workflow->runId();

        $this->assertNotNull($runId);
        $this->assertSame('waiting_for_signal', $workflow->summary()?->liveness_state);

        Queue::fake();

        $existingTaskIds = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->pluck('id')
            ->all();

        $result = WorkflowStub::loadRun($runId)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair', $result->type());
        $this->assertSame('repair_not_needed', $result->outcome());
        $this->assertNull($result->rejectionReason());

        $this->assertSame(
            $existingTaskIds,
            WorkflowTask::query()->where('workflow_run_id', $runId)->pluck('id')->all(),
        );

        Queue::assertNothingPushed();

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $workflow->id(),
            'workflow_run_id' => $runId,
            'command_type' => 'repair',
            'status' => 'accepted',
            'outcome' => 'repair_not_needed',
        ]);
    }

    public function testRepairRecreatesMissingWorkflowTaskAfterSignalIsReceived(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'repair-signal-received');
        $workflow->start();

        $this->drainReadyTasks();

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $signal = $workflow->signal('name-provided', 'Taylor');

        $this->assertTrue($signal->accepted());
        $this->assertSame('signal_received', $signal->outcome());

        /** @var WorkflowSignal $signalRecord */
        $signalRecord = WorkflowSignal::query()
            ->where('workflow_command_id', $signal->commandId())
            ->sole();

        WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->delete();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('signal', $summary->wait_kind);
        $this->assertSame('Waiting to apply signal name-provided', $summary->wait_reason);
        $this->assertSame('signal-application:' . $signalRecord->id, $summary->open_wait_id);
        $this->assertSame('workflow_signal', $summary->resume_source_kind);
        $this->assertSame($signalRecord->id, $summary->resume_source_id);
        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertSame(
            'Accepted signal name-provided is received without an open workflow task.',
            $summary->liveness_reason
        );

        $result = WorkflowStub::loadRun($runId)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair_dispatched', $result->outcome());

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertSame([
            'workflow_wait_kind' => 'signal',
            'open_wait_id' => 'signal-application:' . $signalRecord->id,
            'resume_source_kind' => 'workflow_signal',
            'resume_source_id' => $signalRecord->id,
            'workflow_signal_id' => $signalRecord->id,
            'workflow_command_id' => $signalRecord->workflow_command_id,
        ], $task->payload);
        $this->assertSame(1, $task->repair_count);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($runId);

        $this->assertSame('workflow-task', $updatedSummary->wait_kind);
        $this->assertSame('workflow_task_ready', $updatedSummary->liveness_state);
        $this->assertSame($task->id, $updatedSummary->next_task_id);
        $this->assertSame('workflow', $updatedSummary->next_task_type);

        $detail = RunDetailView::forRun(WorkflowRun::query()->findOrFail($runId));
        $taskDetail = collect($detail['tasks'])->firstWhere('id', $task->id);

        $this->assertIsArray($taskDetail);
        $this->assertSame('Workflow task ready to apply accepted signal.', $taskDetail['summary']);
        $this->assertSame('signal', $taskDetail['workflow_wait_kind']);
        $this->assertSame($signalRecord->id, $taskDetail['workflow_signal_id']);
    }

    public function testTaskWatchdogRecreatesMissingWorkflowTaskAfterSignalIsReceived(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');

        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'repair-watchdog-signal-received');
        $workflow->start();

        $this->drainReadyTasks();

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $signal = $workflow->signal('name-provided', 'Taylor');

        $this->assertTrue($signal->accepted());
        $this->assertSame('signal_received', $signal->outcome());

        /** @var WorkflowSignal $signalRecord */
        $signalRecord = WorkflowSignal::query()
            ->where('workflow_command_id', $signal->commandId())
            ->sole();

        WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->delete();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('signal', $summary->wait_kind);
        $this->assertSame('Waiting to apply signal name-provided', $summary->wait_reason);
        $this->assertSame('signal-application:' . $signalRecord->id, $summary->open_wait_id);
        $this->assertSame('workflow_signal', $summary->resume_source_kind);
        $this->assertSame($signalRecord->id, $summary->resume_source_id);
        $this->assertSame('repair_needed', $summary->liveness_state);

        $this->wakeTaskWatchdog();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertSame([
            'workflow_wait_kind' => 'signal',
            'open_wait_id' => 'signal-application:' . $signalRecord->id,
            'resume_source_kind' => 'workflow_signal',
            'resume_source_id' => $signalRecord->id,
            'workflow_signal_id' => $signalRecord->id,
            'workflow_command_id' => $signalRecord->workflow_command_id,
        ], $task->payload);
        $this->assertSame(1, $task->repair_count);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($runId);

        $this->assertSame('workflow-task', $updatedSummary->wait_kind);
        $this->assertSame('workflow_task_ready', $updatedSummary->liveness_state);
        $this->assertSame($task->id, $updatedSummary->next_task_id);
        $this->assertSame('workflow', $updatedSummary->next_task_type);

        $detail = RunDetailView::forRun(WorkflowRun::query()->findOrFail($runId));
        $taskDetail = collect($detail['tasks'])->firstWhere('id', $task->id);

        $this->assertIsArray($taskDetail);
        $this->assertSame('Workflow task ready to apply accepted signal.', $taskDetail['summary']);
        $this->assertSame('signal', $taskDetail['workflow_wait_kind']);
        $this->assertSame($signalRecord->id, $taskDetail['workflow_signal_id']);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'name' => 'Taylor',
            'greeting' => 'Hello, Taylor!',
            'workflow_id' => 'repair-watchdog-signal-received',
            'run_id' => $runId,
        ], $workflow->output());
    }

    public function testTaskWatchdogRedispatchesReadyWorkflowTaskWhenDispatchIsOverdue(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-overdue-wf-task',
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

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSeconds(20),
            'last_dispatched_at' => now()
                ->subSeconds(20),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->wakeTaskWatchdog();

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(1, $task->repair_count);
        $this->assertNotNull($task->last_dispatched_at);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('workflow-task', $summary->wait_kind);
        $this->assertSame('workflow_task_ready', $summary->liveness_state);
    }

    public function testTaskWatchdogRedispatchesReadyWorkflowTaskAfterDispatchFailureBeforeAgeCutoff(): void
    {
        Queue::fake();

        config()
            ->set('workflows.v2.task_repair.redispatch_after_seconds', 60);

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-dispatch-failed-wf-task',
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

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'last_dispatch_attempt_at' => now(),
            'last_dispatch_error' => 'Queue transport unavailable.',
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('repair_needed', WorkflowRunSummary::query()->findOrFail($run->id)->liveness_state);

        $this->wakeTaskWatchdog();

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(1, $task->repair_count);
        $this->assertNull($task->last_dispatch_error);
        $this->assertNotNull($task->last_dispatched_at);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('workflow-task', $summary->wait_kind);
        $this->assertSame('workflow_task_ready', $summary->liveness_state);
    }

    public function testTaskWatchdogRecreatesMissingWorkflowTaskForRepairNeededRun(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-watchdog-missing-wf',
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

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertNull($summary->next_task_id);

        $this->wakeTaskWatchdog();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->sole();

        $this->assertSame(TaskType::Workflow, $task->task_type);
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame([], $task->payload);
        $this->assertSame(1, $task->repair_count);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('workflow-task', $updatedSummary->wait_kind);
        $this->assertSame('workflow_task_ready', $updatedSummary->liveness_state);
        $this->assertSame($task->id, $updatedSummary->next_task_id);
        $this->assertSame('workflow', $updatedSummary->next_task_type);
    }

    public function testTaskWatchdogRecreatesMissingTimerTaskForRepairNeededRun(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-watchdog-missing-tm',
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
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
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([30]),
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

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'status' => TimerStatus::Pending->value,
            'delay_seconds' => 30,
            'fire_at' => now()
                ->addSeconds(25),
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertSame('timer', $summary->wait_kind);
        $this->assertNull($summary->next_task_id);

        $this->wakeTaskWatchdog();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->sole();

        $this->assertSame(TaskType::Timer, $task->task_type);
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame([
            'timer_id' => $timer->id,
        ], $task->payload);
        $this->assertSame(1, $task->repair_count);
        $this->assertSame($timer->fire_at?->toJSON(), $task->available_at?->toJSON());

        Queue::assertPushed(
            RunTimerTask::class,
            static fn (RunTimerTask $job): bool => $job->taskId === $task->id
        );

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('timer', $updatedSummary->wait_kind);
        $this->assertSame('timer_scheduled', $updatedSummary->liveness_state);
        $this->assertSame($task->id, $updatedSummary->next_task_id);
        $this->assertSame('timer', $updatedSummary->next_task_type);
    }

    public function testTaskWatchdogRecreatesMissingTimerTaskFromTypedHistoryWhenTimerRowDrifts(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-09 18:00:00'));

        try {
            $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'repair-watchdog-timer-history');
            $workflow->start(30);

            $runId = $workflow->runId();

            $this->assertNotNull($runId);

            $this->runReadyTaskForRun($runId, TaskType::Workflow);

            /** @var WorkflowTimer $timer */
            $timer = WorkflowTimer::query()
                ->where('workflow_run_id', $runId)
                ->firstOrFail();

            $timerId = $timer->id;
            $fireAt = $timer->fire_at?->toJSON();

            WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Timer->value)
                ->delete();

            $timer->delete();

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()->findOrFail($runId);
            $summary = RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            );

            $this->assertSame('repair_needed', $summary->liveness_state);
            $this->assertSame('timer', $summary->wait_kind);
            $this->assertSame($timerId, $summary->resume_source_id);
            $this->assertNull($summary->next_task_id);

            $this->wakeTaskWatchdog();

            /** @var WorkflowTimer $restoredTimer */
            $restoredTimer = WorkflowTimer::query()->findOrFail($timerId);
            /** @var WorkflowTask $task */
            $task = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Timer->value)
                ->where('status', TaskStatus::Ready->value)
                ->sole();

            $this->assertSame(TimerStatus::Pending, $restoredTimer->status);
            $this->assertSame($fireAt, $restoredTimer->fire_at?->toJSON());
            $this->assertSame([
                'timer_id' => $timerId,
            ], $task->payload);
            $this->assertSame($fireAt, $task->available_at?->toJSON());
            $this->assertSame(1, $task->repair_count);

            Queue::assertPushed(
                RunTimerTask::class,
                static fn (RunTimerTask $job): bool => $job->taskId === $task->id
            );

            $updatedSummary = WorkflowRunSummary::query()->findOrFail($runId);

            $this->assertSame('timer', $updatedSummary->wait_kind);
            $this->assertSame('timer_scheduled', $updatedSummary->liveness_state);
            $this->assertSame($task->id, $updatedSummary->next_task_id);
            $this->assertSame('timer', $updatedSummary->next_task_type);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testTaskWatchdogLeavesRowOnlyRunningActivityFallbackDiagnosticOnly(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-watchdog-run-act',
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

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => TestGreetingActivity::class,
            'status' => ActivityStatus::Running->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'activities',
            'started_at' => now()
                ->subSeconds(20),
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertNull($summary->wait_kind);
        $this->assertSame('workflow_replay_blocked', $summary->liveness_state);
        $this->assertSame(
            sprintf(
                'Activity %s is visible only from an older mutable row without typed activity history. This row is diagnostic-only and does not satisfy the durable resume-path invariant.',
                TestGreetingActivity::class,
            ),
            $summary->liveness_reason,
        );

        $this->wakeTaskWatchdog();

        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());
        Queue::assertNothingPushed();

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertNull($updatedSummary->wait_kind);
        $this->assertSame('workflow_replay_blocked', $updatedSummary->liveness_state);
        $this->assertNull($updatedSummary->next_task_id);
    }

    public function testTaskWatchdogReclaimsExpiredActivityTaskLease(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-expired-act-task',
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

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => TestGreetingActivity::class,
            'status' => ActivityStatus::Running->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'activities',
            'started_at' => now()
                ->subSeconds(25),
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSeconds(25),
            'leased_at' => now()
                ->subSeconds(25),
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()
                ->subSecond(),
            'last_dispatched_at' => now()
                ->subSeconds(25),
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'connection' => 'redis',
            'queue' => 'activities',
        ]);

        $this->wakeTaskWatchdog();

        $task->refresh();
        $execution->refresh();

        /** @var ActivityAttempt $attempt */
        $attempt = ActivityAttempt::query()
            ->where('activity_execution_id', $execution->id)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertNull($task->leased_at);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
        $this->assertSame(1, $task->attempt_count);
        $this->assertSame(1, $task->repair_count);
        $this->assertNotNull($execution->current_attempt_id);
        $this->assertSame(1, $execution->attempt_count);
        $this->assertSame($execution->current_attempt_id, $attempt->id);
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertSame('expired', $attempt->status->value);
        $this->assertSame($task->id, $attempt->workflow_task_id);
        $this->assertSame('worker-1', $attempt->lease_owner);
        $this->assertNotNull($attempt->closed_at);

        Queue::assertPushed(
            RunActivityTask::class,
            static fn (RunActivityTask $job): bool => $job->taskId === $task->id
        );

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('activity', $summary->wait_kind);
        $this->assertSame('activity_task_ready', $summary->liveness_state);
        $this->assertSame($task->id, $summary->next_task_id);
    }

    public function testLateActivityOutcomeIsIgnoredAfterNewerAttemptTakesOwnership(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'stale-activity-attempt',
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

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => TestReclaimDuringExecutionActivity::class,
            'activity_type' => TestReclaimDuringExecutionActivity::class,
            'status' => ActivityStatus::Pending->value,
            'attempt_count' => 0,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'activities',
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'connection' => 'redis',
            'queue' => 'activities',
        ]);

        TestReclaimDuringExecutionActivity::intercept(static function (string $executionId) use ($task): void {
            WorkflowTask::query()
                ->whereKey($task->id)
                ->update([
                    'status' => TaskStatus::Leased->value,
                    'attempt_count' => 2,
                    'leased_at' => now()
                        ->subSecond(),
                    'lease_owner' => 'worker-newer',
                    'lease_expires_at' => now()
                        ->addMinutes(5),
                ]);

            ActivityExecution::query()
                ->whereKey($executionId)
                ->update([
                    'status' => ActivityStatus::Running->value,
                    'attempt_count' => 2,
                    'current_attempt_id' => '01JTESTATTEMPT000000000002',
                    'started_at' => now()
                        ->subSecond(),
                ]);
        });

        (new RunActivityTask($task->id))->handle();

        $task->refresh();
        $execution->refresh();
        /** @var ActivityAttempt $attempt */
        $attempt = ActivityAttempt::query()
            ->where('activity_execution_id', $execution->id)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Leased, $task->status);
        $this->assertSame(2, $task->attempt_count);
        $this->assertSame(ActivityStatus::Running, $execution->status);
        $this->assertSame(2, $execution->attempt_count);
        $this->assertSame('01JTESTATTEMPT000000000002', $execution->current_attempt_id);
        $this->assertSame('expired', $attempt->status->value);
        $this->assertNotNull($attempt->closed_at);
        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', 'ActivityStarted')
            ->count());
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', 'ActivityCompleted')
            ->count());
        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->count());
    }

    public function testTaskWatchdogReclaimsExpiredTimerTaskLease(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-expired-timer-task',
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
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
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([30]),
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

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'status' => TimerStatus::Pending->value,
            'delay_seconds' => 30,
            'fire_at' => now()
                ->addSeconds(20),
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Timer->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSeconds(10),
            'leased_at' => now()
                ->subSeconds(10),
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()
                ->subSecond(),
            'last_dispatched_at' => now()
                ->subSeconds(10),
            'payload' => [
                'timer_id' => $timer->id,
            ],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->wakeTaskWatchdog();

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertNull($task->leased_at);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
        $this->assertSame(1, $task->repair_count);

        Queue::assertPushed(RunTimerTask::class, static fn (RunTimerTask $job): bool => $job->taskId === $task->id);

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('timer', $summary->wait_kind);
        $this->assertSame('timer_scheduled', $summary->liveness_state);
        $this->assertSame($task->id, $summary->next_task_id);
    }

    public function testWorkflowCanCompleteImmediateTimerWithoutSchedulingATimerTask(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class);
        $workflow->start(0);

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $this->assertSame([
            'waited' => true,
            'workflow_id' => $workflow->id(),
            'run_id' => $runId,
        ], $workflow->output());

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $runId)
            ->where('sequence', 1)
            ->first();

        $this->assertNotNull($timer);
        $this->assertSame('fired', $timer->status->value);
        $this->assertSame(0, $timer->delay_seconds);
        $this->assertNull($workflow->summary()?->wait_kind);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'TimerScheduled',
            'TimerFired',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testWorkflowCanBeCancelledWhileWaitingOnTimer(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class);
        $workflow->start(2);

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'timer');

        $result = $workflow->cancel();

        $this->assertTrue($result->accepted());
        $this->assertSame('cancel', $result->type());
        $this->assertSame('cancelled', $result->outcome());
        $this->assertSame($workflow->id(), $result->instanceId());
        $this->assertSame($runId, $result->runId());

        $workflow->refresh();

        $this->assertSame('cancelled', $workflow->status());
        $this->assertTrue($workflow->cancelled());
        $this->assertFalse($workflow->running());
        $this->assertNull($workflow->output());

        $summary = $workflow->summary();

        $this->assertNotNull($summary);
        $this->assertSame('cancelled', $summary->status);
        $this->assertSame('failed', $summary->status_bucket);
        $this->assertTrue($summary->is_terminal);
        $this->assertSame('cancelled', $summary->closed_reason);
        $this->assertNull($summary->wait_kind);
        $this->assertNotNull($summary->closed_at);

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $runId)
            ->where('sequence', 1)
            ->first();

        $this->assertNotNull($timer);
        $this->assertSame('cancelled', $timer->status->value);

        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'timer')
            ->first();

        $this->assertNotNull($timerTask);
        $this->assertSame('cancelled', $timerTask->status->value);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $workflow->id(),
            'workflow_run_id' => $runId,
            'command_type' => 'cancel',
            'status' => 'accepted',
            'outcome' => 'cancelled',
        ]);

        usleep(2500000);
        $workflow->refresh();

        $this->assertSame('cancelled', $workflow->status());
        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'TimerScheduled',
            'CancelRequested',
            'TimerCancelled',
            'WorkflowCancelled',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testRunTargetedCancelUsesRunScopeForCurrentRun(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'run-target-current');
        $workflow->start(5);

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'timer');

        $selectedRun = WorkflowStub::loadRun($runId);
        $result = $selectedRun->attemptCancel();

        $this->assertTrue($result->accepted());
        $this->assertSame('run', $result->targetScope());
        $this->assertSame('cancelled', $result->outcome());
        $this->assertSame('run-target-current', $result->instanceId());
        $this->assertSame($runId, $result->runId());

        $selectedRun->refresh();

        $this->assertSame($runId, $selectedRun->runId());
        $this->assertSame($runId, $selectedRun->currentRunId());
        $this->assertTrue($selectedRun->currentRunIsSelected());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'run-target-current',
            'workflow_run_id' => $runId,
            'command_type' => 'cancel',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'cancelled',
        ]);
    }

    public function testWorkflowCanBeTerminatedWhileWaitingOnTimer(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class);
        $workflow->start(5);

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'timer');

        $result = $workflow->terminate();

        $this->assertTrue($result->accepted());
        $this->assertSame('terminate', $result->type());
        $this->assertSame('terminated', $result->outcome());

        $workflow->refresh();

        $this->assertSame('terminated', $workflow->status());
        $this->assertTrue($workflow->terminated());
        $this->assertFalse($workflow->running());
        $this->assertNull($workflow->output());

        $summary = $workflow->summary();

        $this->assertNotNull($summary);
        $this->assertSame('terminated', $summary->status);
        $this->assertSame('failed', $summary->status_bucket);
        $this->assertTrue($summary->is_terminal);
        $this->assertSame('terminated', $summary->closed_reason);
        $this->assertNull($summary->wait_kind);

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $runId)
            ->where('sequence', 1)
            ->first();

        $this->assertNotNull($timer);
        $this->assertSame('cancelled', $timer->status->value);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'TimerScheduled',
            'TerminateRequested',
            'TimerCancelled',
            'WorkflowTerminated',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testRunTargetedCancelRejectsHistoricalSelectionWithDurableOutcome(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'historical-instance',
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'run_count' => 2,
            'reserved_at' => now()
                ->subMinutes(10),
            'started_at' => now()
                ->subMinutes(10),
        ]);

        /** @var WorkflowRun $historicalRun */
        $historicalRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([1]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(10),
            'closed_at' => now()
                ->subMinutes(9),
            'last_progress_at' => now()
                ->subMinutes(9),
        ]);

        /** @var WorkflowRun $currentRun */
        $currentRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([30]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $currentRun->id,
            'run_count' => 2,
        ])->save();

        RunSummaryProjector::project(
            $historicalRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
        RunSummaryProjector::project(
            $currentRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $selectedRun = WorkflowStub::loadRun($historicalRun->id);
        $result = $selectedRun->attemptCancel();

        $this->assertTrue($result->rejected());
        $this->assertTrue($result->rejectedNotCurrent());
        $this->assertSame('run', $result->targetScope());
        $this->assertSame('rejected_not_current', $result->outcome());
        $this->assertSame('selected_run_not_current', $result->rejectionReason());
        $this->assertSame($instance->id, $result->instanceId());
        $this->assertSame($historicalRun->id, $result->runId());
        $this->assertSame($historicalRun->id, $result->requestedRunId());
        $this->assertSame($currentRun->id, $result->resolvedRunId());

        $selectedRun->refresh();

        $this->assertSame($historicalRun->id, $selectedRun->runId());
        $this->assertSame($currentRun->id, $selectedRun->currentRunId());
        $this->assertFalse($selectedRun->currentRunIsSelected());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $historicalRun->id,
            'command_type' => 'cancel',
            'target_scope' => 'run',
            'status' => 'rejected',
            'outcome' => 'rejected_not_current',
            'rejection_reason' => 'selected_run_not_current',
        ]);
    }

    public function testRunTargetedCancelRejectsHistoricalSelectionWhenCurrentRunPointerDrifts(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'historical-instance-pointer-drift',
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'run_count' => 2,
            'reserved_at' => now()
                ->subMinutes(10),
            'started_at' => now()
                ->subMinutes(10),
        ]);

        /** @var WorkflowRun $historicalRun */
        $historicalRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([1]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(10),
            'closed_at' => now()
                ->subMinutes(9),
            'last_progress_at' => now()
                ->subMinutes(9),
        ]);

        /** @var WorkflowRun $currentRun */
        $currentRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([30]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => null,
            'run_count' => 2,
        ])->save();

        RunSummaryProjector::project(
            $historicalRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
        RunSummaryProjector::project(
            $currentRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $selectedRun = WorkflowStub::loadRun($historicalRun->id);
        $result = $selectedRun->attemptCancel();

        $this->assertTrue($result->rejected());
        $this->assertTrue($result->rejectedNotCurrent());
        $this->assertSame('run', $result->targetScope());
        $this->assertSame('rejected_not_current', $result->outcome());
        $this->assertSame('selected_run_not_current', $result->rejectionReason());
        $this->assertSame($instance->id, $result->instanceId());
        $this->assertSame($historicalRun->id, $result->runId());
        $this->assertSame($historicalRun->id, $result->requestedRunId());
        $this->assertSame($currentRun->id, $result->resolvedRunId());

        $selectedRun->refresh();

        $this->assertSame($historicalRun->id, $selectedRun->runId());
        $this->assertSame($currentRun->id, $selectedRun->currentRunId());
        $this->assertFalse($selectedRun->currentRunIsSelected());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $historicalRun->id,
            'command_type' => 'cancel',
            'target_scope' => 'run',
            'status' => 'rejected',
            'outcome' => 'rejected_not_current',
            'rejection_reason' => 'selected_run_not_current',
        ]);

        $this->assertSame($currentRun->id, $instance->fresh()->current_run_id);
    }

    public function testAttemptCancelRejectsReservedInstanceThatHasNotStarted(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'reserved-instance');

        $result = $workflow->attemptCancel();

        $this->assertTrue($result->rejected());
        $this->assertSame('cancel', $result->type());
        $this->assertSame('rejected_not_started', $result->outcome());
        $this->assertSame('instance_not_started', $result->rejectionReason());
        $this->assertSame('reserved-instance', $result->instanceId());
        $this->assertNull($result->runId());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'reserved-instance',
            'workflow_run_id' => null,
            'command_type' => 'cancel',
            'status' => 'rejected',
            'outcome' => 'rejected_not_started',
            'rejection_reason' => 'instance_not_started',
        ]);
    }

    public function testAttemptSignalRejectsClosedRun(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'closed-signal-instance');
        $workflow->start('Taylor');

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $result = $workflow->attemptSignal('name-provided', 'Jordan');

        $this->assertTrue($result->rejected());
        $this->assertSame('signal', $result->type());
        $this->assertSame('rejected_not_active', $result->outcome());
        $this->assertSame('run_not_active', $result->rejectionReason());
        $this->assertSame('closed-signal-instance', $result->instanceId());
        $this->assertSame($workflow->runId(), $result->runId());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'closed-signal-instance',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'signal',
            'status' => 'rejected',
            'outcome' => 'rejected_not_active',
            'rejection_reason' => 'run_not_active',
        ]);
    }

    public function testAttemptSignalRejectsUnknownSignalName(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'unknown-signal-instance');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $result = $workflow->attemptSignal('unknown-signal', 'Jordan');

        $this->assertTrue($result->rejected());
        $this->assertSame('signal', $result->type());
        $this->assertSame('rejected_unknown_signal', $result->outcome());
        $this->assertSame('unknown_signal', $result->rejectionReason());
        $this->assertSame('unknown-signal-instance', $result->instanceId());
        $this->assertSame($workflow->runId(), $result->runId());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'unknown-signal-instance',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'signal',
            'status' => 'rejected',
            'outcome' => 'rejected_unknown_signal',
            'rejection_reason' => 'unknown_signal',
        ]);
    }

    public function testAttemptTerminateRejectsClosedRun(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'completed-instance');
        $workflow->start('Taylor');

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $result = $workflow->attemptTerminate();

        $this->assertTrue($result->rejected());
        $this->assertSame('terminate', $result->type());
        $this->assertSame('rejected_not_active', $result->outcome());
        $this->assertSame('run_not_active', $result->rejectionReason());
        $this->assertSame('completed-instance', $result->instanceId());
        $this->assertSame($workflow->runId(), $result->runId());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'completed-instance',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'terminate',
            'status' => 'rejected',
            'outcome' => 'rejected_not_active',
            'rejection_reason' => 'run_not_active',
        ]);
    }

    public function testRepairRecreatesMissingActivityTaskForPendingActivityExecution(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-activity-instance',
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

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => TestGreetingActivity::class,
            'status' => ActivityStatus::Pending->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'activities',
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertSame('activity', $summary->wait_kind);

        $result = WorkflowStub::loadRun($run->id)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair', $result->type());
        $this->assertSame('repair_dispatched', $result->outcome());

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->sole();

        $this->assertSame(TaskType::Activity, $task->task_type);
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame([
            'activity_execution_id' => $execution->id,
        ], $task->payload);
        $this->assertSame(1, $task->repair_count);
        $this->assertSame('redis', $task->connection);
        $this->assertSame('activities', $task->queue);

        Queue::assertPushed(
            RunActivityTask::class,
            static fn (RunActivityTask $job): bool => $job->taskId === $task->id
        );

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('activity', $updatedSummary->wait_kind);
        $this->assertSame('activity_task_ready', $updatedSummary->liveness_state);
        $this->assertSame($task->id, $updatedSummary->next_task_id);
        $this->assertSame('activity', $updatedSummary->next_task_type);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_type' => 'repair',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'repair_dispatched',
        ]);

        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $run->id,
            'workflow_command_id' => $result->commandId(),
            'workflow_task_id' => $task->id,
            'event_type' => 'RepairRequested',
        ]);
    }

    public function testRepairRecreatesMissingActivityTaskFromTypedHistoryWhenActivityExecutionRowIsMissing(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'repair-activity-history-only');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();
        $executionId = $execution->id;
        $executionArguments = $execution->arguments;
        $retryPolicy = $execution->retry_policy;

        WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->delete();
        $execution->delete();

        $summary = RunSummaryProjector::project(
            WorkflowRun::query()
                ->with(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                ->findOrFail($runId)
        );

        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertSame('activity', $summary->wait_kind);
        $this->assertSame($executionId, $summary->resume_source_id);
        $this->assertNull($summary->next_task_id);

        $result = WorkflowStub::loadRun($runId)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair_dispatched', $result->outcome());

        /** @var ActivityExecution $restoredExecution */
        $restoredExecution = ActivityExecution::query()->findOrFail($executionId);

        $this->assertSame($runId, $restoredExecution->workflow_run_id);
        $this->assertSame(1, $restoredExecution->sequence);
        $this->assertSame(TestGreetingActivity::class, $restoredExecution->activity_class);
        $this->assertSame(TestGreetingActivity::class, $restoredExecution->activity_type);
        $this->assertSame(ActivityStatus::Pending, $restoredExecution->status);
        $this->assertSame($executionArguments, $restoredExecution->arguments);
        $this->assertSame(0, $restoredExecution->attempt_count);
        $this->assertSame($retryPolicy, $restoredExecution->retry_policy);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->sole();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame([
            'activity_execution_id' => $executionId,
        ], $task->payload);
        $this->assertSame(1, $task->repair_count);

        Queue::assertPushed(
            RunActivityTask::class,
            static fn (RunActivityTask $job): bool => $job->taskId === $task->id
        );

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($runId);

        $this->assertSame('activity', $updatedSummary->wait_kind);
        $this->assertSame('activity_task_ready', $updatedSummary->liveness_state);
        $this->assertSame($task->id, $updatedSummary->next_task_id);
    }

    public function testRepairRecreatesMissingDelayedActivityRetryTaskFromTypedHistory(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-09 12:00:00'));

        try {
            $workflow = WorkflowStub::make(TestRetryWorkflow::class, 'repair-retry-activity-instance');
            $workflow->start('Taylor');
            $runId = $workflow->runId();

            $this->assertNotNull($runId);

            $this->runReadyTaskForRun($runId, TaskType::Workflow);
            $this->runReadyTaskForRun($runId, TaskType::Activity);

            /** @var ActivityExecution $execution */
            $execution = ActivityExecution::query()
                ->where('workflow_run_id', $runId)
                ->firstOrFail();
            /** @var WorkflowTask $missingRetryTask */
            $missingRetryTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Activity->value)
                ->where('status', TaskStatus::Ready->value)
                ->firstOrFail();

            $originalRetryTaskId = $missingRetryTask->id;
            $originalRetryPayload = $missingRetryTask->payload;
            $originalRetryAvailableAt = $missingRetryTask->available_at?->toJSON();

            $this->assertSame(Carbon::parse('2026-04-09 12:00:05')->toJSON(), $originalRetryAvailableAt);

            $missingRetryTask->delete();

            $summary = RunSummaryProjector::project(
                WorkflowRun::query()
                    ->with(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                    ->findOrFail($runId)
            );

            $this->assertSame('repair_needed', $summary->liveness_state);
            $this->assertSame('activity', $summary->wait_kind);
            $this->assertNull($summary->next_task_id);

            $result = WorkflowStub::loadRun($runId)->attemptRepair();

            $this->assertTrue($result->accepted());
            $this->assertSame('repair_dispatched', $result->outcome());

            /** @var WorkflowTask $repairedRetryTask */
            $repairedRetryTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Activity->value)
                ->where('status', TaskStatus::Ready->value)
                ->firstOrFail();

            $this->assertNotSame($originalRetryTaskId, $repairedRetryTask->id);
            $this->assertSame($execution->id, $repairedRetryTask->payload['activity_execution_id'] ?? null);
            $this->assertSame(
                $originalRetryPayload['retry_of_task_id'],
                $repairedRetryTask->payload['retry_of_task_id'] ?? null
            );
            $this->assertSame(
                $originalRetryPayload['retry_after_attempt_id'],
                $repairedRetryTask->payload['retry_after_attempt_id'] ?? null
            );
            $this->assertSame(
                $originalRetryPayload['retry_after_attempt'],
                $repairedRetryTask->payload['retry_after_attempt'] ?? null
            );
            $this->assertSame(
                $originalRetryPayload['retry_backoff_seconds'],
                $repairedRetryTask->payload['retry_backoff_seconds'] ?? null
            );
            $this->assertSame(
                $originalRetryPayload['max_attempts'],
                $repairedRetryTask->payload['max_attempts'] ?? null
            );
            $this->assertSame(
                $originalRetryPayload['retry_policy'],
                $repairedRetryTask->payload['retry_policy'] ?? null
            );
            $this->assertSame($originalRetryAvailableAt, $repairedRetryTask->available_at?->toJSON());
            $this->assertSame(1, $repairedRetryTask->attempt_count);
            $this->assertSame(1, $repairedRetryTask->repair_count);

            Queue::assertPushed(
                RunActivityTask::class,
                static fn (RunActivityTask $job): bool => $job->taskId === $repairedRetryTask->id
            );

            $detail = RunDetailView::forRun(WorkflowRun::query()->findOrFail($runId));

            $this->assertSame('scheduled', $detail['tasks'][0]['transport_state']);
            $this->assertSame(1, $detail['tasks'][0]['retry_after_attempt']);
            $this->assertSame(5, $detail['tasks'][0]['retry_backoff_seconds']);
            $this->assertSame(2, $detail['tasks'][0]['retry_max_attempts']);
            $this->assertSame($execution->retry_policy, $detail['tasks'][0]['retry_policy']);

            Carbon::setTestNow(Carbon::parse('2026-04-09 12:00:05'));

            $this->runReadyTaskForRun($runId, TaskType::Activity);
            $this->runReadyTaskForRun($runId, TaskType::Workflow);

            $this->assertTrue($workflow->refresh()->completed());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testTaskWatchdogRecreatesMissingDelayedActivityRetryTaskFromTypedHistory(): void
    {
        Queue::fake();
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Carbon::setTestNow(Carbon::parse('2026-04-09 12:00:00'));

        try {
            $workflow = WorkflowStub::make(TestRetryWorkflow::class, 'repair-watchdog-retry-activity-instance');
            $workflow->start('Taylor');
            $runId = $workflow->runId();

            $this->assertNotNull($runId);

            $this->runReadyTaskForRun($runId, TaskType::Workflow);
            $this->runReadyTaskForRun($runId, TaskType::Activity);

            /** @var ActivityExecution $execution */
            $execution = ActivityExecution::query()
                ->where('workflow_run_id', $runId)
                ->firstOrFail();
            /** @var WorkflowTask $missingRetryTask */
            $missingRetryTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Activity->value)
                ->where('status', TaskStatus::Ready->value)
                ->firstOrFail();

            $originalRetryTaskId = $missingRetryTask->id;
            $originalRetryPayload = $missingRetryTask->payload;
            $originalRetryAvailableAt = $missingRetryTask->available_at?->toJSON();

            $this->assertSame(Carbon::parse('2026-04-09 12:00:05')->toJSON(), $originalRetryAvailableAt);

            $missingRetryTask->delete();

            $summary = RunSummaryProjector::project(
                WorkflowRun::query()
                    ->with(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                    ->findOrFail($runId)
            );

            $this->assertSame('repair_needed', $summary->liveness_state);
            $this->assertSame('activity', $summary->wait_kind);
            $this->assertNull($summary->next_task_id);

            $this->wakeTaskWatchdog();

            /** @var WorkflowTask $repairedRetryTask */
            $repairedRetryTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Activity->value)
                ->where('status', TaskStatus::Ready->value)
                ->firstOrFail();

            $this->assertNotSame($originalRetryTaskId, $repairedRetryTask->id);
            $this->assertSame($execution->id, $repairedRetryTask->payload['activity_execution_id'] ?? null);
            $this->assertSame(
                $originalRetryPayload['retry_of_task_id'],
                $repairedRetryTask->payload['retry_of_task_id'] ?? null
            );
            $this->assertSame(
                $originalRetryPayload['retry_after_attempt_id'],
                $repairedRetryTask->payload['retry_after_attempt_id'] ?? null
            );
            $this->assertSame(
                $originalRetryPayload['retry_after_attempt'],
                $repairedRetryTask->payload['retry_after_attempt'] ?? null
            );
            $this->assertSame(
                $originalRetryPayload['retry_backoff_seconds'],
                $repairedRetryTask->payload['retry_backoff_seconds'] ?? null
            );
            $this->assertSame(
                $originalRetryPayload['max_attempts'],
                $repairedRetryTask->payload['max_attempts'] ?? null
            );
            $this->assertSame(
                $originalRetryPayload['retry_policy'],
                $repairedRetryTask->payload['retry_policy'] ?? null
            );
            $this->assertSame($originalRetryAvailableAt, $repairedRetryTask->available_at?->toJSON());
            $this->assertSame(1, $repairedRetryTask->attempt_count);
            $this->assertSame(1, $repairedRetryTask->repair_count);

            Queue::assertPushed(
                RunActivityTask::class,
                static fn (RunActivityTask $job): bool => $job->taskId === $repairedRetryTask->id
            );

            $detail = RunDetailView::forRun(WorkflowRun::query()->findOrFail($runId));

            $this->assertSame('scheduled', $detail['tasks'][0]['transport_state']);
            $this->assertSame(1, $detail['tasks'][0]['retry_after_attempt']);
            $this->assertSame(5, $detail['tasks'][0]['retry_backoff_seconds']);
            $this->assertSame(2, $detail['tasks'][0]['retry_max_attempts']);
            $this->assertSame($execution->retry_policy, $detail['tasks'][0]['retry_policy']);

            Carbon::setTestNow(Carbon::parse('2026-04-09 12:00:05'));

            $this->runReadyTaskForRun($runId, TaskType::Activity);
            $this->runReadyTaskForRun($runId, TaskType::Workflow);

            $this->assertTrue($workflow->refresh()->completed());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testRepairReturnsAcceptedNoOpForRowOnlyRunningActivityFallback(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-running-activity-01',
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

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => TestGreetingActivity::class,
            'status' => ActivityStatus::Running->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'activities',
            'started_at' => now()
                ->subSeconds(20),
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertNull($summary->wait_kind);
        $this->assertSame('workflow_replay_blocked', $summary->liveness_state);
        $this->assertSame(
            sprintf(
                'Activity %s is visible only from an older mutable row without typed activity history. This row is diagnostic-only and does not satisfy the durable resume-path invariant.',
                TestGreetingActivity::class,
            ),
            $summary->liveness_reason,
        );

        $result = WorkflowStub::loadRun($run->id)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair', $result->type());
        $this->assertSame('repair_not_needed', $result->outcome());
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());

        Queue::assertNothingPushed();

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertNull($updatedSummary->wait_kind);
        $this->assertSame('workflow_replay_blocked', $updatedSummary->liveness_state);
        $this->assertNull($updatedSummary->next_task_id);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_type' => 'repair',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'repair_not_needed',
        ]);

        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $run->id,
            'workflow_command_id' => $result->commandId(),
            'workflow_task_id' => null,
            'event_type' => 'RepairRequested',
        ]);
    }

    public function testRepairReturnsAcceptedNoOpForRowOnlyOpenChildFallback(): void
    {
        Queue::fake();

        $parentInstance = WorkflowInstance::query()->create([
            'id' => 'repair-row-only-child-parent',
            'workflow_class' => TestParentWaitingOnChildWorkflow::class,
            'workflow_type' => 'workflow.parent',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);

        $childInstance = WorkflowInstance::query()->create([
            'id' => 'repair-row-only-child-child',
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'workflow.child',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);

        /** @var WorkflowRun $parentRun */
        $parentRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $parentInstance->id,
            'run_number' => 1,
            'workflow_class' => TestParentWaitingOnChildWorkflow::class,
            'workflow_type' => 'workflow.parent',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([60]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'workflow.child',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([30]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subSeconds(50),
            'last_progress_at' => now()
                ->subSeconds(20),
        ]);

        $parentInstance->forceFill([
            'current_run_id' => $parentRun->id,
        ])->save();
        $childInstance->forceFill([
            'current_run_id' => $childRun->id,
        ])->save();

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()->create([
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
            'created_at' => now()
                ->subSeconds(45),
            'updated_at' => now()
                ->subSeconds(45),
        ]);

        $summary = RunSummaryProjector::project(
            $parentRun->fresh([
                'instance',
                'tasks',
                'activityExecutions',
                'timers',
                'failures',
                'historyEvents',
                'childLinks.childRun.instance.currentRun',
                'childLinks.childRun.failures',
                'childLinks.childRun.historyEvents',
            ])
        );

        $this->assertNull($summary->wait_kind);
        $this->assertSame('workflow_replay_blocked', $summary->liveness_state);
        $this->assertSame(
            'Child workflow workflow.child is visible only from an older mutable row or link without typed parent child history. This state is diagnostic-only and does not satisfy the durable resume-path invariant.',
            $summary->liveness_reason,
        );

        $result = WorkflowStub::loadRun($parentRun->id)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair_not_needed', $result->outcome());
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $parentRun->id)->count());

        Queue::assertNothingPushed();

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($parentRun->id);

        $this->assertNull($updatedSummary->wait_kind);
        $this->assertSame('workflow_replay_blocked', $updatedSummary->liveness_state);
        $this->assertNull($updatedSummary->next_task_id);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $parentInstance->id,
            'workflow_run_id' => $parentRun->id,
            'command_type' => 'repair',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'repair_not_needed',
        ]);

        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $parentRun->id,
            'workflow_command_id' => $result->commandId(),
            'workflow_task_id' => null,
            'event_type' => 'RepairRequested',
        ]);
        $this->assertSame($childRun->id, $link->child_workflow_run_id);
    }

    public function testWorkflowCanBeCancelledWithReasonWhileWaitingOnActivity(): void
    {
        $workflow = WorkflowStub::make(TestHeartbeatWorkflow::class, 'cancel-activity-wait');
        $workflow->start();

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'activity');

        $result = $workflow->cancel('Customer requested cancellation');

        $this->assertTrue($result->accepted());
        $this->assertSame('cancel', $result->type());
        $this->assertSame('cancelled', $result->outcome());
        $this->assertSame('Customer requested cancellation', $result->reason());
        $this->assertSame($workflow->id(), $result->instanceId());
        $this->assertSame($runId, $result->runId());

        $workflow->refresh();

        $this->assertSame('cancelled', $workflow->status());
        $this->assertTrue($workflow->cancelled());
        $this->assertFalse($workflow->running());

        $summary = $workflow->summary();

        $this->assertNotNull($summary);
        $this->assertSame('cancelled', $summary->status);
        $this->assertSame('failed', $summary->status_bucket);
        $this->assertTrue($summary->is_terminal);
        $this->assertSame('cancelled', $summary->closed_reason);
        $this->assertNull($summary->wait_kind);

        $activityExecution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->first();

        $this->assertNotNull($activityExecution);
        $this->assertSame('cancelled', $activityExecution->status->value);

        $cancelRequestedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::CancelRequested)
            ->first();

        $this->assertNotNull($cancelRequestedEvent);
        $this->assertSame('Customer requested cancellation', $cancelRequestedEvent->payload['reason'] ?? null);

        $cancelledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowCancelled)
            ->first();

        $this->assertNotNull($cancelledEvent);
        $this->assertSame('Customer requested cancellation', $cancelledEvent->payload['reason'] ?? null);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'cancel-activity-wait',
            'workflow_run_id' => $runId,
            'command_type' => 'cancel',
            'status' => 'accepted',
            'outcome' => 'cancelled',
        ]);

        $command = WorkflowCommand::query()->findOrFail($result->commandId());

        $this->assertSame('Customer requested cancellation', $command->commandReason());

        $eventTypes = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all();

        $this->assertContains('CancelRequested', $eventTypes);
        $this->assertContains('ActivityCancelled', $eventTypes);
        $this->assertContains('WorkflowCancelled', $eventTypes);
    }

    public function testWorkflowCanBeTerminatedWithReasonWhileWaitingOnActivity(): void
    {
        $workflow = WorkflowStub::make(TestHeartbeatWorkflow::class, 'terminate-activity-wait');
        $workflow->start();

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'activity');

        $result = $workflow->terminate('Operator emergency shutdown');

        $this->assertTrue($result->accepted());
        $this->assertSame('terminate', $result->type());
        $this->assertSame('terminated', $result->outcome());
        $this->assertSame('Operator emergency shutdown', $result->reason());

        $workflow->refresh();

        $this->assertSame('terminated', $workflow->status());
        $this->assertTrue($workflow->terminated());

        $summary = $workflow->summary();

        $this->assertNotNull($summary);
        $this->assertSame('terminated', $summary->status);
        $this->assertSame('failed', $summary->status_bucket);
        $this->assertTrue($summary->is_terminal);
        $this->assertSame('terminated', $summary->closed_reason);

        $activityExecution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->first();

        $this->assertNotNull($activityExecution);
        $this->assertSame('cancelled', $activityExecution->status->value);

        $terminateRequestedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::TerminateRequested)
            ->first();

        $this->assertNotNull($terminateRequestedEvent);
        $this->assertSame('Operator emergency shutdown', $terminateRequestedEvent->payload['reason'] ?? null);

        $terminatedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowTerminated)
            ->first();

        $this->assertNotNull($terminatedEvent);
        $this->assertSame('Operator emergency shutdown', $terminatedEvent->payload['reason'] ?? null);

        $command = WorkflowCommand::query()->findOrFail($result->commandId());

        $this->assertSame('Operator emergency shutdown', $command->commandReason());
    }

    public function testCancelWithoutReasonLeavesReasonNull(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'cancel-no-reason');
        $workflow->start(2);

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'timer');

        $result = $workflow->cancel();

        $this->assertTrue($result->accepted());
        $this->assertNull($result->reason());

        $command = WorkflowCommand::query()->findOrFail($result->commandId());

        $this->assertNull($command->commandReason());

        $cancelRequestedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::CancelRequested)
            ->first();

        $this->assertNotNull($cancelRequestedEvent);
        $this->assertArrayNotHasKey('reason', $cancelRequestedEvent->payload ?? []);
    }

    public function testCancelCreatesFailureRowWithCancelledCategory(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'cancel-failure-row');
        $workflow->start(5);

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'timer');

        $result = $workflow->cancel('User requested cancellation');

        $this->assertTrue($result->accepted());
        $this->assertSame('cancelled', $workflow->refresh()->status());

        // Verify failure row was created.
        $this->assertDatabaseHas('workflow_failures', [
            'workflow_run_id' => $runId,
            'source_kind' => 'workflow_run',
            'source_id' => $runId,
            'propagation_kind' => 'cancelled',
            'failure_category' => 'cancelled',
            'handled' => false,
            'exception_class' => 'Workflow\\V2\\Exceptions\\WorkflowCancelledException',
            'message' => 'Workflow cancelled: User requested cancellation',
        ]);

        // Verify history event includes failure_id and failure_category.
        $cancelledEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowCancelled)
            ->first();

        $this->assertNotNull($cancelledEvent);
        $this->assertNotNull($cancelledEvent->payload['failure_id'] ?? null);
        $this->assertSame('cancelled', $cancelledEvent->payload['failure_category'] ?? null);
        $this->assertSame('User requested cancellation', $cancelledEvent->payload['reason'] ?? null);

        // Verify failure row ID matches the history event.
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $runId)
            ->first();

        $this->assertNotNull($failure);
        $this->assertSame($failure->id, $cancelledEvent->payload['failure_id']);
        $this->assertSame(FailureCategory::Cancelled, $failure->failure_category);
    }

    public function testTerminateCreatesFailureRowWithTerminatedCategory(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'terminate-failure-row');
        $workflow->start(5);

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'timer');

        $result = $workflow->terminate('Emergency shutdown');

        $this->assertTrue($result->accepted());
        $this->assertSame('terminated', $workflow->refresh()->status());

        // Verify failure row was created.
        $this->assertDatabaseHas('workflow_failures', [
            'workflow_run_id' => $runId,
            'source_kind' => 'workflow_run',
            'source_id' => $runId,
            'propagation_kind' => 'terminated',
            'failure_category' => 'terminated',
            'handled' => false,
            'exception_class' => 'Workflow\\V2\\Exceptions\\WorkflowTerminatedException',
            'message' => 'Workflow terminated: Emergency shutdown',
        ]);

        // Verify history event includes failure_id and failure_category.
        $terminatedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowTerminated)
            ->first();

        $this->assertNotNull($terminatedEvent);
        $this->assertNotNull($terminatedEvent->payload['failure_id'] ?? null);
        $this->assertSame('terminated', $terminatedEvent->payload['failure_category'] ?? null);
        $this->assertSame('Emergency shutdown', $terminatedEvent->payload['reason'] ?? null);

        // Verify failure row ID matches.
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $runId)
            ->first();

        $this->assertNotNull($failure);
        $this->assertSame($failure->id, $terminatedEvent->payload['failure_id']);
        $this->assertSame(FailureCategory::Terminated, $failure->failure_category);
    }

    public function testCancelWithoutReasonCreatesFailureRowWithDefaultMessage(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'cancel-no-reason-failure');
        $workflow->start(5);

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'timer');

        $result = $workflow->cancel();

        $this->assertTrue($result->accepted());

        $this->assertDatabaseHas('workflow_failures', [
            'workflow_run_id' => $runId,
            'propagation_kind' => 'cancelled',
            'failure_category' => 'cancelled',
            'message' => 'Workflow cancelled.',
        ]);
    }

    public function testCancelFailureRowAppearsInFailureSnapshots(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'cancel-snapshots');
        $workflow->start(5);

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'timer');

        $workflow->cancel('Snapshot test');

        $run = WorkflowRun::query()->findOrFail($runId);
        $run->load(['historyEvents', 'failures']);

        $snapshots = FailureSnapshots::forRun($run);

        $this->assertNotEmpty($snapshots);

        $cancelSnapshot = $snapshots[0];

        $this->assertSame('cancelled', $cancelSnapshot['failure_category']);
        $this->assertSame('cancelled', $cancelSnapshot['propagation_kind']);
        $this->assertSame('workflow_run', $cancelSnapshot['source_kind']);
        $this->assertSame('Workflow cancelled: Snapshot test', $cancelSnapshot['message']);
        $this->assertFalse($cancelSnapshot['handled']);
    }

    private function waitFor(callable $condition): void
    {
        $deadline = microtime(true) + 10;

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

    private function runReadyTaskForRun(string $runId, TaskType $taskType): void
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', $taskType->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->first();

        if ($task === null) {
            $this->fail(sprintf('Expected a ready %s task for run %s.', $taskType->value, $runId));
        }

        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };

        $this->app->call([$job, 'handle']);
    }

    private function runReadyActivityTaskForSequence(string $runId, int $sequence): void
    {
        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->where('sequence', $sequence)
            ->firstOrFail();

        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->where('status', TaskStatus::Ready->value)
            ->get()
            ->sole(
                static fn (WorkflowTask $task): bool => ($task->payload['activity_execution_id'] ?? null) === $execution->id
            );

        $this->app->call([new RunActivityTask($task->id), 'handle']);
    }

    /**
     * @param list<array<string, mixed>> $path
     */
    private function replaceActivityScheduledParallelPath(string $runId, int $sequence, array $path): void
    {
        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityScheduled->value)
            ->get()
            ->sole(
                static fn (WorkflowHistoryEvent $event): bool => ($event->payload['sequence'] ?? null) === $sequence
            );

        $payload = is_array($event->payload) ? $event->payload : [];
        $last = $path[array_key_last($path)] ?? [];

        $event->forceFill([
            'payload' => array_merge($payload, $last, [
                'parallel_group_path' => $path,
            ]),
        ])->save();
    }

    private function removeActivityHistoryParallelMetadata(string $runId, int $sequence): void
    {
        WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('event_type', [
                HistoryEventType::ActivityScheduled->value,
                HistoryEventType::ActivityStarted->value,
                HistoryEventType::ActivityHeartbeatRecorded->value,
                HistoryEventType::ActivityRetryScheduled->value,
                HistoryEventType::ActivityCompleted->value,
                HistoryEventType::ActivityFailed->value,
            ])
            ->get()
            ->each(static function (WorkflowHistoryEvent $event) use ($sequence): void {
                $payload = is_array($event->payload) ? $event->payload : [];

                if (($payload['sequence'] ?? null) !== $sequence) {
                    return;
                }

                unset(
                    $payload['parallel_group_id'],
                    $payload['parallel_group_kind'],
                    $payload['parallel_group_base_sequence'],
                    $payload['parallel_group_size'],
                    $payload['parallel_group_index'],
                    $payload['parallel_group_path'],
                );

                $event->forceFill([
                    'payload' => $payload,
                ])->save();
            });
    }

    private function wakeTaskWatchdog(): void
    {
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);
        TaskWatchdog::wake();
    }

    private function configureGreetingTypeMaps(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'config-greeting-workflow' => TestConfiguredGreetingWorkflow::class,
        ]);

        config()
            ->set('workflows.v2.types.activities', [
                'config-greeting-activity' => TestConfiguredGreetingActivity::class,
            ]);
    }

    private function configureContinueSignalTypeMap(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'config-continue-signal-workflow' => TestConfiguredContinueSignalWorkflow::class,
        ]);
    }
}
