<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Tests\Fixtures\V2\LeaseBlockingActivity;
use Tests\Fixtures\V2\LeaseBlockingWorkflow;
use Tests\Support\TestDatabaseServiceProvider;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class EmbeddedActivityLeaseMaintenanceTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'dw-embedded-lease-');
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->run();
        DB::statement('CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue TEXT NOT NULL, payload TEXT NOT NULL, attempts INTEGER NOT NULL,
            reserved_at INTEGER, available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
        DB::statement('CREATE TABLE IF NOT EXISTS failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL UNIQUE, connection TEXT NOT NULL, queue TEXT NOT NULL,
            payload TEXT NOT NULL, exception TEXT NOT NULL, failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        LeaseBlockingActivity::$invocations = 0;
        LeaseBlockingActivity::$callbackPid = null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->path . $suffix)) {
                unlink($this->path . $suffix);
            }
        }
    }

    public function testOrdinaryInlineActivityOutlastsItsLeaseAndCompletesExactlyOnce(): void
    {
        $stub = WorkflowStub::make(LeaseBlockingWorkflow::class, 'embedded-long-activity', 'lease-tenant');
        $stub->start(4.2);
        $this->workOnce();
        $task = WorkflowTask::query()->where('workflow_run_id', $stub->runId())
            ->where('task_type', 'activity')->sole();
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())
            ->orderBy('sequence')->get()->map->getAttributes()->all();
        $this->workOnce();

        self::assertSame(getmypid(), LeaseBlockingActivity::$callbackPid);
        self::assertSame(1, LeaseBlockingActivity::$invocations);
        self::assertSame(TaskStatus::Completed, $task->fresh()->status,
            'The original callback must publish once after exceeding its runtime lease.');
        $this->workOnce();
        self::assertSame('completed', $stub->refresh()->status());
        self::assertSame(['integer' => 9007199254740993, 'optional' => null, 'unicode' => 'λ'], $stub->output());
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())->orderBy('sequence')->get();
        self::assertSame($prefix, $events->take(count($prefix))->map->getAttributes()->all());
        self::assertSame(1, $events->where('event_type', HistoryEventType::ActivityStarted)->count());
        self::assertSame(1, $events->where('event_type', HistoryEventType::ActivityCompleted)->count());
        self::assertSame(0, $events->where('event_type', HistoryEventType::ActivityHeartbeatRecorded)->count());
        self::assertSame(0, WorkflowTask::query()->where('workflow_run_id', $stub->runId())->sum('repair_count'));
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, DB::table('failed_jobs')->count());
    }

    protected function getPackageProviders($app): array
    {
        return [TestDatabaseServiceProvider::class, WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'lease_sqlite');
        $app['config']->set('database.connections.lease_sqlite', [
            'driver' => 'sqlite', 'database' => $this->path, 'prefix' => '',
            'foreign_key_constraints' => true, 'busy_timeout' => 50, 'journal_mode' => 'WAL',
        ]);
        $app['config']->set('workflows.storage.connection', 'lease_sqlite');
        $app['config']->set('workflows.v1.enabled', false);
        $app['config']->set('workflows.v2.task_dispatch_mode', 'queue');
        $app['config']->set('workflows.v2.activity_task_lease_seconds', 3);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids', 'database' => 'lease_sqlite', 'table' => 'failed_jobs',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');
    }

    private function workOnce(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default',
            '--once' => true, '--tries' => 1, '--timeout' => 30]);
    }
}
