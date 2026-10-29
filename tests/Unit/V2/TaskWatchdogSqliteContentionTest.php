<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class TaskWatchdogSqliteContentionTest extends TestCase
{
    private string $database = '';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::now());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->database . $suffix)) {
                unlink($this->database . $suffix);
            }
        }
    }

    public static function contentionPhases(): iterable
    {
        foreach (['DELETE', 'WAL'] as $journal) {
            foreach ([false, true] as $separate) {
                foreach (['repair', 'dispatch'] as $phase) {
                    yield $journal . ($separate ? ' separate storage ' : ' ') . $phase
                        => [$journal, $separate, $phase];
                }
            }
        }
    }

    #[DataProvider('contentionPhases')]
    public function testBusyWriterDefersOnlyTheUnenteredPhaseAndRecovers(
        string $journal,
        bool $separate,
        string $phase,
    ): void {
        Queue::fake();
        $storage = DB::connection('watchdog_sqlite');
        $storage->getPdo()
            ->exec('PRAGMA journal_mode=' . $journal);
        $storage->getPdo()
            ->exec('PRAGMA busy_timeout=20');
        $storage->statement('CREATE TABLE watchdog_writer_probe (id INTEGER PRIMARY KEY)');
        if ($separate) {
            config([
                'database.connections.watchdog_application' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                    'prefix' => '',
                ],
            ]);
            DB::setDefaultConnection('watchdog_application');
        }
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class);
        $workflow->start('Ada');
        $task = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())->sole();
        $task->forceFill([
            'available_at' => now()
                ->subMinute(),
            'last_dispatched_at' => now()
                ->subMinute(),
            'created_at' => now()
                ->subMinute(),
        ])->save();
        $before = $task->fresh()
            ->getRawOriginal();
        $history = WorkflowHistoryEvent::query()->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all();
        WorkerCompatibilityFleet::heartbeat();
        $saved = 0;
        WorkflowTask::saved(static function (WorkflowTask $row) use ($task, &$saved): void {
            if ($row->id === $task->id) {
                ++$saved;
            }
        });
        $writer = new PDO('sqlite:' . $this->database);
        $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $writer->exec('PRAGMA busy_timeout=20');
        $started = false;
        $holdWriter = static function () use ($writer, &$started): void {
            $started = true;
            $writer->beginTransaction();
            $writer->exec('INSERT INTO watchdog_writer_probe (id) VALUES (1)');
        };
        if ($phase === 'repair') {
            $holdWriter();
        } else {
            $application = DB::connection();
            $storage->getEventDispatcher()
                ->listen(
                    TransactionCommitted::class,
                    static function ($event) use ($storage, $application, &$started, &$saved, $holdWriter): void {
                        if (! $started && $saved > 0 && $event->connection->transactionLevel() === 0
                            && ($event->connection === $storage || $event->connection === $application)) {
                            $holdWriter();
                        }
                    }
                );
        }
        try {
            $report = TaskWatchdog::runPass(runIds: [$workflow->runId()]);
            $this->assertTrue($started);
            $this->assertSame([], $report['existing_task_failures']);
            if ($phase === 'repair') {
                $this->assertSame([[
                    'candidate_id' => $task->id,
                    'phase' => 'repair',
                    'reason' => 'sqlite_writer_busy',
                ]], $report['deferred_existing_tasks'] ?? []);
                $this->assertSame(0, $report['dispatched_tasks']);
                $this->assertSame(0, $saved);
                $this->assertSame($before, $task->fresh()->getRawOriginal());
            } else {
                // A competing writer starts only after the entire repair and
                // poll dispatch commit. Nothing from that commit can replay.
                $this->assertSame([], $report['deferred_existing_tasks'] ?? []);
                $this->assertSame(1, $report['dispatched_tasks']);
                $this->assertSame(2, $saved);
                $this->assertSame((int) $before['repair_count'] + 1, $task->fresh()->repair_count);
                $this->assertGreaterThan(
                    $before['last_dispatched_at'],
                    $task->fresh()
                        ->getRawOriginal('last_dispatched_at')
                );
            }
            Queue::assertNothingPushed();
        } finally {
            if ($writer->inTransaction()) {
                $writer->rollBack();
            }
        }
        $recovered = TaskWatchdog::runPass(runIds: [$workflow->runId()]);
        $this->assertSame([], $recovered['existing_task_failures']);
        $this->assertSame([], $recovered['deferred_existing_tasks'] ?? []);
        $this->assertSame($phase === 'repair' ? 1 : 0, $recovered['repaired_existing_tasks']);
        $this->assertSame($phase === 'repair' ? 1 : 0, $recovered['dispatched_tasks']);
        $this->assertSame(2, $saved);
        $this->assertGreaterThan($before['last_dispatched_at'], $task->fresh()->getRawOriginal('last_dispatched_at'));
        $this->assertSame($history, WorkflowHistoryEvent::query()->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all());
        $this->assertSame(20, (int) $storage->getPdo()->query('PRAGMA busy_timeout')->fetchColumn());
        Queue::assertNothingPushed();
    }

    public static function genuineFailures(): iterable
    {
        yield 'admission constraint failure' => [false];
        yield 'busy-looking model callback' => [true];
    }

    public static function writerHandoffs(): iterable
    {
        foreach (['DELETE', 'WAL'] as $journal) {
            for ($repeat = 0; $repeat < 3; ++$repeat) {
                yield $journal . ' handoff ' . $repeat => [$journal];
            }
        }
    }

    #[DataProvider('writerHandoffs')]
    public function testWaitingWriterRunsBetweenMaintenanceCandidates(string $journal): void
    {
        Queue::fake();
        $storage = DB::connection('watchdog_sqlite');
        $storage->getPdo()
            ->exec('PRAGMA journal_mode=' . $journal);
        $storage->getPdo()
            ->exec('PRAGMA busy_timeout=20');
        $storage->statement('CREATE TABLE watchdog_writer_probe (id INTEGER PRIMARY KEY)');
        $runs = [];
        $tasks = [];
        for ($index = 0; $index < 2; ++$index) {
            $workflow = WorkflowStub::make(TestGreetingWorkflow::class);
            $workflow->start('Ada');
            $runs[] = $workflow->runId();
            $tasks[] = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())->sole();
        }
        foreach ($tasks as $index => $task) {
            $task->forceFill([
                'available_at' => now()
                    ->subMinutes(2 - $index),
                'last_dispatched_at' => now()
                    ->subMinute(),
                'created_at' => now()
                    ->subMinutes(2 - $index),
            ])->save();
        }
        WorkerCompatibilityFleet::heartbeat();
        $saved = [0, 0];
        WorkflowTask::saved(static function (WorkflowTask $row) use ($tasks, &$saved): void {
            foreach ($tasks as $index => $task) {
                if ($row->id === $task->id) {
                    ++$saved[$index];
                    if ($index === 1) {
                        // Keep a repeated winner's next transaction observable.
                        usleep(400_000);
                    }
                }
            }
        });
        $program = <<<'PHP'
            $writer = new PDO('sqlite:'.$argv[1]);
            $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $writer->exec('PRAGMA busy_timeout=0');
            echo "ready\n";
            flush();
            usleep(50_000);
            $deadline = hrtime(true) + 150_000_000;
            do {
                try {
                    $writer->exec('BEGIN IMMEDIATE');
                    $writer->exec('INSERT INTO watchdog_writer_probe (id) VALUES (1)');
                    usleep(300_000);
                    $writer->exec('COMMIT');
                    echo 'admitted';
                    exit(0);
                } catch (PDOException $error) {
                    if (!in_array($error->errorInfo[1] ?? null, [5, 6], true)) {
                        throw $error;
                    }
                    usleep(10_000);
                }
            } while (hrtime(true) < $deadline);
            echo 'starved';
            exit(2);
            PHP;
        $child = null;
        $pipes = [];
        $database = $this->database;
        $storage->getEventDispatcher()
            ->listen(
                TransactionCommitted::class,
                static function ($event) use ($storage, &$saved, &$child, &$pipes, $program, $database): void {
                    if ($child === null && $saved[0] > 0 && $event->connection === $storage
                        && $storage->transactionLevel() === 0) {
                        $child = proc_open([PHP_BINARY, '-r', $program, $database], [
                            1 => ['pipe', 'w'],
                            2 => ['pipe', 'w'],
                        ], $pipes);
                        self::assertIsResource($child);
                        self::assertSame("ready\n", fgets($pipes[1]));
                    }
                }
            );
        try {
            $report = TaskWatchdog::runPass(runIds: $runs);
        } finally {
            self::assertIsResource($child);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($child);
        }
        $this->assertSame(0, $status, $output . $error);
        $this->assertSame('admitted', $output);
        $this->assertSame(1, $storage->table('watchdog_writer_probe')->count());
        $this->assertSame([], $report['existing_task_failures']);
        $this->assertSame(1, $report['repaired_existing_tasks']);
        $this->assertSame(1, $report['dispatched_tasks']);
        $this->assertSame([[
            'candidate_id' => $tasks[1]->id,
            'phase' => 'repair',
            'reason' => 'sqlite_writer_busy',
        ]], $report['deferred_existing_tasks'] ?? []);
        $this->assertSame([2, 0], $saved);
        $recovered = TaskWatchdog::runPass(runIds: $runs);
        $this->assertSame([], $recovered['existing_task_failures']);
        $this->assertSame([], $recovered['deferred_existing_tasks'] ?? []);
        $this->assertSame(1, $recovered['repaired_existing_tasks']);
        $this->assertSame([2, 2], $saved);
        Queue::assertNothingPushed();
    }

    #[DataProvider('genuineFailures')]
    public function testGenuineFailuresAreNotDeferred(bool $bodyFailure): void
    {
        Queue::fake();
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class);
        $workflow->start('Ada');
        $task = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())->sole();
        $task->forceFill([
            'available_at' => now()
                ->subMinute(),
            'last_dispatched_at' => now()
                ->subMinute(),
            'created_at' => now()
                ->subMinute(),
        ])->save();
        $calls = 0;
        if ($bodyFailure) {
            WorkflowTask::saving(static function (WorkflowTask $row) use ($task, &$calls): void {
                if ($row->id === $task->id) {
                    ++$calls;
                    $error = new \PDOException('callback refused with busy-looking error');
                    $error->errorInfo = ['HY000', 5, 'database is locked'];

                    throw $error;
                }
            });
        } else {
            DB::connection('watchdog_sqlite')->statement(
                'CREATE TRIGGER refuse_admission BEFORE UPDATE OF id ON workflow_tasks '
                . "BEGIN SELECT RAISE(ABORT, 'admission constraint refused'); END"
            );
        }
        $report = TaskWatchdog::runPass(runIds: [$workflow->runId()]);
        $this->assertCount(1, $report['existing_task_failures']);
        $this->assertSame([], $report['deferred_existing_tasks'] ?? []);
        $this->assertSame(0, $report['dispatched_tasks']);
        $this->assertSame($bodyFailure ? 1 : 0, $calls);
        $this->assertSame(0, $task->fresh()->repair_count);
        $this->assertStringContainsString(
            $bodyFailure ? 'callback refused' : 'admission constraint refused',
            $report['existing_task_failures'][0]['message']
        );
        Queue::assertNothingPushed();
    }

    public function testBusyLookingMetadataFailureIsNotWriterDeferral(): void
    {
        $task = new class() extends WorkflowTask {
            public function getKey(): mixed
            {
                $error = new \PDOException('metadata callback refused');
                $error->errorInfo = ['HY000', 5, 'database is locked'];

                throw $error;
            }
        };
        $this->assertOriginalAdmissionCallbackFailure($task, 'metadata callback refused');
    }

    public function testBusyLookingQueryObserverFailureIsNotWriterDeferral(): void
    {
        $task = new WorkflowTask([
            'id' => 'query-observer-task',
        ]);
        DB::listen(static function ($query): void {
            if (str_starts_with($query->sql, 'update') && in_array('query-observer-task', $query->bindings, true)) {
                $error = new \PDOException('query observer refused');
                $error->errorInfo = ['HY000', 5, 'database is locked'];

                throw $error;
            }
        });
        $this->assertOriginalAdmissionCallbackFailure($task, 'query observer refused');
    }

    public function testQueryObserverDatabaseFailureIsNotWriterDeferral(): void
    {
        $task = new WorkflowTask([
            'id' => 'query-observer-database-task',
        ]);
        $storage = DB::connection('watchdog_sqlite');
        $storage->statement('CREATE TABLE watchdog_observer_probe (id INTEGER PRIMARY KEY)');
        config([
            'database.connections.watchdog_observer' => [
                'driver' => 'sqlite',
                'database' => $this->database,
                'prefix' => '',
            ],
        ]);
        $observer = DB::connection('watchdog_observer');
        $observer->getPdo()
            ->exec('PRAGMA busy_timeout=20');
        DB::listen(static function ($query) use ($observer): void {
            if (str_starts_with($query->sql, 'update')
                && in_array('query-observer-database-task', $query->bindings, true)) {
                $observer->insert('INSERT INTO watchdog_observer_probe (id) VALUES (1)');
            }
        });
        $this->assertOriginalAdmissionCallbackFailure($task, 'watchdog_observer_probe');
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $temporaryRoot = is_dir('/dev/shm') && is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
        $this->database = (string) tempnam($temporaryRoot, 'watchdog-sqlite-');
        $app['config']->set('database.default', 'watchdog_sqlite');
        $app['config']->set('database.connections.watchdog_sqlite', [
            'driver' => 'sqlite',
            'database' => $this->database,
            'prefix' => '',
        ]);
        $app['config']->set('workflows.storage.connection', 'watchdog_sqlite');
        $app['config']->set('workflows.v2.task_dispatch_mode', 'poll');
        $app['config']->set('workflows.v1.enabled', false);
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate', [
            '--database' => 'watchdog_sqlite',
            '--force' => true,
        ])->assertExitCode(0);
    }

    private function assertOriginalAdmissionCallbackFailure(WorkflowTask $task, string $message): void
    {
        $entered = false;
        $failure = null;
        try {
            \Workflow\V2\Support\SqliteTransaction::forTaskOnce($task, static function () use (&$entered): void {
                $entered = true;
            });
        } catch (\Throwable $error) {
            $failure = $error;
        }
        $this->assertInstanceOf(\PDOException::class, $failure);
        $this->assertStringContainsString($message, $failure->getMessage());
        $this->assertFalse($entered);
        $this->assertSame(0, $task->getConnection()->transactionLevel());
    }
}
