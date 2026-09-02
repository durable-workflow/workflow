<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\SchemaTestCase;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\TaskWatchdog;

final class WorkflowServiceProviderSchemaTest extends SchemaTestCase
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
        $this->deletePublishedWorkflowMigrations();

        Cache::forget('workflow:watchdog');
        Cache::forget('workflow:watchdog:looping');
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);

        parent::tearDown();
    }

    public function testProviderLoadsPackageMigrationsForFreshApps(): void
    {
        Artisan::call('migrate:fresh');

        $this->assertTrue(Schema::hasTable('workflows'));
        $this->assertTrue(Schema::hasTable('workflow_instances'));
        $this->assertTrue(Schema::hasTable('workflow_runs'));
        $this->assertTrue(Schema::hasTable('workflow_run_summaries'));
        $this->assertFalse(Schema::hasColumn('workflow_run_summaries', 'memo'));
        $this->assertFalse(Schema::hasColumn('workflow_run_summaries', 'search_attributes'));
        $this->assertTrue(Schema::hasTable('workflow_commands'));
    }

    public function testPublishedMigrationsCanRunAsInstallSource(): void
    {
        $this->deletePublishedWorkflowMigrations();

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
        $this->assertFalse(Schema::hasColumn('workflow_run_summaries', 'memo'));
        $this->assertFalse(Schema::hasColumn('workflow_run_summaries', 'search_attributes'));
        $this->assertTrue(Schema::hasTable('workflow_schedules'));
        $this->assertTrue(Schema::hasTable('workflow_schedule_history_events'));
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
