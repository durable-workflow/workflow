<?php

declare(strict_types=1);

namespace Tests\Unit;

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
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
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
        $this->assertSame(\Workflow\V2\Models\WorkflowTimelineEntry::class, config('workflows.v2.run_timeline_entry_model'));
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
        $this->assertTrue(Schema::hasTable('workflow_commands'));
    }

    public function testCommandsAreRegistered(): void
    {
        $registeredCommands = array_keys(Artisan::all());

        $expectedCommands = [
            'make:activity',
            'make:workflow',
            'workflow:v2:backfill-command-lifecycles',
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
}
