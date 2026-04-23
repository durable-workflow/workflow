<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TestSimpleWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowPendingStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\TaskWatchdog;
use Workflow\Watchdog;

final class WorkflowServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('workflow:watchdog');
        Cache::forget('workflow:watchdog:looping');
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);

        $this->app->register(WorkflowServiceProvider::class);
    }

    protected function tearDown(): void
    {
        Cache::forget('workflow:watchdog');
        Cache::forget('workflow:watchdog:looping');
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);

        parent::tearDown();
    }

    public function testProviderLoads(): void
    {
        $this->assertTrue(
            $this->app->getProvider(WorkflowServiceProvider::class) instanceof WorkflowServiceProvider
        );
    }

    public function testProviderBootRejectsConfiguredInstanceModelThatChangesInferredForeignKeys(): void
    {
        config()->set('workflows.v2.instance_model', MisconfiguredProviderWorkflowInstance::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('workflows.v2.instance_model');
        $this->expectExceptionMessage('workflow_instance_id');
        $this->expectExceptionMessage('runs()');

        (new WorkflowServiceProvider($this->app))->boot();
    }

    public function testProviderBootAllowsConfiguredInstanceModelWithExplicitRelationOverrides(): void
    {
        config()->set('workflows.v2.instance_model', ValidatedProviderWorkflowInstance::class);

        (new WorkflowServiceProvider($this->app))->boot();

        $this->assertTrue(true);
    }

    public function testProviderMergesV2DefaultsIntoLegacyPublishedConfig(): void
    {
        config()->set('workflows', [
            'stored_workflow_model' => StoredWorkflow::class,
            'serializer' => Serializer::class,
            'webhooks_route' => 'legacy-webhooks',
        ]);

        (new WorkflowServiceProvider($this->app))->register();

        $this->assertSame('legacy-webhooks', config('workflows.webhooks_route'));
        $this->assertSame(Serializer::class, config('workflows.serializer'));
        $this->assertSame(\Workflow\V2\Models\WorkflowInstance::class, config('workflows.v2.instance_model'));
        $this->assertSame(\Workflow\V2\Models\WorkflowCommand::class, config('workflows.v2.command_model'));
        $this->assertSame(
            \Workflow\V2\Models\WorkflowTimelineEntry::class,
            config('workflows.v2.run_timeline_entry_model')
        );
        $this->assertSame(30, config('workflows.v2.compatibility.heartbeat_ttl_seconds'));
        $this->assertSame(10, config('workflows.v2.update_wait.completion_timeout_seconds'));
        $this->assertSame(50, config('workflows.v2.update_wait.poll_interval_milliseconds'));
        $this->assertSame(3, config('workflows.v2.task_repair.redispatch_after_seconds'));
        $this->assertSame(5, config('workflows.v2.task_repair.loop_throttle_seconds'));
        $this->assertSame(25, config('workflows.v2.task_repair.scan_limit'));
        $this->assertSame(60, config('workflows.v2.task_repair.failure_backoff_max_seconds'));
    }

    public function testConfigIsPublished(): void
    {
        Artisan::call('vendor:publish', [
            '--tag' => 'config',
        ]);

        $this->assertFileExists(config_path('workflows.php'));
    }

    public function testMigrationsArePublished(): void
    {
        Artisan::call('vendor:publish', [
            '--tag' => 'migrations',
        ]);

        $migrationFiles = glob(database_path('migrations/*.php'));
        $this->assertNotEmpty($migrationFiles, 'Migrations should be published');
    }

    public function testProviderLoadsPackageMigrationsForFreshApps(): void
    {
        Artisan::call('migrate:fresh');

        $this->assertTrue(Schema::hasTable('workflows'));
        $this->assertTrue(Schema::hasTable('workflow_instances'));
        $this->assertTrue(Schema::hasTable('workflow_runs'));
        $this->assertTrue(Schema::hasTable('workflow_run_summaries'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'memo'));
        $this->assertTrue(Schema::hasTable('workflow_commands'));
    }

    public function testPublishedMigrationsCanRunAsInstallSource(): void
    {
        $this->deletePublishedWorkflowMigrations();

        try {
            Artisan::call('vendor:publish', [
                '--tag' => 'migrations',
                '--force' => true,
            ]);

            $migrationFiles = glob(database_path('migrations/*.php')) ?: [];
            $this->assertNotEmpty($migrationFiles, 'Migrations should be published');

            Schema::dropAllTables();

            $this->artisan('migrate:install')
                ->run();

            $this->artisan('migrate', [
                '--path' => database_path('migrations'),
                '--realpath' => true,
            ])->run();

            $this->assertTrue(Schema::hasTable('workflow_instances'));
            $this->assertTrue(Schema::hasTable('workflow_runs'));
            $this->assertTrue(Schema::hasTable('workflow_run_summaries'));
            $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'memo'));
            $this->assertTrue(Schema::hasTable('workflow_schedules'));
            $this->assertTrue(Schema::hasTable('workflow_schedule_history_events'));
        } finally {
            $this->deletePublishedWorkflowMigrations();
        }
    }

    public function testCommandsAreRegistered(): void
    {
        $registeredCommands = array_keys(Artisan::all());

        $expectedCommands = [
            'make:activity',
            'make:workflow',
            'workflow:v2:doctor',
            'workflow:v2:history-export',
            'workflow:v2:repair-pass',
            'workflow:v2:rebuild-projections',
        ];

        foreach ($expectedCommands as $command) {
            $this->assertContains(
                $command,
                $registeredCommands,
                "Command [{$command}] is not registered in Artisan."
            );
        }

        $this->assertNotContains(
            'workflow:v2:backfill-parallel-group-metadata',
            $registeredCommands,
            'Final v2 must not ship preview-era parallel-group metadata backfill commands.'
        );
    }

    public function testLoopingEventWakesWatchdog(): void
    {
        Queue::fake();
        Cache::forget('workflow:watchdog');

        StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()
                ->subSeconds(Watchdog::DEFAULT_TIMEOUT + 1),
        ]);

        Event::dispatch(new Looping('redis', 'high,default'));

        Queue::assertPushed(Watchdog::class, static function (Watchdog $watchdog): bool {
            return $watchdog->connection === 'redis'
                && $watchdog->queue === 'high';
        });
    }

    public function testLoopingEventThrottlesWake(): void
    {
        Queue::fake();
        Cache::forget('workflow:watchdog');

        StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()
                ->subSeconds(Watchdog::DEFAULT_TIMEOUT + 1),
        ]);

        Event::dispatch(new Looping('redis', 'high,default'));
        Event::dispatch(new Looping('redis', 'high,default'));
        Event::dispatch(new Looping('redis', 'high,default'));

        Queue::assertPushed(Watchdog::class, 1);
    }

    public function testLoopingEventSkipsWhenThrottleAlreadyHeld(): void
    {
        Queue::fake();
        Cache::forget('workflow:watchdog');
        Cache::put('workflow:watchdog:looping', true, 60);

        StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()
                ->subSeconds(Watchdog::DEFAULT_TIMEOUT + 1),
        ]);

        Event::dispatch(new Looping('redis', 'high,default'));

        Queue::assertNotPushed(Watchdog::class);
    }

    public function testLoopingEventSkipsWhenNoRecoverablePendingWorkflowsExist(): void
    {
        Queue::fake();
        Cache::forget('workflow:watchdog');
        Cache::forget('workflow:watchdog:looping');

        Event::dispatch(new Looping('redis', 'high,default'));

        Queue::assertNotPushed(Watchdog::class);
    }

    public function testLoopingEventSkipsWatchdogsBeforeWorkflowTablesExist(): void
    {
        Queue::fake();
        Cache::forget('workflow:watchdog');
        Cache::forget('workflow:watchdog:looping');
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);

        foreach ([
            'workflow_relationships',
            'workflow_exceptions',
            'workflow_timers',
            'workflow_signals',
            'workflow_logs',
            'workflows',
            'workflow_worker_compatibility_heartbeats',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Event::dispatch(new Looping('redis', 'default'));

        Queue::assertNothingPushed();
        $this->assertFalse(Cache::has('workflow:watchdog'));
        $this->assertFalse(Cache::has('workflow:watchdog:looping'));
        $this->assertFalse(Cache::has(TaskWatchdog::LOOP_THROTTLE_KEY));
    }

    public function testLoopingEventRepairsOverdueV2Task(): void
    {
        Queue::fake();
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);

        $instance = WorkflowInstance::query()->create([
            'id' => 'provider-v2-repair-inst',
            'workflow_class' => TestSimpleWorkflow::class,
            'workflow_type' => TestSimpleWorkflow::class,
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestSimpleWorkflow::class,
            'workflow_type' => TestSimpleWorkflow::class,
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSeconds(20),
            'last_dispatched_at' => now()
                ->subSeconds(20),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        Event::dispatch(new Looping('redis', 'high,default'));

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );

        $task->refresh();

        $this->assertSame(1, $task->repair_count);
        $this->assertNotNull($task->last_dispatched_at);
    }

    private function deletePublishedWorkflowMigrations(): void
    {
        $sourceFiles = glob(dirname(__DIR__, 3) . '/src/migrations/*.php') ?: [];
        $publishedNames = array_fill_keys(array_map('basename', $sourceFiles), true);

        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            if (isset($publishedNames[basename($file)])) {
                @unlink($file);
            }
        }
    }
}

final class MisconfiguredProviderWorkflowInstance extends WorkflowInstance
{
    protected $table = 'misconfigured_provider_workflow_instances';
}

final class ValidatedProviderWorkflowInstance extends WorkflowInstance
{
    protected $table = 'validated_provider_workflow_instances';

    public function runs(): HasMany
    {
        return $this->hasMany(\Workflow\V2\Support\ConfiguredV2Models::resolve('run_model', WorkflowRun::class), 'workflow_instance_id');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(
            \Workflow\V2\Support\ConfiguredV2Models::resolve('command_model', WorkflowCommand::class),
            'workflow_instance_id',
        )->oldest('created_at');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(
            \Workflow\V2\Support\ConfiguredV2Models::resolve('update_model', WorkflowUpdate::class),
            'workflow_instance_id',
        )
            ->orderBy('command_sequence')
            ->oldest('accepted_at')
            ->oldest('created_at')
            ->oldest('id');
    }
}
