<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use RuntimeException;
use Tests\Fixtures\V2\TestLiveLoggingWorkflow;
use Tests\Fixtures\V2\TestLoggedSelectionWorkflow;
use Tests\Fixtures\V2\TestLoggedTwoHandleWorkflow;
use Tests\Fixtures\V2\TestReplayAwareLoggingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\WorkflowReplayState;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RuntimeObjectFactory;
use Workflow\V2\Support\WorkflowContinuationCache;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

final class V2ReplayAwareWorkflowLoggerTest extends TestCase
{
    private const LINES = [
        'logging workflow started',
        'logging workflow step one',
        'logging workflow side effect',
        'logging workflow step two',
        'logging workflow update applied',
        'logging workflow finishing',
    ];

    /**
     * @var list<array{message: string, context: array<string, mixed>}>
     */
    private array $logged = [];

    /**
     * @var list<array{message: string, context: array<string, mixed>}>
     */
    private array $allLogged = [];

    protected function setUp(): void
    {
        parent::setUp();

        TestReplayAwareLoggingWorkflow::$observedStates = [];
        $this->logged = [];

        $this->allLogged = [];

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->allLogged[] = [
                'message' => $event->message,
                'context' => $event->context,
            ];

            if (preg_match('/^(logging workflow|selection |two handles)/', $event->message) === 1) {
                $this->logged[] = [
                    'message' => $event->message,
                    'context' => $event->context,
                ];
            }
        });
    }

    public function testEachStepLogsOnceAcrossColdReplaysAndRestarts(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestReplayAwareLoggingWorkflow::class, 'replay-aware-logging');
        $workflow->start();
        $runId = $workflow->runId();

        // Every workflow task below cold-replays the run from its history.
        // Between tasks the worker's engine services are discarded, as they
        // are when a worker process restarts.
        $this->drainReadyTasks(restartBetweenTasks: true);
        $this->assertSame('waiting', $workflow->refresh()->status());

        $update = $workflow->attemptUpdate('note', 'checked');
        $this->assertTrue($update->completed());
        $this->drainReadyTasks(restartBetweenTasks: true);

        $workflow->signal('finish');
        $this->drainReadyTasks(restartBetweenTasks: true);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame(['Hello, first!', 'stamped', 'Hello, second!', ['checked']], $workflow->output());

        $this->assertSame(self::LINES, array_column($this->logged, 'message'));

        foreach ($this->logged as $line) {
            $this->assertSame('replay-aware-logging', $line['context']['workflow_instance_id']);
            $this->assertSame($runId, $line['context']['workflow_run_id']);
            $this->assertSame('test-replay-aware-logging-workflow', $line['context']['workflow_type']);
            $this->assertArrayHasKey('namespace', $line['context']);
        }

        $this->assertSame('Hello, first!', $this->logged[1]['context']['result']);
        $this->assertSame('checked', $this->logged[4]['context']['note']);

        // The code really was replayed: the opening line ran on every
        // workflow task but was observed as new only once.
        $starts = array_values(array_filter(
            TestReplayAwareLoggingWorkflow::$observedStates,
            static fn (string $state): bool => str_starts_with($state, 'start:'),
        ));
        $this->assertGreaterThanOrEqual(4, count($starts));
        $this->assertSame('start:executing', $starts[0]);
        $this->assertSame(array_fill(0, count($starts) - 1, 'start:replaying'), array_slice($starts, 1));
        $this->assertSame(['after-signal:executing'], array_values(array_filter(
            TestReplayAwareLoggingWorkflow::$observedStates,
            static fn (string $state): bool => str_starts_with($state, 'after-signal:'),
        )));

        // A cold replay of the finished run, and a query, write nothing.
        $logged = count($this->logged);
        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        $replayed = (new WorkflowReplayer())->replay($run);
        $this->assertSame(WorkflowReplayState::Replaying, $replayed->workflow->replayState());
        $this->assertTrue($replayed->workflow->isReplaying());

        $status = $workflow->query('status');
        $this->assertSame('replaying', $status['replay_state']);
        $this->assertTrue($status['replaying']);
        $this->assertCount($logged, $this->logged);
    }

    public function testReplayStateIsUnavailableOutsideAWorkflowTask(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestReplayAwareLoggingWorkflow::class, 'replay-aware-logging-outside');
        $workflow->start();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $instance = RuntimeObjectFactory::workflow(TestReplayAwareLoggingWorkflow::class, $run);

        $this->assertSame(WorkflowReplayState::Unavailable, $instance->replayState());
        $this->assertFalse($instance->isReplaying());

        $instance->logger()
            ->info('logging workflow outside');
        $this->assertSame([], $this->logged);

        $instance->beginReplayRegion(0);
        $this->assertSame(WorkflowReplayState::Executing, $instance->replayState());
        $instance->logger()
            ->warning('logging workflow fresh', [
                'key' => 'value',
            ]);
        $this->assertSame(['logging workflow fresh'], array_column($this->logged, 'message'));
        $this->assertSame('value', $this->logged[0]['context']['key']);

        $instance->beginReplayRegion(1);
        $this->assertSame(WorkflowReplayState::Replaying, $instance->replayState());
    }

    public function testLoggerFailuresDoNotFailTheWorkflow(): void
    {
        Queue::fake();

        Log::swap(new class() implements LoggerInterface {
            use LoggerTrait;

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new RuntimeException('log handler down');
            }
        });

        $workflow = WorkflowStub::make(TestReplayAwareLoggingWorkflow::class, 'replay-aware-logging-broken');
        $workflow->start();

        $this->drainReadyTasks();
        $this->assertTrue($workflow->attemptUpdate('note', 'still-works')->completed());
        $workflow->signal('finish');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame(['still-works'], $workflow->output()[3]);
    }

    public function testWorkerProcessesWriteEachStepOnce(): void
    {
        $logFile = storage_path('logs/laravel.log');
        $offset = is_file($logFile) ? (int) filesize($logFile) : 0;

        $workflow = WorkflowStub::make(TestReplayAwareLoggingWorkflow::class, 'replay-aware-logging-workers');
        $workflow->start('single');
        $runId = $workflow->runId();

        $this->waitForWorkflow(
            $workflow,
            static fn (): bool => WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', 'SignalWaitOpened')
                ->exists(),
            'the logging workflow to wait for its signal',
        );

        $this->assertTrue($workflow->attemptUpdate('note', 'from-test', 'single')->completed());
        $workflow->signal('finish');

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->refresh()
                ->completed(),
            'the logging workflow to complete',
        );

        clearstatcache();
        $contents = (string) file_get_contents($logFile, offset: $offset);
        $lines = array_values(array_filter(
            explode("\n", $contents),
            static fn (string $line): bool => str_contains($line, $runId),
        ));

        foreach (self::LINES as $message) {
            $this->assertCount(1, array_filter(
                $lines,
                static fn (string $line): bool => str_contains($line, $message . ' '),
            ), sprintf('[%s] must be written exactly once: %s', $message, implode("\n", $lines)));
        }
    }

    public function testCodeBeforeAwaitingALosingHandleLogsOnce(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestLoggedSelectionWorkflow::class, 'replay-aware-logging-selection');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->runReadyTaskOfType($runId, TaskType::Workflow);

        // The code after select() parked on the pending handle without
        // recording a step, so its line waits for the task that moves past it.
        $this->assertSame(['selection started'], array_column($this->logged, 'message'));

        $this->runReadyTaskOfType($runId, TaskType::Activity);
        $this->runReadyTaskOfType($runId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame(
            ['selection started', 'selection awaiting work', 'selection finished'],
            array_column($this->logged, 'message'),
        );
        $this->assertSame('deadline', $this->logged[1]['context']['winner']);
        $this->assertSame('Hello, Taylor!', $this->logged[2]['context']['work']);
    }

    public function testAResolvedHandleBeforeAParkedOneLogsEachLineOnce(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestLoggedTwoHandleWorkflow::class, 'replay-aware-logging-two-handles');
        $workflow->start();
        $runId = $workflow->runId();

        $this->runReadyTaskOfType($runId, TaskType::Workflow);
        $this->runReadyTaskOfType($runId, TaskType::Activity);
        // The first handle resolves and the second parks in the same task.
        $this->runReadyTaskOfType($runId, TaskType::Workflow);
        $this->assertSame(['two handles started'], array_column($this->logged, 'message'));

        $this->runReadyTaskOfType($runId, TaskType::Activity);
        $this->runReadyTaskOfType($runId, TaskType::Workflow);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame(
            ['two handles started', 'two handles awaiting', 'two handles first done', 'two handles second done'],
            array_column($this->logged, 'message'),
        );
    }

    public function testRolledBackTaskWritesNothing(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestReplayAwareLoggingWorkflow::class, 'replay-aware-logging-rollback');
        $workflow->start();
        $instance = RuntimeObjectFactory::workflow(
            TestReplayAwareLoggingWorkflow::class,
            WorkflowRun::query()->findOrFail($workflow->runId()),
        );
        $instance->beginReplayRegion(0);

        try {
            \Workflow\V2\Support\ConfiguredV2Models::connection()->transaction(static function () use (
                $instance
            ): void {
                $instance->logger()
                    ->info('logging workflow rolled back');

                throw new RuntimeException('task failed before commit');
            });
        } catch (RuntimeException) {
        }

        \Workflow\V2\Support\ConfiguredV2Models::connection()->transaction(static function () use ($instance): void {
            $instance->logger()
                ->info('logging workflow committed');
        });

        $this->assertSame(['logging workflow committed'], array_column($this->logged, 'message'));
    }

    public function testLiveContinuationFallbackDoesNotRepeatLines(): void
    {
        self::stopWorkers();
        $previousQueue = Queue::getFacadeRoot();
        $keys = ['queue.default', 'queue.connections.database', 'workflows.v2.queue',
            'workflows.v2.task_dispatch_mode', 'workflows.v2.live_execution'];
        $previous = array_combine($keys, array_map(static fn (string $key): mixed => config($key), $keys));
        config([
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'table' => 'jobs',
                'queue' => 'logger-live',
                'retry_after' => 90,
            ],
            'workflows.v2.queue' => 'logger-live',
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.v2.live_execution' => [
                'workflows' => ['test-live-logging-workflow'],
                'build' => 'logger-live',
            ],
        ]);
        $this->app->forgetInstance('queue');
        Queue::swap($this->app->make('queue'));

        try {
            $workflow = WorkflowStub::make(TestLiveLoggingWorkflow::class, 'replay-aware-logging-live');
            $workflow->start();
            $worker = $this->app->make('queue.worker');

            for ($attempt = 0; $attempt < 10 && ! $workflow->refresh()->completed(); $attempt++) {
                $worker->runNextJob('database', 'logger-live', new WorkerOptions(sleep: 0));
            }

            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame(['Hello, live!', 'stamped'], $workflow->output());
            // The task really resumed warm and fell back to a cold rerun.
            $counts = app(WorkflowContinuationCache::class)->snapshot()['counts'];
            $this->assertGreaterThanOrEqual(1, $counts['cold_unsupported_operation'] ?? 0, json_encode($counts));
            $this->assertSame(
                ['live started', 'live after activity', 'live after side effect'],
                array_column($this->liveLines(), 'message'),
            );
        } finally {
            Queue::swap($previousQueue);
            config($previous);
            DB::table('jobs')->where('queue', 'logger-live')->delete();
        }
    }

    /**
     * @return list<array{message: string, context: array<string, mixed>}>
     */
    private function liveLines(): array
    {
        return array_values(array_filter(
            $this->allLogged,
            static fn (array $line): bool => str_starts_with($line['message'], 'live '),
        ));
    }

    private function runReadyTaskOfType(string $runId, TaskType $type): void
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', $type->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();

        $job = match ($type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };

        $this->app->call([$job, 'handle']);
    }

    private function drainReadyTasks(bool $restartBetweenTasks = false): void
    {
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->orderBy('created_at')
                ->first();

            if ($task === null) {
                return;
            }

            if ($restartBetweenTasks) {
                $this->app->forgetInstance(WorkflowExecutor::class);
                $this->app->forgetInstance(WorkflowContinuationCache::class);
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
}
