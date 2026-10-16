<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class DatabaseQueueRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16T06:00:00.123456Z'));
        $this->artisan('migrate', [
            '--force' => true,
        ])->run();
        DB::statement('CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue TEXT NOT NULL,
            payload TEXT NOT NULL, attempts INTEGER NOT NULL, reserved_at INTEGER,
            available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
        DB::statement('CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL UNIQUE,
            connection TEXT NOT NULL, queue TEXT NOT NULL, payload TEXT NOT NULL, exception TEXT NOT NULL,
            failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function deliveries(): array
    {
        $cases = [];
        foreach (['workflow', 'activity', 'timer'] as $type) {
            foreach (['queued', 'missing', 'reserved'] as $delivery) {
                $cases[$type . ' ' . $delivery] = [$type, $delivery];
            }
        }
        return $cases;
    }

    #[DataProvider('deliveries')]
    public function testRecoveryDistinguishesQueuedWorkFromMissingOrReservedTransport(
        string $type,
        string $delivery
    ): void {
        $stub = WorkflowStub::make(
            $type === 'timer' ? TestTimerWorkflow::class : TestGreetingWorkflow::class,
            'queue-recovery-' . $type,
            '0',
        );
        $stub->start($type === 'timer' ? 1 : 'queue-user', new StartOptions(
            executionTimeoutSeconds: 120,
            runTimeoutSeconds: 100,
        ));
        if ($type !== 'workflow') {
            self::assertSame(0, Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => 'default',
                '--once' => true,
                '--tries' => 1,
            ]));
        }
        $task = WorkflowTask::query()->where('task_type', $type)->sole();
        $taskId = $task->id;
        self::assertSame('ready', $task->status->value);
        self::assertSame(1, DB::table('jobs')->count());
        $originalJob = DB::table('jobs')->sole();
        if ($delivery === 'missing') {
            DB::table('jobs')->where('id', $originalJob->id)->delete();
        } elseif ($delivery === 'reserved') {
            DB::table('jobs')->where('id', $originalJob->id)->update([
                'reserved_at' => now()
->timestamp,
                'attempts' => 1,
            ]);
        }
        $before = $task->getRawOriginal();
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $timing = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
        $history = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all();
        Carbon::setTestNow(now()->addSeconds(4));

        $report = TaskWatchdog::runPass('database', 'default', runIds: [$run->id]);

        $task->refresh();
        self::assertSame($delivery === 'queued' ? 0 : 1, $report['dispatched_tasks']);
        self::assertSame($delivery === 'reserved' ? 2 : 1, DB::table('jobs')->count());
        self::assertSame($delivery === 'queued' ? 0 : 1, $task->repair_count);
        self::assertSame($taskId, $task->id);
        self::assertSame('ready', $task->status->value);
        self::assertSame(0, (int) $task->attempt_count);
        self::assertNull($task->lease_owner);
        self::assertNull($task->lease_expires_at);
        if ($delivery === 'queued') {
            self::assertSame($before, $task->getRawOriginal());
            self::assertSame((array) $originalJob, (array) DB::table('jobs')->sole());
        }
        self::assertSame($history, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all());
        $run->refresh();
        self::assertSame(
            $timing,
            [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')]
        );
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'queue-recovery',
            'database.connections.queue-recovery' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'workflows.storage.connection' => 'queue-recovery',
            'workflows.v1.enabled' => false,
            'workflows.v2.compatibility.namespace' => '0',
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.v2.matching_role.queue_wake_enabled' => false,
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'connection' => 'queue-recovery',
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 90,
            ],
            'queue.failed' => [
                'driver' => 'database-uuids',
                'database' => 'queue-recovery',
                'table' => 'failed_jobs',
            ],
            'cache.default' => 'array',
            'logging.default' => 'null',
        ]);
    }
}
