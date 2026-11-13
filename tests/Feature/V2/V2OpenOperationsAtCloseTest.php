<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestSelectionLoserAtCloseWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\ActivityTaskClaimer;
use Workflow\V2\Support\ClosingRunOpenWork;
use Workflow\V2\Support\WorkflowCommandNormalizer;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

/**
 * An operation the workflow never awaited gets a recorded outcome when the
 * run's own code closes it, and nothing is appended after the terminal event
 * (#1117).
 */
final class V2OpenOperationsAtCloseTest extends TestCase
{
    private const TERMINAL_EVENTS = [
        HistoryEventType::WorkflowCompleted,
        HistoryEventType::WorkflowFailed,
        HistoryEventType::WorkflowContinuedAsNew,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
    }

    /**
     * @return iterable<string, array{string, RunStatus, string, HistoryEventType}>
     */
    public static function closes(): iterable
    {
        yield 'complete' => ['complete', RunStatus::Completed, 'completed', HistoryEventType::WorkflowCompleted];
        yield 'fail' => ['fail', RunStatus::Failed, 'failed', HistoryEventType::WorkflowFailed];
        yield 'continue as new' => [
            'continue',
            RunStatus::Completed,
            'continued',
            HistoryEventType::WorkflowContinuedAsNew,
        ];
    }

    #[DataProvider('closes')]
    public function testCloseCancelsTheUnawaitedSelectionLoserBeforeTheTerminalEvent(
        string $mode,
        RunStatus $status,
        string $closedReason,
        HistoryEventType $terminalType,
    ): void {
        $workflow = WorkflowStub::make(TestSelectionLoserAtCloseWorkflow::class, 'loser-at-close-' . $mode);
        $workflow->start($mode);
        $runId = $workflow->runId();

        $this->runReadyTask($runId, TaskType::Workflow);

        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertSame($status, $run->status);
        $this->assertSame($closedReason, $run->closed_reason);

        $execution = ActivityExecution::query()->where('workflow_run_id', $runId)->sole();
        $this->assertSame(ActivityStatus::Cancelled, $execution->status);
        $this->assertSame(TaskStatus::Cancelled, WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->sole()
            ->status);

        $events = $this->events($runId);
        $cancelled = $events->firstWhere('event_type', HistoryEventType::ActivityCancelled);
        $this->assertInstanceOf(WorkflowHistoryEvent::class, $cancelled);
        $this->assertSame($execution->id, $cancelled->payload['activity_execution_id']);
        $this->assertSame($terminalType, $events->last()->event_type);
        $this->assertLessThan($events->last()->sequence, $cancelled->sequence);
        $this->assertArrayNotHasKey(ClosingRunOpenWork::ABANDONED_ACTIVITIES, $events->last()->payload);

        // A late claim of the cancelled activity starts nothing.
        $claim = ActivityTaskClaimer::claimDetailed(WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->sole()
            ->id);
        $this->assertNull($claim['claim']);
        $this->assertSame($events->count(), $this->events($runId)->count());

        // Cold replay of the closed run reaches the same close; the loser's
        // recorded cancellation does not disturb it.
        try {
            (new WorkflowReplayer())->replay(WorkflowRun::query()->findOrFail($runId));
            $this->assertNotSame('fail', $mode);
        } catch (\RuntimeException $exception) {
            $this->assertSame('fail', $mode);
            $this->assertSame('Closing with an open select() loser.', $exception->getMessage());
        }

        if ($mode === 'continue') {
            $continued = WorkflowRun::query()
                ->where('workflow_instance_id', $run->workflow_instance_id)
                ->where('run_number', 2)
                ->sole();
            $this->runReadyTask($continued->id, TaskType::Workflow);
            $this->assertSame(RunStatus::Completed, $continued->refresh()->status);
            $this->assertSame($events->count(), $this->events($runId)->count());
        }
    }

    public function testCloseCancelsAPendingTimerLoser(): void
    {
        $workflow = WorkflowStub::make(TestSelectionLoserAtCloseWorkflow::class, 'timer-loser-at-close');
        $workflow->start('timer-loser');
        $runId = $workflow->runId();

        $this->runReadyTask($runId, TaskType::Workflow);
        $this->runReadyTask($runId, TaskType::Activity);
        $this->runReadyTask($runId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('closed-with-work', $workflow->output());

        $timer = WorkflowTimer::query()->where('workflow_run_id', $runId)->sole();
        $this->assertSame(TimerStatus::Cancelled, $timer->status);
        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());

        $events = $this->events($runId);
        $cancelled = $events->firstWhere('event_type', HistoryEventType::TimerCancelled);
        $this->assertInstanceOf(WorkflowHistoryEvent::class, $cancelled);
        $this->assertSame($timer->id, $cancelled->payload['timer_id']);
        $this->assertSame(HistoryEventType::WorkflowCompleted, $events->last()->event_type);
        $this->assertLessThan($events->last()->sequence, $cancelled->sequence);

        (new WorkflowReplayer())->replay(WorkflowRun::query()->findOrFail($runId));
    }

    public function testACancellationRecordedAtCloseNeverReplacesTheFailureTheWorkflowHandled(): void
    {
        // Frozen time gives the close's cancellation the same close time as
        // the failure, and its member index is lower, so a replay that counted
        // it would hand the workflow "Activity cancelled" instead of "boom".
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestSelectionLoserAtCloseWorkflow::class, 'all-failure-at-close');
        $workflow->start('all-failure');
        $runId = $workflow->runId();
        $this->runReadyTask($runId, TaskType::Workflow);

        $failingTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->get()
            ->sole(static fn (WorkflowTask $task): bool => ActivityExecution::query()
                ->findOrFail($task->payload['activity_execution_id'])->sequence === 2);
        $this->app->call([new RunActivityTask($failingTask->id), 'handle']);
        $this->runReadyTask($runId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('caught: boom', $workflow->output());
        $cancelled = $this->events($runId)
            ->firstWhere('event_type', HistoryEventType::ActivityCancelled);
        $this->assertSame(1, $cancelled->payload['sequence']);
        $this->assertSame(ClosingRunOpenWork::CANCELLATION_REASON, $cancelled->payload['cancellation_reason']);

        $replayed = (new \Workflow\V2\Support\QueryStateReplayer())->replayState(
            WorkflowRun::query()->findOrFail($runId),
        );
        $this->assertSame('boom', $replayed->workflow->caught);
        (new WorkflowReplayer())->replay(WorkflowRun::query()->findOrFail($runId));
    }

    public function testARunningLoserIsCancelledAtCloseAndItsLateOutcomeIsRefused(): void
    {
        $workflow = WorkflowStub::make(TestSelectionLoserAtCloseWorkflow::class, 'running-loser-at-close');
        $workflow->start('activity-loser');
        $runId = $workflow->runId();
        $this->runReadyTask($runId, TaskType::Workflow);

        [$firstTask, $secondTask] = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->sortBy(static fn (WorkflowTask $task): int => (int) ActivityExecution::query()
                ->findOrFail($task->payload['activity_execution_id'])->sequence)
            ->values()
            ->all();

        /** @var ActivityTaskBridge $activities */
        $activities = $this->app->make(ActivityTaskBridge::class);
        $claim = $activities->claimStatus($firstTask->id, 'slow-worker');
        $this->assertTrue($claim['claimed']);
        $attemptId = $claim['activity_attempt_id'] ?? null;
        $this->assertIsString($attemptId);

        $this->app->call([new RunActivityTask($secondTask->id), 'handle']);
        $this->runReadyTask($runId, TaskType::Workflow);
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('closed-with-second', $workflow->output());

        $first = ActivityExecution::query()->findOrFail($firstTask->payload['activity_execution_id']);
        $this->assertSame(ActivityStatus::Cancelled, $first->status);
        $eventCount = $this->events($runId)
            ->count();
        $this->assertSame(HistoryEventType::WorkflowCompleted, $this->events($runId)->last()->event_type);

        $heartbeat = $activities->heartbeat($attemptId);
        $this->assertFalse($heartbeat['can_continue']);
        $this->assertTrue($heartbeat['cancel_requested']);

        // The close cancelled this attempt, so its late outcome is stale and
        // nothing follows the terminal event.
        $outcome = $activities->complete($attemptId, 'late result');
        $this->assertFalse($outcome['recorded']);
        $this->assertSame('stale_attempt', $outcome['reason']);
        $this->assertSame(ActivityStatus::Cancelled, $first->refresh()->status);
        $this->assertSame($eventCount, $this->events($runId)->count());
    }

    public function testAnOpenActivityLeftByAnEarlierReleaseIsNotStartedOnItsClosedRun(): void
    {
        $workflow = WorkflowStub::make(TestSelectionLoserAtCloseWorkflow::class, 'legacy-loser-at-close');
        $workflow->start('complete');
        $runId = $workflow->runId();
        $this->runReadyTask($runId, TaskType::Workflow);

        // Reconstruct a run closed before #1117: the loser's task is still
        // ready and nothing recorded its outcome.
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->sole();
        $task->forceFill([
            'status' => TaskStatus::Ready,
        ])->save();
        ActivityExecution::query()->whereKey($task->payload['activity_execution_id'])->update([
            'status' => ActivityStatus::Pending->value,
            'closed_at' => null,
        ]);
        WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityCancelled->value)
            ->delete();
        $eventCount = $this->events($runId)
            ->count();

        $claim = ActivityTaskClaimer::claimDetailed($task->id);

        $this->assertNull($claim['claim']);
        $this->assertSame('run_closed', $claim['reason']);
        $this->assertSame(TaskStatus::Cancelled, $task->refresh()->status);
        $this->assertSame(
            ActivityStatus::Cancelled,
            ActivityExecution::query()->findOrFail($task->payload['activity_execution_id'])->status,
        );
        $this->assertSame($eventCount, $this->events($runId)->count());
        $this->assertSame(HistoryEventType::WorkflowCompleted, $this->events($runId)->last()->event_type);
    }

    public function testServiceModeCloseCancelsOpenActivitiesAndKeepsAnAbandonedOneRunningWithoutHistory(): void
    {
        /** @var WorkflowTaskBridge $bridge */
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        /** @var ActivityTaskBridge $activities */
        $activities = $this->app->make(ActivityTaskBridge::class);
        $run = $this->newServiceRun();

        $commands = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'tests.cancelled-at-close',
                'arguments' => Serializer::serialize(['cancelled']),
            ],
            [
                'type' => 'schedule_activity',
                'activity_type' => 'tests.abandoned-at-close',
                'arguments' => Serializer::serialize(['abandoned']),
                'schedule_to_close_timeout' => 180,
                'cancellation_policy' => 'abandon',
            ],
        ], '1.20');
        $this->assertTrue($bridge->complete($this->newLease($run)->id, $commands)['completed']);
        $this->assertTrue($bridge->complete($this->newLease($run)->id, [[
            'type' => 'complete_workflow',
            'result' => Serializer::serialize('done'),
        ]])['completed']);

        $this->assertSame(RunStatus::Completed, $run->refresh()->status);
        [$cancelled, $abandoned] = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get()
            ->all();
        $this->assertSame(ActivityStatus::Cancelled, $cancelled->status);
        $this->assertSame(ActivityStatus::Pending, $abandoned->status);

        $events = $this->events($run->id);
        $terminal = $events->last();
        $this->assertSame(HistoryEventType::WorkflowCompleted, $terminal->event_type);
        $this->assertSame([[
            'activity_execution_id' => $abandoned->id,
            'sequence' => $abandoned->sequence,
        ]], $terminal->payload[ClosingRunOpenWork::ABANDONED_ACTIVITIES]);
        $this->assertSame(1, $events->where('event_type', HistoryEventType::ActivityCancelled)->count());
        $this->assertSame(
            $cancelled->id,
            $events->firstWhere('event_type', HistoryEventType::ActivityCancelled)->payload['activity_execution_id'],
        );

        $abandonedTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Activity->value)
            ->where('payload->activity_execution_id', $abandoned->id)
            ->sole();
        $this->assertSame(TaskStatus::Ready, $abandonedTask->status);

        // The abandoned activity keeps running, but nothing reaches the closed run.
        $claim = $activities->claimStatus($abandonedTask->id, 'independent-worker');
        $this->assertTrue($claim['claimed']);
        $attemptId = $claim['activity_attempt_id'];
        $heartbeat = $activities->heartbeat($attemptId, [
            'message' => 'still going',
        ]);
        $this->assertTrue($heartbeat['can_continue']);
        $outcome = $activities->complete($attemptId, Serializer::serialize('independent result'));
        $this->assertFalse($outcome['recorded']);
        $this->assertSame('run_closed', $outcome['reason']);
        $this->assertSame(ActivityStatus::Completed, $abandoned->refresh()->status);
        $this->assertSame($events->count(), $this->events($run->id)->count());
    }

    /**
     * @return \Illuminate\Support\Collection<int, WorkflowHistoryEvent>
     */
    private function events(string $runId): \Illuminate\Support\Collection
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->get()
            ->toBase();
    }

    private function runReadyTask(string $runId, TaskType $taskType): void
    {
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', $taskType->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $job = $taskType === TaskType::Activity
            ? new RunActivityTask($task->id)
            : new RunWorkflowTask($task->id);

        $this->app->call([$job, 'handle']);
    }

    private function newServiceRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'started_at' => now()
                ->subMinute(),
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()
                ->subMinute(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

    private function newLease(WorkflowRun $run): WorkflowTask
    {
        return WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Leased,
            'payload' => [],
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'service-worker',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);
    }
}
