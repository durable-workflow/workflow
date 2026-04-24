<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\TestSimpleWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowPendingStatus;
use Workflow\Watchdog;

final class WorkflowServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('workflow:watchdog');
        Cache::forget('workflow:watchdog:looping');

        $this->app->register(WorkflowServiceProvider::class);
    }

    protected function tearDown(): void
    {
        Cache::forget('workflow:watchdog');
        Cache::forget('workflow:watchdog:looping');

        parent::tearDown();
    }

    public function testProviderLoads(): void
    {
        $this->assertTrue(
            $this->app->getProvider(WorkflowServiceProvider::class) instanceof WorkflowServiceProvider
        );
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

    public function testCommandsAreRegistered(): void
    {
        $registeredCommands = array_keys(Artisan::all());

        $expectedCommands = ['make:activity', 'make:workflow'];

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

    public function testLoopingEventSkipsWhenWatchdogIsDisabled(): void
    {
        Queue::fake();
        Cache::forget('workflow:watchdog');
        Cache::forget('workflow:watchdog:looping');

        config([
            'workflows.watchdog.enabled' => false,
        ]);

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
}
