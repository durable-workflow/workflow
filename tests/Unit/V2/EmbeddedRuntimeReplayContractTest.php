<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestNestedParallelActivityWorkflow;
use Tests\Fixtures\V2\TestQueryContinueAsNewWorkflow;
use Tests\Fixtures\V2\TestRetryActivity;
use Tests\Fixtures\V2\TestRetryWorkflow;
use Tests\Fixtures\V2\TestSearchAttributeWorkflow;
use Tests\Fixtures\V2\TestSelectionWorkflow;
use Tests\Fixtures\V2\TestSideEffectWorkflow;
use Tests\TestCase;
use Workflow\V2\Activity;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use function Workflow\V2\localActivity;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\LocalActivityOptions;
use Workflow\V2\Support\OperatorDashboardSummary;
use Workflow\V2\Support\OperatorMetrics;
use Workflow\V2\Support\OperatorQueueVisibility;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class EmbeddedRuntimeReplayContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');
        config()
            ->set('workflows.v2.namespace', 'unit-runtime');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TestSideEffectWorkflow::resetCounter();
        RetryingLocalActivity::reset();

        parent::tearDown();
    }

    public function testNestedParallelActivitiesCompleteAndColdReplayTheDurableBarrier(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestNestedParallelActivityWorkflow::class, 'unit-nested-replay');
        $workflow->start('Ada', 'Grace', 'Linus');

        $openRun = WorkflowRun::query()->findOrFail($workflow->runId());
        $openRun->forceFill([
            'namespace' => 'unit-runtime',
        ])->save();
        $openRun->instance()
            ->update([
                'namespace' => 'unit-runtime',
            ]);
        WorkflowTask::query()
            ->where('workflow_run_id', $openRun->id)
            ->update([
                'namespace' => 'unit-runtime',
            ]);
        RunSummaryProjector::project($openRun->fresh());
        $openDetail = RunDetailView::forRun($openRun);
        $openQueue = OperatorQueueVisibility::forQueue('unit-runtime', 'default', [[
            'worker_id' => 'unit-worker-active',
            'runtime' => 'php',
            'build_id' => 'unit-build',
            'last_heartbeat_at' => now()
                ->subSecond(),
            'heartbeat_expires_at' => now()
                ->addMinute(),
            'supported_workflow_types' => ['test-nested-parallel-activity-workflow'],
            'max_concurrent_workflow_tasks' => 2,
        ], [
            'worker_id' => 'unit-worker-stale',
            'runtime' => 'php',
            'heartbeat_expires_at' => now()
                ->subSecond(),
        ]])->toArray();
        $openMetrics = OperatorMetrics::snapshot(now(), 'unit-runtime');

        $this->assertFalse($openDetail['is_terminal']);
        $this->assertTrue($openDetail['can_cancel']);
        $this->assertSame(1, $openQueue['stats']['approximate_backlog_count']);
        $this->assertSame(['active', 'stale'], collect($openQueue['pollers'])->pluck('status')->all());
        $this->assertSame(1, $openMetrics['runs']['running']);
        $this->assertGreaterThanOrEqual(1, $openMetrics['tasks']['ready']);

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'stage' => 'completed',
            'workflow_id' => 'unit-nested-replay',
            'run_id' => $workflow->runId(),
            'results' => ['Hello, Ada!', ['Hello, Grace!', 'Hello, Linus!']],
        ], $workflow->output());

        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $replayed = (new QueryStateReplayer())->replayState($run);
        $closedDetail = RunDetailView::forRun($run->fresh());
        $dashboard = OperatorDashboardSummary::snapshot(now(), 'unit-runtime');

        $this->assertNull($replayed->current);
        $this->assertSame([
            'stage' => 'completed',
        ], $replayed->workflow->currentState());
        $this->assertTrue($closedDetail['is_terminal']);
        $this->assertFalse($closedDetail['can_cancel']);
        $this->assertSame(1, $dashboard['flows']);
        $this->assertSame(1, $dashboard['operator_metrics']['runs']['completed']);
        $this->assertSame(
            3,
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ActivityCompleted->value)
                ->count(),
        );
    }

    public function testSelectionCommitsOneWinnerAndReplayUsesThePersistedResolution(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestSelectionWorkflow::class, 'unit-selection-replay');
        $workflow->start('Ada', 300);
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('work', $workflow->output()['key']);
        $this->assertSame('activity', $workflow->output()['kind']);
        $this->assertSame('Hello, Ada!', $workflow->output()['result']);
        $this->assertSame(['deadline'], $workflow->output()['remaining']);

        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $resolution = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SelectionResolved->value)
            ->sole();
        $before = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count();

        $this->assertSame('work', $resolution->payload['member_key'] ?? null);
        $this->assertSame([
            'stage' => 'selected-work',
        ], (new QueryStateReplayer())->query($run, 'currentState'));
        $this->assertSame($before, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());
    }

    public function testSideEffectsRemainSingleExecutionAcrossWaitingAndCompletedQueries(): void
    {
        Queue::fake();
        TestSideEffectWorkflow::resetCounter();

        $workflow = WorkflowStub::make(TestSideEffectWorkflow::class, 'unit-side-effect-replay');
        $workflow->start();
        $this->drainReadyTasks();

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame(1, $workflow->currentToken());
        $this->assertSame('waiting-for-finish', $workflow->currentStage());
        $this->assertSame(1, TestSideEffectWorkflow::sideEffectExecutions());

        $workflow->signal('finish', 'done');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame(1, $workflow->currentToken());
        $this->assertSame(1, TestSideEffectWorkflow::sideEffectExecutions());
        $this->assertSame('done', $workflow->output()['finish']);
    }

    public function testSearchAttributeUpsertsAndContinueAsNewRemainReplayable(): void
    {
        Queue::fake();

        $search = WorkflowStub::make(TestSearchAttributeWorkflow::class, 'unit-search-replay');
        $search->start('Ada');
        $this->drainReadyTasks();

        $this->assertTrue($search->refresh()->completed());
        $this->assertSame([
            'customer' => 'Ada',
            'result' => 'success',
            'status' => 'completed',
        ], $search->searchAttributes());
        $this->assertNull(
            (new QueryStateReplayer())
                ->replayState(WorkflowRun::query()->findOrFail($search->runId()))
                ->current,
        );

        $continued = WorkflowStub::make(TestQueryContinueAsNewWorkflow::class, 'unit-continue-replay');
        $continued->start(0, 2);
        $this->drainReadyTasks();

        $this->assertTrue($continued->refresh()->completed());
        $this->assertSame(2, $continued->output()['count']);
        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'unit-continue-replay')
            ->orderBy('run_number')
            ->get();

        $this->assertCount(3, $runs);
        $this->assertSame([1, 2, 2], $runs
            ->map(static fn (WorkflowRun $run): int => (new QueryStateReplayer())->query($run, 'currentCount'))
            ->all());
    }

    public function testLocalActivityRetryHistoryReplaysWithoutReexecutingTheActivity(): void
    {
        Queue::fake();
        RetryingLocalActivity::reset();

        $workflow = WorkflowStub::make(RetryingLocalActivityWorkflow::class, 'unit-local-retry');
        $workflow->start('Ada');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'message' => 'Hello, Ada!',
            'attempts' => 2,
        ], $workflow->output());
        $this->assertSame(2, RetryingLocalActivity::executions());

        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get();

        $this->assertSame(1, $events->where('event_type', HistoryEventType::ActivityRetryScheduled)->count());
        $this->assertSame(1, $events->where('event_type', HistoryEventType::ActivityCompleted)->count());
        $this->assertNull((new QueryStateReplayer())->replayState($run)->current);
        $this->assertSame(2, RetryingLocalActivity::executions());
        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Activity->value)
            ->count());
    }

    public function testExternalActivityWorkerCanPollHeartbeatAndCompleteAWorkflow(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'unit-external-complete');
        $workflow->start('Ada');
        $this->runNextReadyTask(TaskType::Workflow);

        /** @var ActivityTaskBridge $bridge */
        $bridge = $this->app->make(ActivityTaskBridge::class);
        $tasks = $bridge->poll(null, null, 10);

        $this->assertCount(1, $tasks);
        $this->assertSame(TestGreetingActivity::class, $tasks[0]['activity_class']);

        $claim = $bridge->claimStatus($tasks[0]['task_id'], 'unit-external-worker');

        $this->assertTrue($claim['claimed']);
        $this->assertSame(1, $claim['attempt_number']);
        $this->assertSame('unit-external-worker', $claim['lease_owner']);
        $this->assertSame(TestGreetingActivity::class, $claim['activity_class']);
        $this->assertIsArray($claim['arguments_envelope']);
        $this->assertNull($bridge->claim($tasks[0]['task_id'], 'unit-competing-worker'));

        $status = $bridge->status($claim['activity_attempt_id']);
        $heartbeat = $bridge->heartbeat($claim['activity_attempt_id'], [
            'message' => 'greeting',
            'current' => 1,
            'total' => 1,
        ]);

        $this->assertTrue($status['can_continue']);
        $this->assertFalse($status['heartbeat_recorded']);
        $this->assertTrue($heartbeat['can_continue']);
        $this->assertTrue($heartbeat['heartbeat_recorded']);
        $this->assertNotNull($heartbeat['last_heartbeat_at']);

        $completion = $bridge->complete($claim['activity_attempt_id'], 'Hello, Ada!');

        $this->assertTrue($completion['recorded']);
        $this->assertNotNull($completion['next_task_id']);
        $this->assertFalse($bridge->complete($claim['activity_attempt_id'], 'late duplicate')['recorded']);

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('Hello, Ada!', $workflow->output()['greeting']);
        $this->assertSame('attempt_closed', $bridge->status($claim['activity_attempt_id'])['reason']);
        $this->assertSame('attempt_not_found', $bridge->status('missing-attempt')['reason']);
        $this->assertSame(
            1,
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $workflow->runId())
                ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded->value)
                ->count(),
        );
    }

    public function testExternalActivityFailureSchedulesAndCompletesTheNextAttempt(): void
    {
        Queue::fake();
        Carbon::setTestNow(now()->startOfSecond());

        $workflow = WorkflowStub::make(TestRetryWorkflow::class, 'unit-external-retry');
        $workflow->start('Grace');
        $this->runNextReadyTask(TaskType::Workflow);

        /** @var ActivityTaskBridge $bridge */
        $bridge = $this->app->make(ActivityTaskBridge::class);
        $firstTask = $bridge->poll(null, null, 1)[0];
        $firstClaim = $bridge->claimStatus($firstTask['task_id'], 'unit-external-worker');

        $this->assertTrue($firstClaim['claimed']);
        $this->assertSame(TestRetryActivity::class, $firstClaim['activity_class']);
        $this->assertSame(2, $firstClaim['retry_policy']['max_attempts']);

        $failure = $bridge->fail($firstClaim['activity_attempt_id'], [
            'class' => RuntimeException::class,
            'message' => 'remote activity failed',
            'code' => 17,
        ]);

        $this->assertTrue($failure['recorded']);
        $this->assertNotNull($failure['next_task_id']);
        $this->assertSame([], $bridge->poll(null, null, 1));

        Carbon::setTestNow(now()->addSeconds(5));

        $retryTasks = $bridge->poll(null, null, 1);
        $this->assertCount(1, $retryTasks);
        $this->assertSame($failure['next_task_id'], $retryTasks[0]['task_id']);

        $retryClaim = $bridge->claimStatus($retryTasks[0]['task_id'], 'unit-external-worker');

        $this->assertTrue($retryClaim['claimed']);
        $this->assertSame(2, $retryClaim['attempt_number']);

        $completion = $bridge->complete($retryClaim['activity_attempt_id'], [
            'message' => 'Hello, Grace!',
            'attempt_count' => 2,
        ]);

        $this->assertTrue($completion['recorded']);
        $this->assertNotNull($completion['next_task_id']);

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('Hello, Grace!', $workflow->output()['activity']['message']);
        $this->assertSame(2, $workflow->output()['activity']['attempt_count']);

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->get();

        $this->assertSame(1, $events->where('event_type', HistoryEventType::ActivityRetryScheduled)->count());
        $this->assertSame(1, $events->where('event_type', HistoryEventType::ActivityCompleted)->count());
    }

    public function testExternalWorkflowWorkerExecutesClaimedReplayTasksToCompletion(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'unit-external-workflow-worker');
        $workflow->start('Linus');

        /** @var WorkflowTaskBridge $workflowBridge */
        $workflowBridge = $this->app->make(WorkflowTaskBridge::class);
        $tasks = $workflowBridge->poll(null, null, 10);

        $this->assertCount(1, $tasks);
        $this->assertSame(TestGreetingWorkflow::class, $tasks[0]['workflow_class']);

        $claim = $workflowBridge->claimStatus($tasks[0]['task_id'], 'unit-replay-worker');
        $history = $workflowBridge->historyPayload($tasks[0]['task_id']);
        $firstPage = $workflowBridge->historyPayloadPaginated($tasks[0]['task_id'], 0, 1);

        $this->assertTrue($claim['claimed']);
        $this->assertSame('unit-replay-worker', $claim['lease_owner']);
        $this->assertNotNull($history);
        $this->assertSame('unit-external-workflow-worker', $history['workflow_instance_id']);
        $this->assertGreaterThan(1, $history['total_history_events']);
        $this->assertNotNull($firstPage);
        $this->assertCount(1, $firstPage['history_events']);
        $this->assertTrue($firstPage['has_more']);
        $this->assertSame(1, $firstPage['next_after_sequence']);

        $heartbeat = $workflowBridge->heartbeat($tasks[0]['task_id']);
        $firstExecution = $workflowBridge->execute($tasks[0]['task_id']);

        $this->assertTrue($heartbeat['renewed']);
        $this->assertTrue($firstExecution['executed']);
        $this->assertSame('waiting', $firstExecution['run_status']);

        /** @var ActivityTaskBridge $activityBridge */
        $activityBridge = $this->app->make(ActivityTaskBridge::class);
        $activityTask = $activityBridge->poll(null, null, 1)[0];
        $activityClaim = $activityBridge->claimStatus($activityTask['task_id'], 'unit-activity-worker');
        $activityCompletion = $activityBridge->complete($activityClaim['activity_attempt_id'], 'Hello, Linus!');

        $this->assertTrue($activityClaim['claimed']);
        $this->assertTrue($activityCompletion['recorded']);

        $resumeTasks = $workflowBridge->poll(null, null, 1);
        $this->assertCount(1, $resumeTasks);

        $resumeClaim = $workflowBridge->claim($resumeTasks[0]['task_id'], 'unit-replay-worker');
        $resumeExecution = $workflowBridge->execute($resumeTasks[0]['task_id']);

        $this->assertNotNull($resumeClaim);
        $this->assertTrue($resumeExecution['executed']);
        $this->assertSame('completed', $resumeExecution['run_status']);
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('Hello, Linus!', $workflow->output()['greeting']);
        $this->assertFalse($workflowBridge->heartbeat($resumeTasks[0]['task_id'])['renewed']);
        $this->assertFalse($workflowBridge->claimStatus('missing-workflow-task')['claimed']);
        $this->assertNull($workflowBridge->historyPayload('missing-workflow-task'));
    }

    private function runNextReadyTask(TaskType $type): void
    {
        $task = WorkflowTask::query()
            ->where('task_type', $type->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->firstOrFail();

        $job = match ($type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };

        $this->app->call([$job, 'handle']);
    }

    private function drainReadyTasks(): void
    {
        for ($executed = 0; $executed < 100; $executed++) {
            $cutoff = now()
                ->format('Y-m-d H:i:s.u');
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->where(static function ($query) use ($cutoff): void {
                    $query->whereNull('available_at')
                        ->orWhere('available_at', '<=', $cutoff);
                })
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();

            if (! $task instanceof WorkflowTask) {
                return;
            }

            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Embedded runtime contract exceeded its 100-task execution bound.');
    }
}

final class RetryingLocalActivityWorkflow extends Workflow
{
    /**
     * @return array{message: string, attempts: int}
     */
    public function handle(string $name): array
    {
        return localActivity(
            RetryingLocalActivity::class,
            new LocalActivityOptions(maxAttempts: 2, backoff: 0),
            $name,
        );
    }
}

final class RetryingLocalActivity extends Activity
{
    private static int $executions = 0;

    public static function reset(): void
    {
        self::$executions = 0;
    }

    public static function executions(): int
    {
        return self::$executions;
    }

    /**
     * @return array{message: string, attempts: int}
     */
    public function handle(string $name): array
    {
        self::$executions++;

        if (self::$executions === 1) {
            throw new RuntimeException('retry the local activity');
        }

        return [
            'message' => "Hello, {$name}!",
            'attempts' => self::$executions,
        ];
    }
}
