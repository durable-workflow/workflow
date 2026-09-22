<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Process\Process;
use Tests\Fixtures\TestSimpleWorkflow;
use Tests\SchemaTestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowPendingStatus;
use Workflow\V2\Contracts\MatchingRole;
use Workflow\Watchdog;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class V2OnlyInstallationTest extends SchemaTestCase
{
    private const LEGACY_TABLES = [
        'workflows',
        'workflow_logs',
        'workflow_signals',
        'workflow_timers',
        'workflow_exceptions',
        'workflow_relationships',
    ];

    private string|false $previousV1Flag = false;

    protected function setUp(): void
    {
        $this->previousV1Flag = getenv('DW_V1_ENABLED');
        putenv('DW_V1_ENABLED=false');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $packageFiles = glob(dirname(__DIR__, 3) . '/src/migrations/*.php') ?: [];
        $publishedNames = array_fill_keys(array_map('basename', $packageFiles), true);

        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            if (isset($publishedNames[basename($file)])) {
                @unlink($file);
            }
        }

        try {
            parent::tearDown();
        } finally {
            $this->previousV1Flag === false
                ? putenv('DW_V1_ENABLED')
                : putenv("DW_V1_ENABLED={$this->previousV1Flag}");
        }
    }

    public function testFreshV2OnlyInstallOmitsLegacyTablesAndMigrationLedgerEntries(): void
    {
        $this->assertFalse((bool) config('workflows.v1.enabled'));
        $this->assertNotContains(dirname(__DIR__, 3) . '/src/migrations-v1', $this->app->make('migrator')->paths());
        $this->assertSame([], glob(database_path('migrations/2022_01_01_*.php')) ?: [], database_path('migrations'));
        Artisan::call('migrate:fresh');

        $this->assertTrue(Schema::hasTable('workflow_instances'));
        $this->assertTrue(Schema::hasTable('workflow_runs'));

        foreach (self::LEGACY_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected legacy table {$table}");
        }

        $this->assertSame(0, DB::table('migrations') ->where('migration', 'like', '2022_01_01_%') ->count());
    }

    public function testV2OnlyFlagSurvivesLaravelConfigurationCache(): void
    {
        $cachePath = tempnam(sys_get_temp_dir(), 'wf_v2_config_');
        $this->assertIsString($cachePath);
        @unlink($cachePath);

        try {
            $process = new Process(
                [PHP_BINARY, 'vendor/bin/testbench', 'config:cache'],
                dirname(__DIR__, 3),
                [
                    'APP_CONFIG_CACHE' => $cachePath,
                    'DW_V1_ENABLED' => 'false',
                ],
            );
            $process->run();

            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertFileExists($cachePath);

            $cachedConfig = require $cachePath;
            $this->assertFalse($cachedConfig['workflows']['v1']['enabled']);
        } finally {
            @unlink($cachePath);
        }
    }

    public function testPublishedV2OnlyMigrationsCanInstallAndRollback(): void
    {
        Artisan::call('vendor:publish', [
            '--tag' => 'migrations',
            '--force' => true,
        ]);

        $published = array_map('basename', glob(database_path('migrations/*.php')) ?: []);
        $this->assertNotEmpty($published);
        $this->assertContains('2026_04_05_000100_create_workflow_instances_table.php', $published);

        foreach (glob(dirname(__DIR__, 3) . '/src/migrations-v1/*.php') ?: [] as $legacyFile) {
            $this->assertNotContains(basename($legacyFile), $published);
        }

        Schema::dropAllTables();
        Artisan::call('migrate:install');
        Artisan::call('migrate', [
            '--path' => database_path('migrations'),
            '--realpath' => true,
        ]);

        $this->assertTrue(Schema::hasTable('workflow_instances'));
        $this->assertFalse(Schema::hasTable('workflows'));

        Artisan::call('migrate:rollback', [
            '--path' => database_path('migrations'),
            '--realpath' => true,
        ]);

        $this->assertFalse(Schema::hasTable('workflow_instances'));
    }

    public function testLoopingSkipsLegacyWatchdogButWakesV2MatchingRole(): void
    {
        Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 3) . '/src/migrations-v1',
            '--realpath' => true,
        ]);

        Queue::fake();
        Cache::forget('workflow:watchdog');
        Cache::forget('workflow:watchdog:looping');

        StoredWorkflow::create([
            'class' => TestSimpleWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()
                ->subSeconds(Watchdog::DEFAULT_TIMEOUT + 1),
        ]);

        $matchingRole = $this->createMock(MatchingRole::class);
        $matchingRole->expects($this->once())
            ->method('wake')
            ->with('redis', 'high,default');
        $this->app->instance(MatchingRole::class, $matchingRole);

        Event::dispatch(new Looping('redis', 'high,default'));

        Queue::assertNotPushed(Watchdog::class);
        $this->assertFalse(Cache::has('workflow:watchdog:looping'));
    }
}
