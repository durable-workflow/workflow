<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Activity;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\LocalActivityOptions;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class LocalActivityRetryDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', [
            '--force' => true,
        ])->run();
        DB::statement('CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue TEXT NOT NULL,
            payload TEXT NOT NULL, attempts INTEGER NOT NULL, reserved_at INTEGER,
            available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
        DB::statement('CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL UNIQUE,
            connection TEXT NOT NULL, queue TEXT NOT NULL, payload TEXT NOT NULL, exception TEXT NOT NULL,
            failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        QueueRetryActivity::$calls = 0;
    }

    public static function modes(): array
    {
        return [
            'ordinary success' => [false, 0, 2],
            'immediate retry' => [true, 0, 2],
            'delayed retry' => [true, 1, 2],
            'exhausted policy' => [true, 0, 1],
        ];
    }

    #[DataProvider('modes')]
    public function testQueueWorkersExecuteTheOriginalRetryWithoutRepair(
        bool $retry,
        int $backoff,
        int $maxAttempts,
    ): void {
        $stub = $this->start($retry, $backoff, $maxAttempts);
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $deadlines = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
        $this->workOnce();
        $retries = $retry && $maxAttempts > 1;
        if ($retries) {
            $event = WorkflowHistoryEvent::query()->where('event_type', 'ActivityRetryScheduled')->sole();
            $task = WorkflowTask::query()->where('status', 'ready')->sole();
            self::assertSame($event->payload['retry_task_id'], $task->id);
            self::assertSame($event->payload['retry_available_at'], $task->available_at->toISOString());
            self::assertSame(0, $task->attempt_count);
            self::assertNotNull($task->last_dispatched_at);
            self::assertSame(1, DB::table('jobs')->count());
            if ($backoff > 0) {
                $transportAt = (int) DB::table('jobs')->sole()->available_at;
                self::assertSame($task->available_at->copy()->ceilSecond()->getTimestamp(), $transportAt);
                $remainingMicroseconds = (int) ceil(($transportAt - microtime(true)) * 1_000_000) + 50_000;
                if ($remainingMicroseconds > 0) {
                    usleep($remainingMicroseconds);
                }
            }
            $this->workOnce();
        }
        $failed = $retry && $maxAttempts === 1;
        self::assertSame($failed ? 'failed' : 'completed', $run->fresh()->status->value);
        if (! $failed) {
            self::assertSame([9007199254740993, null, '雪'], $stub->refresh()->output());
        }
        self::assertSame($deadlines, [
            $run->fresh()
                ->getRawOriginal('execution_deadline_at'), $run->fresh()
                ->getRawOriginal('run_deadline_at'),
        ]);
        self::assertSame($retries ? 2 : 1, ActivityAttempt::query()->count());
        self::assertSame($retries ? 2 : 1, QueueRetryActivity::$calls);
        self::assertSame(1, ActivityExecution::query()->count());
        self::assertSame($retries ? 1 : 0, WorkflowHistoryEvent::query()
            ->where('event_type', 'ActivityRetryScheduled')
            ->count());
        self::assertSame($failed ? 0 : 1, WorkflowHistoryEvent::query()
            ->where('event_type', 'ActivityCompleted')
            ->count());
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, DB::table('failed_jobs')->count());
        self::assertSame(0, WorkflowTask::query()->where('task_type', 'activity')->count());
        self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
    }

    public function testPollModeDoesNotPublishLaravelRetryJobs(): void
    {
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        $this->start();
        $task = WorkflowTask::query()->sole();
        app()
            ->call([new RunWorkflowTask($task->id), 'handle']);
        self::assertSame(1, ActivityAttempt::query()->count());
        self::assertSame(1, WorkflowTask::query()->where('status', 'ready')->count());
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
    }

    public function testRolledBackRetryDoesNotPublishBeforeTheRootCommit(): void
    {
        $stub = $this->start();
        $task = WorkflowTask::query()->sole();
        $originalJobs = DB::table('jobs')->get()->toArray();
        DB::beginTransaction();
        try {
            app()->call([new RunWorkflowTask($task->id), 'handle']);
            self::assertSame(1, WorkflowHistoryEvent::query()->where('event_type', 'ActivityRetryScheduled')->count());
            self::assertEquals($originalJobs, DB::table('jobs')->get()->toArray());
        } finally {
            DB::rollBack();
        }
        self::assertEquals($originalJobs, DB::table('jobs')->get()->toArray());
        self::assertSame(0, ActivityAttempt::query()->count());
        self::assertSame(1, WorkflowTask::query()->count());
        self::assertSame(2, WorkflowHistoryEvent::query()->count());
        $this->workOnce();
        self::assertSame(1, DB::table('jobs')->count());
        $this->workOnce();
        self::assertSame([9007199254740993, null, '雪'], $stub->refresh()->output());
        self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('workflows.storage.connection', 'testing');
        $app['config']->set('workflows.v1.enabled', false);
        $app['config']->set('workflows.v2.task_dispatch_mode', 'queue');
        $app['config']->set('workflows.v2.compatibility.namespace', '0');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'testing',
            'table' => 'failed_jobs',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'stderr');
    }

    private function start(bool $retry = true, int $backoff = 0, int $maxAttempts = 2): WorkflowStub
    {
        $stub = WorkflowStub::make(QueueRetryWorkflow::class, 'local-queue-retry', '0');
        $stub->start(
            $retry,
            $backoff,
            $maxAttempts,
            new StartOptions(executionTimeoutSeconds: 30, runTimeoutSeconds: 20)
        );

        return $stub;
    }

    private function workOnce(): void
    {
        self::assertSame(0, Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--once' => true,
            '--tries' => 1,
            '--timeout' => 30,
        ]));
    }
}

final class QueueRetryWorkflow extends Workflow
{
    public function handle(bool $retry, int $backoff, int $maxAttempts): array
    {
        return self::localActivity(
            QueueRetryActivity::class,
            new LocalActivityOptions(maxAttempts: $maxAttempts, backoff: $backoff),
            $retry,
        );
    }
}

final class QueueRetryActivity extends Activity
{
    public static int $calls = 0;

    public function handle(bool $retry): array
    {
        ++self::$calls;
        if ($retry && $this->attemptCount() === 1) {
            throw new RuntimeException('One original callback failure.');
        }

        return [9007199254740993, null, '雪'];
    }
}
