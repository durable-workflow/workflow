<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use RuntimeException;
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

    protected function setUp(): void
    {
        parent::setUp();

        TestReplayAwareLoggingWorkflow::$observedStates = [];
        $this->logged = [];

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            if (str_starts_with($event->message, 'logging workflow')) {
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
