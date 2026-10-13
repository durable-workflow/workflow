<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\V2\TestAuthoredRetryReplayWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\ActivityRetryPolicyMismatchException;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\WorkflowStub;

final class V2AuthoredActivityRetryReplayTest extends TestCase
{
    protected function tearDown(): void
    {
        TestAuthoredRetryReplayWorkflow::$overrides = null;
        parent::tearDown();
    }

    #[DataProvider('modes')]
    public function testRefusalPreservesOriginalAndExactRestorationRepairsIt(string $mode): void
    {
        self::stopWorkers();
        config()
            ->set('queue.default', 'database');
        Queue::fake();
        $stub = WorkflowStub::make(TestAuthoredRetryReplayWorkflow::class, 'retry-identity-' . $mode);
        $stub->start(120, $mode);
        $this->runReady($stub->runId(), TaskType::Workflow);
        $this->runReady($stub->runId(), TaskType::Activity);
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $activity = ActivityExecution::query()->where('workflow_run_id', $run->id)->firstOrFail();
        $policy = $activity->retry_policy;
        $deadlines = [
            $activity->schedule_deadline_at?->format('Y-m-d H:i:s.u'),
            $activity->schedule_to_close_deadline_at?->format('Y-m-d H:i:s.u'),
        ];
        self::assertNotNull($deadlines[1]);
        $prefix = $run->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->toArray();
        $resume = WorkflowTask::query()->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)->where('status', TaskStatus::Ready->value)->firstOrFail();

        TestAuthoredRetryReplayWorkflow::$overrides = new ActivityOptions(
            startToCloseTimeout: 120,
            scheduleToCloseTimeout: 600,
            maxAttempts: 1,
            backoff: [1, 2, 5, 10, 15, 30, 60, 121],
        );
        foreach (['query', 'fiber'] as $consumer) {
            try {
                if ($consumer === 'query') {
                    (new QueryStateReplayer())->replayState($run->fresh());
                } else {
                    WorkflowFiberRunner::forClass(
                        TestAuthoredRetryReplayWorkflow::class,
                        $run->workflow_instance_id,
                        $run->id,
                        [120, $mode],
                        'avro',
                        HistoryExport::forRun($run->fresh())['history_events']
                    )->step();
                }
                self::fail($consumer . ' must reject the changed list before consuming the result.');
            } catch (ActivityRetryPolicyMismatchException $exception) {
                self::assertSame(1, $exception->workflowSequence);
                self::assertSame('backoff_seconds', $exception->policyField);
            }
        }
        $this->assertColdReplay($run->id, $mode, true);
        $this->runReady($run->id, TaskType::Workflow);
        self::assertFalse($run->fresh()->status->isTerminal());
        self::assertNull($run->fresh()->closed_at);
        self::assertSame($prefix, $run->historyEvents()->orderBy('sequence')->get()->toArray());
        self::assertSame(TaskStatus::Failed, $resume->fresh()->status);
        self::assertSame('activity_retry_policy_mismatch', $resume->fresh()->payload['replay_blocked_reason']);
        self::assertSame($policy, $activity->fresh()->retry_policy);
        self::assertSame(
            $deadlines,
            [
                $activity->fresh()
                    ->schedule_deadline_at?->format('Y-m-d H:i:s.u'),
                $activity->fresh()
                    ->schedule_to_close_deadline_at?->format('Y-m-d H:i:s.u'),
            ]
        );
        self::assertSame(1, $activity->fresh()->attempt_count);

        TestAuthoredRetryReplayWorkflow::$overrides = null;
        $this->assertColdReplay($run->id, $mode, false);
        self::assertTrue(WorkflowStub::loadRun($run->id)->attemptRepair()->accepted());
        $this->runReady($run->id, TaskType::Workflow);
        self::assertTrue($run->fresh()->status->isTerminal());
        self::assertTrue($stub->refresh()->completed());
        self::assertSame($resume->id, $resume->fresh()->id);
        self::assertSame(2, $resume->fresh()->attempt_count);
        self::assertSame(1, $resume->fresh()->repair_count);
        self::assertSame($policy, $activity->fresh()->retry_policy);
        self::assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityScheduled->value)->count()
        );
        self::assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCompleted->value)->count()
        );
        self::assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::WorkflowCompleted->value)->count()
        );
        self::assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::WorkflowFailed->value)->count()
        );
        self::assertSame(
            $prefix,
            array_slice($run->historyEvents()->orderBy('sequence')->get()->toArray(), 0, count($prefix))
        );
    }

    public static function modes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'all' => ['all'];
        yield 'selection' => ['select'];
    }

    private function runReady(string $runId, TaskType $type): void
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $runId)->where('task_type', $type->value)
            ->where('status', TaskStatus::Ready->value)->firstOrFail();
        $job = $type === TaskType::Activity ? new RunActivityTask($task->id) : new RunWorkflowTask($task->id);
        $this->app->call([$job, 'handle']);
    }

    private function assertColdReplay(string $runId, string $mode, bool $changed): void
    {
        $connection = (string) config('database.default');
        $database = config("database.connections.{$connection}");
        $process = new Process([PHP_BINARY, __DIR__ . '/../../Fixtures/V2/authored_retry_cold_replay.php'], env: [
            'RETRY_REPLAY_DB_CONFIG' => json_encode($database, JSON_THROW_ON_ERROR),
            'RETRY_REPLAY_RUN_ID' => $runId,
            'RETRY_REPLAY_MODE' => $mode,
            'RETRY_REPLAY_CHANGED' => $changed ? '1' : '0',
        ]);
        $process->setTimeout(30);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if ($changed) {
            self::assertSame([
                'rejected' => true,
                'sequence' => 1,
                'field' => 'backoff_seconds',
            ], $result);
        } else {
            self::assertSame(false, $result['rejected']);
            self::assertSame(true, $result['completed']);
            self::assertSame($mode === 'all' ? ['Hello, Ada!'] : 'Hello, Ada!', $result['result']);
        }
    }
}
