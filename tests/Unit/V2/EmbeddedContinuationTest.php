<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use Tests\Support\TestDatabaseServiceProvider;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\WorkflowContinuationCache;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EmbeddedContinuationTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'dw-continuation-');
        parent::setUp();
        Carbon::setTestNow('2026-10-17 15:15:00.123456');
        $this->artisan('migrate', [
            '--force' => true,
        ])->run();
        DB::statement('CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue TEXT NOT NULL, payload TEXT NOT NULL, attempts INTEGER NOT NULL,
            reserved_at INTEGER, available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
        DB::statement('CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL UNIQUE, connection TEXT NOT NULL, queue TEXT NOT NULL,
            payload TEXT NOT NULL, exception TEXT NOT NULL, failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        ContinuationWorkflow::$entries = 0;
        ContinuationWorkflow::$afterActivity = 0;
        ContinuationActivity::$calls = 0;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->path . $suffix)) {
                unlink($this->path . $suffix);
            }
        }
    }

    public static function profiles(): array
    {
        return [
            'ordinary cold jobs' => [false, 3, 2, 'none'],
            'retained jobs' => [true, 1, 1, 'none'],
            'lost process-local state' => [true, 2, 1, 'evict'],
            'new application build' => [true, 2, 1, 'build'],
            'duplicate completed delivery' => [true, 1, 1, 'duplicate'],
        ];
    }

    #[DataProvider('profiles')]
    public function testActualDatabaseJobsKeepCommittedEffectsAndOriginalDeadlines(
        bool $enabled,
        int $entries,
        int $afterActivity,
        string $change,
    ): void {
        config()->set('workflows.v2.live_execution', [
            'workflows' => $enabled ? [ContinuationWorkflow::class] : [],
            'build' => 'continuation-test-build',
        ]);
        $stub = WorkflowStub::make(ContinuationWorkflow::class, 'original', 'continuation-test');
        $stub->start(new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 90));
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $deadlines = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
        $this->workOnce(); // workflow parks on the activity
        if ($change === 'duplicate') {
            Queue::connection('database')->push(new RunWorkflowTask(
                WorkflowTask::query()->where('workflow_run_id', $run->id)->where('task_type', 'workflow')->sole()->id,
            ));
        }
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all();
        $this->workOnce(); // actual activity callback
        if ($change === 'duplicate') {
            $this->workOnce();
            $this->assertSame(1, ContinuationWorkflow::$entries);
        }
        if ($change === 'evict') {
            $this->app->make(WorkflowContinuationCache::class)->clear();
        } elseif ($change === 'build') {
            config()->set('workflows.v2.live_execution.build', 'new-build');
        }
        $this->workOnce(); // workflow resumes, then parks on its timer
        Carbon::setTestNow(now()->addSeconds(2));
        $this->workOnce(); // durable timer fires through the ordinary queue
        $this->workOnce(); // final workflow continuation
        $this->assertSame('completed', $stub->refresh()->status(), Artisan::output());
        $this->assertSame([
            'integer' => 9007199254740993,
            'optional' => null,
            'unicode' => 'λ',
        ], $stub->output());
        $this->assertSame(1, ContinuationActivity::$calls);
        $this->assertSame($entries, ContinuationWorkflow::$entries);
        $this->assertSame($afterActivity, ContinuationWorkflow::$afterActivity);
        $this->assertSame($deadlines, [
            $run->fresh()
                ->getRawOriginal('execution_deadline_at'),
            $run->fresh()
                ->getRawOriginal('run_deadline_at'),
        ]);
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')->get();
        $this->assertSame($prefix, $events->take(count($prefix))->map->getRawOriginal()->all());
        foreach ([HistoryEventType::ActivityScheduled, HistoryEventType::ActivityStarted,
            HistoryEventType::ActivityCompleted, HistoryEventType::TimerScheduled,
            HistoryEventType::TimerFired, HistoryEventType::WorkflowCompleted] as $type) {
            $this->assertCount(1, $events->where('event_type', $type));
        }
        $tasks = WorkflowTask::query()->where('workflow_run_id', $run->id)->get();
        $this->assertCount(5, $tasks);
        $this->assertSame(['completed'], $tasks->pluck('status')->map->value->unique()->values()->all());
        $this->assertSame([1], $tasks->pluck('attempt_count')->unique()->values()->all());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function testFreshLeaseOwnerIsCheckedBeforeAnyRetainedPrefixCanResume(): void
    {
        $this->enable();
        $stub = WorkflowStub::make(ContinuationWorkflow::class, 'lease-original', 'continuation-test');
        $stub->start();
        $this->workOnce();
        $this->workOnce();
        $task = WorkflowTask::query()->where('workflow_run_id', $stub->runId())
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->sole();
        $writer = new PDO('sqlite:' . $this->path);
        $admissions = 0;
        DB::connection()->beforeExecuting(static function (string $sql) use ($writer, $task, &$admissions): void {
            if (str_starts_with($sql, 'update "workflow_tasks" set "id" =') && ++$admissions === 2) {
                $statement = $writer->prepare('UPDATE workflow_tasks SET lease_owner = ? WHERE id = ?');
                $statement->execute(['replacement-owner', $task->id]);
            }
        });
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())
            ->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all();
        $this->workOnce();
        $this->assertSame(2, $admissions);
        $this->assertSame('replacement-owner', $task->fresh()->lease_owner);
        $this->assertSame('leased', $task->fresh()->status->value);
        $this->assertSame(1, ContinuationWorkflow::$entries);
        $this->assertSame(0, ContinuationWorkflow::$afterActivity);
        $this->assertSame($prefix, WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())
            ->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all());
    }

    public static function failures(): array
    {
        return [
            'root rollback' => ['rollback'],
            'lost post-commit acknowledgement' => ['ack'],
        ];
    }

    #[DataProvider('failures')]
    public function testAdvancedFrameIsDiscardedAfterTransactionFailure(string $phase): void
    {
        $this->enable();
        $stub = WorkflowStub::make(ContinuationWorkflow::class, 'failed-original', 'continuation-test');
        $stub->start(new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 90));
        $this->workOnce();
        $this->workOnce();
        $inject = true;
        WorkflowHistoryEvent::created(static function (WorkflowHistoryEvent $event) use (&$inject, $phase): void {
            if ($inject && $event->event_type === HistoryEventType::TimerScheduled) {
                $inject = false;
                $fault = static fn () => throw new RuntimeException('controlled-' . $phase);
                if ($phase === 'ack') {
                    DB::afterCommit($fault);
                } else {
                    $fault();
                }
            }
        });
        $this->workOnce();
        $this->assertFalse($inject);
        $this->assertSame(0, $this->app->make(WorkflowContinuationCache::class)->snapshot()['entries']);
        $this->assertSame(1, ContinuationWorkflow::$entries);
        $this->assertSame(1, ContinuationWorkflow::$afterActivity);
        $this->assertSame(1, ContinuationActivity::$calls);
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())->get();
        $this->assertCount($phase === 'ack' ? 1 : 0, $events->where('event_type', HistoryEventType::TimerScheduled));
        $this->assertCount(1, $events->where('event_type', HistoryEventType::ActivityCompleted));
        $this->assertCount(0, $events->where('event_type', HistoryEventType::WorkflowCompleted));
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    protected function getPackageProviders($app): array
    {
        return [TestDatabaseServiceProvider::class, WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'continuation');
        $app['config']->set('database.connections.continuation', [
            'driver' => 'sqlite',
            'database' => $this->path,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 1000,
        ]);
        $app['config']->set('workflows.storage.connection', 'continuation');
        $app['config']->set('workflows.v1.enabled', false);
        $app['config']->set('workflows.v2.task_dispatch_mode', 'queue');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'continuation',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'continuation',
            'table' => 'failed_jobs',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');
    }

    private function enable(): void
    {
        config()->set('workflows.v2.live_execution', [
            'workflows' => [ContinuationWorkflow::class],
            'build' => 'continuation-test-build',
        ]);
    }

    private function workOnce(): void
    {
        $this->app->make('queue.worker')
            ->runNextJob('database', 'default', new WorkerOptions(sleep: 0, timeout: 30));
        $this->assertSame(0, DB::transactionLevel());
    }
}

#[Type('continuation-workflow')]
final class ContinuationWorkflow extends Workflow
{
    public static int $entries = 0;

    public static int $afterActivity = 0;

    public function handle(): array
    {
        ++self::$entries;
        $value = Workflow::activity(ContinuationActivity::class);
        ++self::$afterActivity;
        Workflow::timer(1);

        return $value;
    }
}

#[Type('continuation-activity')]
final class ContinuationActivity extends Activity
{
    public static int $calls = 0;

    public function handle(): array
    {
        ++self::$calls;

        return [
            'integer' => 9007199254740993,
            'optional' => null,
            'unicode' => 'λ',
        ];
    }
}
