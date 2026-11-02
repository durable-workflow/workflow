<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\SqliteContentionActivity;
use Tests\Fixtures\V2\SqliteContentionWorkflow;
use Tests\Support\TestDatabaseServiceProvider;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\WorkflowStub;

final class ProjectionSqliteContentionTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'dw-projection-contention-');
        parent::setUp();
        $this->artisan('migrate', [
            '--force' => true,
        ])->run();
        $this->queueTables(DB::connection());
        DB::statement('CREATE TABLE projection_writer_probe (id INTEGER PRIMARY KEY)');
        SqliteContentionActivity::$invocations = 0;
    }

    protected function tearDown(): void
    {
        WorkflowRunSummary::flushEventListeners();
        parent::tearDown();
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->path . $suffix)) {
                unlink($this->path . $suffix);
            }
        }
    }

    public static function databases(): array
    {
        return [
            'DELETE' => ['DELETE', false],
            'WAL' => ['WAL', false],
            'DELETE with separate application database' => ['DELETE', true],
            'WAL with separate application database' => ['WAL', true],
        ];
    }

    #[DataProvider('databases')]
    public function testSummaryWriterContentionDoesNotEscapeOrRepeatModelHooks(string $journal, bool $separate): void
    {
        $connection = DB::connection('projection_sqlite');
        $connection->getPdo()
            ->exec('PRAGMA journal_mode=' . $journal);
        if ($separate) {
            config()->set('database.connections.projection_application', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
            DB::setDefaultConnection('projection_application');
            $this->queueTables(DB::connection());
            config()
                ->set('queue.failed.database', 'projection_application');
            self::assertFalse(DB::connection()->getSchemaBuilder()->hasTable('workflow_runs'));
        }
        $stub = WorkflowStub::make(SqliteContentionWorkflow::class, 'projection-' . $journal, 'projection-test');
        $stub->start(false, false, new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 100));
        $this->workOnce();
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        self::assertGreaterThan(2, $run->last_history_sequence);
        self::assertSame(0, SqliteContentionActivity::$invocations);
        $summary = WorkflowRunSummary::query()->findOrFail($run->id);
        $summary->forceFill([
            'liveness_reason' => 'stale projection control',
        ])->save();
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all();
        $tasks = WorkflowTask::query()->where('workflow_run_id', $run->id)->orderBy('id')
            ->get()
            ->map->getRawOriginal()
            ->all();
        $deadlines = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
        $writer = new PDO('sqlite:' . $this->path);
        $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $writer->exec('PRAGMA busy_timeout=20');
        $writer->beginTransaction();
        $writer->exec('INSERT INTO projection_writer_probe (id) VALUES (1)');
        $rollbacks = 0;
        $saving = 0;
        WorkflowRunSummary::saving(static function (WorkflowRunSummary $row) use ($run, &$saving): void {
            if ($row->id === $run->id) {
                $saving++;
            }
        });
        $connection->getEventDispatcher()
            ->listen(TransactionRolledBack::class, static function ($event) use (
                $connection,
                $writer,
                &$rollbacks
            ): void {
                if ($event->connection === $connection && $writer->inTransaction()) {
                    self::assertSame(0, $connection->transactionLevel());
                    self::assertFalse($connection->getPdo()->inTransaction());
                    $rollbacks++;
                    $writer->commit();
                }
            });
        try {
            $projected = RunSummaryProjector::project($run->fresh());
        } finally {
            if ($writer->inTransaction()) {
                $writer->rollBack();
            }
        }
        self::assertSame(1, $rollbacks);
        self::assertSame(1, $saving);
        self::assertSame($run->id, $projected->id);
        self::assertNotSame('stale projection control', $projected->liveness_reason);
        self::assertSame($prefix, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all());
        self::assertSame($tasks, WorkflowTask::query()->where('workflow_run_id', $run->id)
            ->orderBy('id')
            ->get()
            ->map->getRawOriginal()
            ->all());
        self::assertSame($deadlines, [$run->fresh()->getRawOriginal('execution_deadline_at'),
            $run->fresh()
                ->getRawOriginal('run_deadline_at')]);
        self::assertSame(0, SqliteContentionActivity::$invocations);
        $this->workOnce();
        $this->workOnce();
        self::assertSame('completed', $stub->refresh()->status());
        self::assertSame(strtolower($journal), $connection->getPdo()->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame([
            'integer' => 9007199254740993,
            'optional' => null,
            'unicode' => 'λ',
        ], $stub->output());
        self::assertSame(1, SqliteContentionActivity::$invocations);
        self::assertSame($run->id, $stub->runId());
        self::assertSame($deadlines, [$run->fresh()->getRawOriginal('execution_deadline_at'),
            $run->fresh()
                ->getRawOriginal('run_deadline_at')]);
        self::assertSame($prefix, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->limit(count($prefix))
            ->get()
            ->map->getRawOriginal()
            ->all());
        self::assertSame(0, WorkflowTask::query()->where('workflow_run_id', $run->id)->sum('repair_count'));
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, DB::table('failed_jobs')->count());
    }

    protected function getPackageProviders($app): array
    {
        return [TestDatabaseServiceProvider::class, WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'projection_sqlite');
        $app['config']->set('database.connections.projection_sqlite', [
            'driver' => 'sqlite',
            'database' => $this->path,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 20,
            'journal_mode' => 'DELETE',
            'transaction_mode' => 'DEFERRED',
        ]);
        $app['config']->set('workflows.storage.connection', 'projection_sqlite');
        $app['config']->set('workflows.v1.enabled', false);
        $app['config']->set('workflows.v2.task_dispatch_mode', 'queue');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'projection_sqlite',
            'table' => 'failed_jobs',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');
    }

    private function queueTables(Connection $connection): void
    {
        $connection->statement('CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue TEXT NOT NULL, payload TEXT NOT NULL, attempts INTEGER NOT NULL,
            reserved_at INTEGER, available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
        $connection->statement('CREATE TABLE IF NOT EXISTS failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL UNIQUE, connection TEXT NOT NULL, queue TEXT NOT NULL,
            payload TEXT NOT NULL, exception TEXT NOT NULL, failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    }

    private function workOnce(): void
    {
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--once' => true,
            '--tries' => 1,
            '--timeout' => 30,
        ]);
    }
}
