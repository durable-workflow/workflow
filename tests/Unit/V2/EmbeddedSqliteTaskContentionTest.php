<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\SqliteContentionActivity;
use Tests\Fixtures\V2\SqliteContentionWorkflow;
use Tests\Support\TestDatabaseServiceProvider;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\WorkflowStub;

final class EmbeddedSqliteTaskContentionTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'dw-task-contention-');
        parent::setUp();
        $this->artisan('migrate', [
            '--force' => true,
        ])->run();
        $this->createQueueTables(DB::connection());
        DB::statement('CREATE TABLE contention_probe (id INTEGER PRIMARY KEY)');
        SqliteContentionActivity::$invocations = 0;
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

    public static function phases(): array
    {
        $phases = [
            'workflow claim' => ['workflow_claim'],
            'workflow execution admission' => ['workflow_execution'],
            'activity claim' => ['activity_claim'],
            'activity outcome after callback' => ['activity_outcome'],
            'start control API' => ['start'],
            'signal control API' => ['signal'],
            'timer claim admission' => ['timer_claim'],
            'timer firing admission' => ['timer_fire'],
        ];
        $cases = [];
        foreach ($phases as $name => [$phase]) {
            $cases[$name] = [$phase, false];
            $cases[$name . ' with separate application database'] = [$phase, true];
            if (! in_array($phase, ['start', 'signal'], true)) {
                $cases[$name . ' after three rollbacks'] = [$phase, false, 3];
                $cases[$name . ' after three rollbacks with separate application database'] = [$phase, true, 3];
            }
        }

        return $cases;
    }

    public static function claims(): array
    {
        return [
            'workflow claim' => ['workflow', false],
            'workflow claim with separate application database' => ['workflow', true],
            'activity claim' => ['activity', false],
            'activity claim with separate application database' => ['activity', true],
        ];
    }

    #[DataProvider('claims')]
    public function testRepeatedWalCommitsCannotStrandTheOriginalTask(string $kind, bool $separate): void
    {
        config()->set('logging.default', 'null');
        if ($separate) {
            config()->set('database.connections.application_sqlite', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
            DB::setDefaultConnection('application_sqlite');
            $this->createQueueTables(DB::connection());
            config()
                ->set('queue.failed.database', 'application_sqlite');
        }
        $stub = WorkflowStub::make(SqliteContentionWorkflow::class, 'repeated-' . $kind, 'contention-test');
        $stub->start(false, false);
        $runId = $stub->runId();
        if ($kind === 'activity') {
            $this->workOnce();
        }
        $task = WorkflowTask::query()->where('workflow_run_id', $runId)->where('task_type', $kind)->sole();
        $taskId = $task->id;
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderBy('sequence')
            ->get()
            ->map->getAttributes()
            ->all();
        $writer = new PDO('sqlite:' . $this->path);
        $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $writer->exec('PRAGMA busy_timeout=1');
        $competingCommits = 0;
        $blockedWrites = 0;
        $armed = true;
        DB::connection('contention_sqlite')->getEventDispatcher()->listen(
            QueryExecuted::class,
            static function (QueryExecuted $query) use (
                $writer,
                $taskId,
                &$armed,
                &$competingCommits,
                &$blockedWrites
            ): void {
                if (! $armed || $query->connectionName !== 'contention_sqlite'
                    || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'from "workflow_tasks"')
                    || ! in_array($taskId, $query->bindings, true)) {
                    return;
                }
                try {
                    // Every fresh read is followed by another real WAL commit
                    // unless the claim already owns SQLite's writer.
                    $writer->exec('INSERT INTO contention_probe (id) VALUES (' . ($competingCommits + 1) . ')');
                    $competingCommits++;
                } catch (PDOException $error) {
                    if (($error->errorInfo[1] & 255) !== 5) {
                        throw $error;
                    }
                    $blockedWrites++;
                }
            },
        );
        $this->workOnce();
        $armed = false;
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'Repeated fresh snapshots must not strand a claim.');
        $this->assertSame(0, $competingCommits);
        $this->assertGreaterThan(0, $blockedWrites);
        $this->assertSame('completed', $task->fresh()->status->value);
        $this->assertSame(1, (int) $task->fresh()->attempt_count);
        if ($kind === 'workflow') {
            $this->workOnce();
        }
        $this->workOnce();
        $this->assertSame('completed', $stub->refresh()->status());
        $this->assertSame($runId, $stub->runId());
        $this->assertSame($taskId, $task->fresh()->id);
        $this->assertSame(1, SqliteContentionActivity::$invocations);
        $this->assertSame([
            'integer' => 9007199254740993,
            'optional' => null,
            'unicode' => 'λ',
        ], $stub->output());
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderBy('sequence')->get();
        $this->assertSame($prefix, $events->take(count($prefix))->map->getAttributes()->all());
        $this->assertSame(
            1,
            $events->where('event_type', \Workflow\V2\Enums\HistoryEventType::ActivityCompleted)->count()
        );
        $this->assertSame(
            1,
            $events->where('event_type', \Workflow\V2\Enums\HistoryEventType::WorkflowCompleted)->count()
        );
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $runId)->sum('repair_count'));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    #[DataProvider('phases')]
    public function testOriginalRunCompletesOnceAfterRealWalWrite(
        string $phase,
        bool $separate,
        int $releaseAfter = 1
    ): void {
        if ($separate) {
            config()->set('database.connections.application_sqlite', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
            DB::setDefaultConnection('application_sqlite');
            $this->createQueueTables(DB::connection());
            config()
                ->set('queue.failed.database', 'application_sqlite');
            $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('workflow_tasks'));
        }
        $connection = DB::connection('contention_sqlite');
        $writer = new PDO('sqlite:' . $this->path);
        $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $writer->exec('PRAGMA busy_timeout=20');
        $armed = $phase === 'start';
        $injected = false;
        $admissions = 0;
        $rollbacks = 0;
        $connection->getEventDispatcher()
            ->listen(TransactionRolledBack::class, static function () use ($writer, $releaseAfter, &$rollbacks): void {
                if ($writer->inTransaction() && ++$rollbacks >= $releaseAfter) {
                    $writer->commit();
                }
            });
        $connection->beforeExecuting(static function (string $sql, array $bindings, $database) use (
            $writer,
            $phase,
            &$armed,
            &$injected,
            &$admissions,
        ): void {
            if (! $armed || $injected) {
                return;
            }
            $matches = match ($phase) {
                'workflow_claim', 'activity_claim' => str_starts_with($sql, 'update "workflow_tasks"')
                    && in_array('leased', $bindings, true),
                'activity_outcome' => str_starts_with($sql, 'update "activity_executions"')
                    && in_array('completed', $bindings, true),
                'signal' => str_starts_with($sql, 'update "workflow_runs"')
                    && str_contains($sql, 'last_command_sequence'),
                default => false,
            };
            $writeAdmission = in_array(
                $phase,
                [
                    'workflow_claim',
                    'activity_claim',
                    'activity_outcome',
                    'workflow_execution',
                    'timer_claim',
                    'timer_fire',
                ],
                true
            )
                && str_starts_with($sql, 'update "workflow_tasks" set "id" =');
            if ($writeAdmission) {
                $admissions++;
                $matches = $admissions === (in_array($phase, ['timer_fire', 'activity_outcome'], true) ? 2 : 1);
            }
            if (in_array($phase, ['start', 'signal'], true)) {
                $matches = str_starts_with($sql, 'update "workflow_instances" set "id" =');
            }
            if (! $matches) {
                return;
            }
            if ($database->transactionLevel() !== 1 || ! $database->getPdo()->inTransaction()) {
                throw new RuntimeException('Expected the original root mutation transaction.');
            }
            if ($writeAdmission || in_array($phase, ['start', 'signal'], true)) {
                $writer->beginTransaction();
            }
            $writer->exec('INSERT INTO contention_probe (id) VALUES (1)');
            $injected = true;
        });

        $stub = WorkflowStub::make(SqliteContentionWorkflow::class, 'original-' . $phase, 'contention-test');
        $stub->start($phase === 'signal', in_array($phase, ['timer_claim', 'timer_fire'], true));
        $runId = $stub->runId();
        $firstTask = WorkflowTask::query()->where('workflow_run_id', $runId)->sole();
        $originalTaskId = $firstTask->id;
        $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->get()
            ->map->getAttributes()
            ->all();
        $armed = in_array($phase, ['workflow_claim', 'workflow_execution'], true);
        $this->workOnce();
        $this->assertSame(1, (int) $firstTask->fresh()->attempt_count);
        $armed = in_array($phase, ['activity_claim', 'activity_outcome'], true);
        $activity = WorkflowTask::query()->where('workflow_run_id', $runId)->where('task_type', 'activity')->sole();
        $activityTaskId = $activity->id;
        $this->workOnce();
        $this->assertSame(
            1,
            SqliteContentionActivity::$invocations,
            'Publishing a result must not rerun its callback.'
        );
        $this->assertSame(1, (int) $activity->fresh()->attempt_count);
        $armed = false;
        $this->workOnce();
        $timer = null;
        $originalFireAt = null;
        if (in_array($phase, ['timer_claim', 'timer_fire'], true)) {
            $timer = WorkflowTimer::query()->where('workflow_run_id', $runId)->sole();
            $originalFireAt = $timer->fire_at->toJSON();
            $this->travel(2)
                ->seconds();
            $armed = true;
            $admissions = 0;
            $this->workOnce();
            $armed = false;
            $this->workOnce();
        }
        if ($phase === 'signal') {
            $this->assertSame('waiting', $stub->refresh()->status());
            $armed = true;
            $this->assertTrue($stub->signal('finish', 'original signal')->accepted());
            $armed = false;
            $this->workOnce();
        }

        $this->assertTrue($injected, 'The real competing write must have reached the selected boundary.');
        $this->assertSame('completed', $stub->refresh()->status());
        $this->assertSame($runId, $stub->runId());
        $this->assertSame([
            'integer' => 9007199254740993,
            'optional' => null,
            'unicode' => 'λ',
        ], $stub->output());
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderBy('sequence')->get();
        $this->assertSame($prefix, $events->take(count($prefix))->map->getAttributes()->all());
        $types = $events->pluck('event_type')
            ->map(static fn ($type): string => $type->value)
            ->countBy();
        $this->assertSame(1, $types['ActivityStarted']);
        $this->assertSame(1, $types['ActivityCompleted']);
        $this->assertSame(1, $types['WorkflowCompleted']);
        if ($timer !== null) {
            $this->assertSame($originalFireAt, $timer->fresh()->fire_at->toJSON());
            $this->assertSame(1, $types['TimerFired']);
            $fired = $events->firstWhere('event_type', \Workflow\V2\Enums\HistoryEventType::TimerFired);
            $this->assertSame($timer->id, $fired->payload['timer_id']);
        }
        $this->assertSame($originalTaskId, $firstTask->fresh()->id);
        $this->assertSame($activityTaskId, $activity->fresh()->id);
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $runId)->sum('repair_count'));
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    protected function getPackageProviders($app): array
    {
        return [TestDatabaseServiceProvider::class, WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'contention_sqlite');
        $app['config']->set('database.connections.contention_sqlite', [
            'driver' => 'sqlite',
            'database' => $this->path,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 20,
            'journal_mode' => 'WAL',
            'transaction_mode' => 'DEFERRED',
        ]);
        $app['config']->set('workflows.storage.connection', 'contention_sqlite');
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
            'database' => 'contention_sqlite',
            'table' => 'failed_jobs',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'stderr');
    }

    private function createQueueTables(Connection $database): void
    {
        $database->statement('CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue TEXT NOT NULL, payload TEXT NOT NULL, attempts INTEGER NOT NULL,
            reserved_at INTEGER, available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
        $database->statement('CREATE TABLE IF NOT EXISTS failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
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
