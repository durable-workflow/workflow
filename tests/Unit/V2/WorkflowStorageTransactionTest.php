<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Fixtures\V2\TestConfiguredStorageWorkflow;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Events\WorkflowStarted;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class WorkflowStorageTransactionTest extends TestCase
{
    public static function storageModes(): array
    {
        return [
            'configured storage' => [false],
            'custom model connections' => [true],
        ];
    }

    #[DataProvider('storageModes')]
    public function testFailedStartRollsBackTheRunAndEveryRelatedWrite(bool $custom): void
    {
        $this->configureCustomModels($custom);
        Queue::fake();
        $workflow = WorkflowStub::make(TestConfiguredStorageWorkflow::class);
        Event::listen('eloquent.creating: ' . WorkflowTask::class, static function (): never {
            throw new RuntimeException('refuse initial task');
        });

        try {
            $workflow->start();
            $this->fail('The initial task must be rejected by the fixture.');
        } catch (RuntimeException $error) {
            $this->assertSame('refuse initial task', $error->getMessage());
        }

        $this->assertSame(0, WorkflowRun::query()->count(), 'The failed start cannot retain a partial run.');
        $this->assertSame(0, WorkflowTask::query()->count());
        $this->assertSame(0, WorkflowCommand::query()->count());
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
        $instance = WorkflowInstance::query()->sole();
        $this->assertNull($instance->current_run_id);
        $this->assertSame(0, $instance->run_count);
        Queue::assertNothingPushed();
    }

    #[DataProvider('storageModes')]
    public function testTaskClaimLocksAreHeldByTheStorageTransaction(bool $custom): void
    {
        $this->configureCustomModels($custom);
        Queue::fake();
        $workflow = WorkflowStub::make(TestConfiguredStorageWorkflow::class);
        $workflow->start();
        $task = WorkflowTask::query()->sole();
        $levels = [];
        DB::listen(static function (QueryExecuted $query) use (&$levels): void {
            if ($query->connectionName === 'workflow_storage'
                && str_starts_with(strtolower($query->sql), 'select')) {
                $levels[] = $query->connection->transactionLevel();
            }
        });

        $claimed = $this->app->make(WorkflowTaskBridge::class)->claimStatus($task->id, 'storage-worker');

        $this->assertTrue($claimed['claimed']);
        $this->assertNotSame([], $levels);
        $this->assertGreaterThanOrEqual(
            1,
            min($levels),
            'Claim reads and row locks must share the storage transaction.'
        );
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    #[DataProvider('storageModes')]
    public function testTaskDispatchWaitsForTheStorageCommit(bool $custom): void
    {
        $this->configureCustomModels($custom);
        Queue::fake();
        $storage = DB::connection('workflow_storage');
        $storage->beginTransaction();
        try {
            $workflow = WorkflowStub::make(TestConfiguredStorageWorkflow::class);
            $workflow->start();
            Queue::assertNothingPushed();
            $storage->commit();
            Queue::assertPushed(RunWorkflowTask::class, 1);
        } finally {
            if ($storage->transactionLevel() > 0) {
                $storage->rollBack();
            }
        }
    }

    public static function commitOutcomes(): array
    {
        return [
            'configured storage/commit' => [true, false],
            'configured storage/rollback' => [false, false],
            'custom models/commit' => [true, true],
            'custom models/rollback' => [false, true],
        ];
    }

    #[DataProvider('commitOutcomes')]
    public function testStartEventsFollowTheOutermostStorageTransaction(bool $commit, bool $custom): void
    {
        $this->configureCustomModels($custom);
        Queue::fake();
        Event::fake([WorkflowStarted::class]);
        $storage = DB::connection('workflow_storage');
        $storage->beginTransaction();
        try {
            $workflow = WorkflowStub::make(TestConfiguredStorageWorkflow::class);
            $workflow->start();
            Event::assertNotDispatched(WorkflowStarted::class);
            $commit ? $storage->commit() : $storage->rollBack();
            if ($commit) {
                Event::assertDispatched(WorkflowStarted::class, 1);
            } else {
                Event::assertNotDispatched(WorkflowStarted::class);
                $this->assertSame(0, WorkflowInstance::query()->count());
                $this->assertSame(0, WorkflowRun::query()->count());
                $this->assertSame(0, WorkflowTask::query()->count());
                Queue::assertNothingPushed();
            }
        } finally {
            if ($storage->transactionLevel() > 0) {
                $storage->rollBack();
            }
        }
    }

    #[DataProvider('storageModes')]
    public function testIndependentClaimantsProduceOneLease(bool $custom): void
    {
        $this->configureCustomModels($custom);
        $storage = DB::connection('workflow_storage');
        if (! in_array($storage->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The physical row-lock probe requires PostgreSQL, MySQL or MariaDB.');
        }
        Queue::fake();
        $workflow = WorkflowStub::make(TestConfiguredStorageWorkflow::class);
        $workflow->start();
        $task = WorkflowTask::query()->sole();
        $directory = sys_get_temp_dir() . '/workflow-storage-claims-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory . '/settings.json', json_encode([
            'connection' => config('database.connections.workflow_storage'),
            'custom' => $custom,
            'task_id' => $task->id,
        ], JSON_THROW_ON_ERROR));
        $processes = [];
        $probe = null;
        try {
            $processes['A'] = $this->startClaimant($directory, 'A');
            $this->waitForClaimMarker($directory . '/A.selected', $processes['A']);
            config()
                ->set('database.connections.claim_probe', config('database.connections.workflow_storage'));
            $probe = DB::connection('claim_probe');
            $locked = false;
            $probe->beginTransaction();
            try {
                $probe->table($task->getTable())
                    ->where('id', $task->id)
                    ->lock('for update nowait')
                    ->first();
            } catch (QueryException $error) {
                // NOWAIT uses 3572 on MySQL and 1205 on MariaDB, both with HY000.
                $this->assertTrue(
                    $storage->getDriverName() === 'pgsql'
                        ? ($error->errorInfo[0] ?? null) === '55P03'
                        : (($error->errorInfo[0] ?? null) === 'HY000'
                            && in_array($error->errorInfo[1] ?? null, [1205, 3572], true)),
                    'The independent probe must fail specifically because the claimed row is locked. '
                        . json_encode($error->errorInfo, JSON_THROW_ON_ERROR),
                );
                $locked = true;
            } finally {
                $probe->rollBack();
            }
            $this->assertTrue($locked, 'The storage row lock must remain held after the claim read.');
            $processes['B'] = $this->startClaimant($directory, 'B');
            $this->waitForClaimMarker($directory . '/B.query', $processes['B']);
            file_put_contents($directory . '/release', 'continue');
            $claimed = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $claimed[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['claimed'];
            }
            $this->assertSame([true, false], $claimed);
            $this->assertSame('storage-A', $task->fresh()->lease_owner);
            $this->assertSame(1, $task->fresh()->attempt_count);
            $probe->beginTransaction();
            try {
                $this->assertNotNull($probe->table($task->getTable())
                    ->where('id', $task->id)
                    ->lock('for update nowait')
                    ->first(), 'The same probe must succeed after the claim transactions release the row.');
            } finally {
                $probe->rollBack();
            }
        } finally {
            file_put_contents($directory . '/release', 'continue');
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            if ($probe !== null) {
                DB::purge('claim_probe');
            }
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    public static function interleavedOutcomes(): array
    {
        $cases = [];
        foreach (self::storageModes() as $mode => [$custom]) {
            $cases[$mode . '/storage commits first'] = [$custom, true, true];
            $cases[$mode . '/storage rolls back first'] = [$custom, true, false];
            $cases[$mode . '/application rolls back first'] = [$custom, false, true];
            $cases[$mode . '/application commits first'] = [$custom, false, false];
        }

        return $cases;
    }

    #[DataProvider('interleavedOutcomes')]
    public function testAnotherConnectionCannotOwnStorageCallbacks(
        bool $custom,
        bool $storageFirst,
        bool $commitStorage,
    ): void {
        $this->configureCustomModels($custom);
        Queue::fake();
        Event::fake([WorkflowStarted::class]);
        $storage = DB::connection('workflow_storage');
        $application = DB::connection();
        $storage->beginTransaction();
        $application->beginTransaction();
        try {
            WorkflowStub::make(TestConfiguredStorageWorkflow::class)->start();
            $this->assertPublication(false);
            $first = $storageFirst ? $storage : $application;
            ($storageFirst ? $commitStorage : ! $commitStorage) ? $first->commit() : $first->rollBack();
            $this->assertPublication($storageFirst && $commitStorage);
            $last = $storageFirst ? $application : $storage;
            ($storageFirst ? ! $commitStorage : $commitStorage) ? $last->commit() : $last->rollBack();
            $this->assertPublication($commitStorage);
            $this->assertSame($commitStorage ? 1 : 0, WorkflowRun::query()->count());
            $this->assertSame($commitStorage ? 1 : 0, WorkflowTask::query()->count());
        } finally {
            foreach ([$storage, $application] as $connection) {
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            }
        }
    }

    #[DataProvider('storageModes')]
    public function testPhysicalQueuePublicationDoesNotJoinAnUnrelatedApplicationTransaction(bool $custom): void
    {
        $this->configureCustomModels($custom);
        $storage = DB::connection('workflow_storage');
        if (! $storage->getSchemaBuilder()->hasTable('jobs')) {
            $storage->getSchemaBuilder()
                ->create('jobs', static function (Blueprint $table): void {
                    $table->bigIncrements('id');
                    $table->string('queue')
                        ->index();
                    $table->longText('payload');
                    $table->unsignedTinyInteger('attempts');
                    $table->unsignedInteger('reserved_at')
                        ->nullable();
                    $table->unsignedInteger('available_at');
                    $table->unsignedInteger('created_at');
                });
        }
        $application = DB::connection();
        $application->beginTransaction();
        try {
            WorkflowStub::make(TestConfiguredStorageWorkflow::class)->start();
            $this->assertSame(1, $storage->table('jobs')->count(), 'Committed storage must publish its physical job.');
            $application->rollBack();
            $this->assertSame(1, WorkflowRun::query()->count());
            $this->assertSame(1, $storage->table('jobs')->count());
        } finally {
            if ($application->transactionLevel() > 0) {
                $application->rollBack();
            }
        }
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';
        $storage = $app['config']->get('database.connections.' . $driver);
        if ($driver === 'sqlite') {
            $storage['database'] = ':memory:';
        }
        $app['config']->set('database.connections.workflow_storage', $storage);
        $app['config']->set('database.connections.application', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('database.default', 'application');
        $app['config']->set('workflows.storage.connection', 'workflow_storage');
        $app['config']->set('workflows.v1.enabled', false);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database.connection', 'workflow_storage');
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh', [
            '--database' => 'workflow_storage',
            '--force' => true,
        ])->assertExitCode(0);
    }

    private function assertPublication(bool $published): void
    {
        if ($published) {
            Queue::assertPushed(RunWorkflowTask::class, 1);
            Event::assertDispatched(WorkflowStarted::class, 1);

            return;
        }

        Queue::assertNothingPushed();
        Event::assertNotDispatched(WorkflowStarted::class);
    }

    private function configureCustomModels(bool $custom): void
    {
        if ($custom) {
            config([
                'workflows.storage.connection' => null,
                'workflows.v2.run_model' => StorageTransactionRun::class,
                'workflows.v2.instance_model' => StorageTransactionInstance::class,
            ]);
        }
    }

    private function startClaimant(string $directory, string $worker): Process
    {
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../Fixtures/V2/storage-claim-process.php',
            $directory,
            $worker,
        ]);
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    private function waitForClaimMarker(string $path, Process $process): void
    {
        $deadline = hrtime(true) + 10_000_000_000;
        while (! is_file($path) && $process->isRunning() && hrtime(true) < $deadline) {
            usleep(10_000);
        }
        $this->assertFileExists($path, $process->getErrorOutput());
    }
}

final class StorageTransactionRun extends WorkflowRun
{
    protected $connection = 'workflow_storage';

    public function getForeignKey(): string
    {
        return 'workflow_run_id';
    }
}

final class StorageTransactionInstance extends WorkflowInstance
{
    protected $connection = 'workflow_storage';

    public function getForeignKey(): string
    {
        return 'workflow_instance_id';
    }
}
