<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabaseServiceProvider;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

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
            'ordinary cold jobs' => [false, 3, 2],
            'retained jobs' => [true, 1, 1],
        ];
    }

    #[DataProvider('profiles')]
    public function testActualDatabaseJobsKeepCommittedEffectsAndOriginalDeadlines(
        bool $enabled,
        int $entries,
        int $afterActivity,
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
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all();
        $this->workOnce(); // actual activity callback
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

    private function workOnce(): void
    {
        $this->assertSame(0, Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--once' => true,
            '--tries' => 1,
            '--timeout' => 30,
        ]), Artisan::output());
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
