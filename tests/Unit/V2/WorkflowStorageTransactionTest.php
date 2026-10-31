<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh', [
            '--database' => 'workflow_storage',
            '--force' => true,
        ])->assertExitCode(0);
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
