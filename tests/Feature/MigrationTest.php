<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Workflow\V2\WorkflowStub;
use Workflow\V2\StartOptions;
use Workflow\V2\Workflow;
use Workflow\V2\Activity;

/**
 * Tests v1→v2 migration path to ensure:
 * 1. v1 schema migrations run cleanly
 * 2. v1 workflow state is preserved after adding v2 tables
 * 3. v2 migrations run without breaking v1 data
 * 4. v2 workflows can be started and executed
 * 5. v1 and v2 workflows coexist in the same database
 */
class MigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fresh database for each test
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $tables = DB::select('SHOW TABLES');
        foreach ($tables as $table) {
            $tableName = array_values((array) $table)[0];
            if ($tableName !== 'migrations') {
                Schema::dropIfExists($tableName);
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::table('migrations')->truncate();
    }

    /**
     * @test
     */
    public function it_runs_v1_migrations_without_errors()
    {
        $this->runV1Migrations();

        // Assert v1 tables exist
        $this->assertTrue(Schema::hasTable('workflows'));
        $this->assertTrue(Schema::hasTable('workflow_logs'));
        $this->assertTrue(Schema::hasTable('workflow_signals'));
        $this->assertTrue(Schema::hasTable('workflow_timers'));
        $this->assertTrue(Schema::hasTable('workflow_exceptions'));
        $this->assertTrue(Schema::hasTable('workflow_relationships'));

        // Assert v1 table structure
        $this->assertTrue(Schema::hasColumns('workflows', [
            'id', 'class', 'arguments', 'output', 'status', 'created_at', 'updated_at'
        ]));
    }

    /**
     * @test
     */
    public function it_preserves_v1_workflow_data_after_v2_migration()
    {
        // Set up v1 schema and data
        $this->runV1Migrations();
        $v1WorkflowId = $this->seedV1WorkflowData();

        // Capture pre-migration state
        $v1Workflow = DB::table('workflows')->where('id', $v1WorkflowId)->first();
        $v1LogCount = DB::table('workflow_logs')->where('stored_workflow_id', $v1WorkflowId)->count();
        $v1SignalCount = DB::table('workflow_signals')->where('stored_workflow_id', $v1WorkflowId)->count();

        // Run v2 migrations
        $this->runV2Migrations();

        // Assert v2 tables exist
        $this->assertTrue(Schema::hasTable('workflow_instances'));
        $this->assertTrue(Schema::hasTable('workflow_runs'));
        $this->assertTrue(Schema::hasTable('workflow_history_events'));
        $this->assertTrue(Schema::hasTable('workflow_tasks'));
        $this->assertTrue(Schema::hasTable('activity_executions'));

        // Assert v1 tables still exist
        $this->assertTrue(Schema::hasTable('workflows'));
        $this->assertTrue(Schema::hasTable('workflow_logs'));
        $this->assertTrue(Schema::hasTable('workflow_signals'));

        // Assert v1 data is unchanged
        $v1WorkflowAfter = DB::table('workflows')->where('id', $v1WorkflowId)->first();
        $this->assertEquals($v1Workflow->class, $v1WorkflowAfter->class);
        $this->assertEquals($v1Workflow->status, $v1WorkflowAfter->status);
        $this->assertEquals($v1Workflow->arguments, $v1WorkflowAfter->arguments);

        // Assert v1 related data is unchanged
        $this->assertEquals($v1LogCount, DB::table('workflow_logs')->where('stored_workflow_id', $v1WorkflowId)->count());
        $this->assertEquals($v1SignalCount, DB::table('workflow_signals')->where('stored_workflow_id', $v1WorkflowId)->count());
    }

    /**
     * @test
     */
    public function it_allows_v2_workflows_after_migration()
    {
        // Set up v1 schema and data
        $this->runV1Migrations();
        $this->seedV1WorkflowData();

        // Run v2 migrations
        $this->runV2Migrations();

        // Start a v2 workflow
        $workflow = WorkflowStub::make(TestMigrationWorkflow::class, 'migration-test-v2-1');
        $runId = $workflow->start(['test' => true], StartOptions::rejectDuplicate());

        // Assert v2 data was created
        $this->assertNotNull($runId);

        $instance = DB::table('workflow_instances')
            ->where('instance_id', 'migration-test-v2-1')
            ->first();
        $this->assertNotNull($instance);

        $run = DB::table('workflow_runs')
            ->where('run_id', $runId)
            ->first();
        $this->assertNotNull($run);

        // Assert v1 data is still intact
        $v1Count = DB::table('workflows')->count();
        $this->assertGreaterThan(0, $v1Count, 'v1 workflows should still exist');
    }

    /**
     * @test
     */
    public function it_tracks_v1_workflows_separately_from_v2()
    {
        // Set up v1 schema and data
        $this->runV1Migrations();
        $v1Id = $this->seedV1WorkflowData();

        // Run v2 migrations
        $this->runV2Migrations();

        // Start a v2 workflow
        $workflow = WorkflowStub::make(TestMigrationWorkflow::class, 'migration-test-coexist');
        $runId = $workflow->start(['test' => true], StartOptions::rejectDuplicate());

        // v1 workflow should be in workflows table
        $v1Exists = DB::table('workflows')->where('id', $v1Id)->exists();
        $this->assertTrue($v1Exists, 'v1 workflow should exist in workflows table');

        // v2 workflow should be in workflow_instances table
        $v2Exists = DB::table('workflow_instances')
            ->where('instance_id', 'migration-test-coexist')
            ->exists();
        $this->assertTrue($v2Exists, 'v2 workflow should exist in workflow_instances table');

        // v2 workflow should NOT be in workflows table
        $v2InV1Table = DB::table('workflows')
            ->where('class', 'LIKE', '%TestMigrationWorkflow%')
            ->exists();
        $this->assertFalse($v2InV1Table, 'v2 workflow should not appear in v1 workflows table');

        // v1 workflow should NOT be in workflow_instances table
        $v1InV2Table = DB::table('workflow_instances')
            ->where('instance_id', 'test-v1-workflow')
            ->exists();
        $this->assertFalse($v1InV2Table, 'v1 workflow should not appear in v2 workflow_instances table');
    }

    /**
     * @test
     */
    public function it_preserves_v1_workflow_with_timer()
    {
        $this->runV1Migrations();

        // Create v1 workflow with timer
        $workflowId = DB::table('workflows')->insertGetId([
            'class' => 'App\\TestWorkflow',
            'arguments' => json_encode(['test' => true]),
            'status' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('workflow_timers')->insert([
            'stored_workflow_id' => $workflowId,
            'index' => 1,
            'stop_at' => now()->addHours(2),
            'created_at' => now(),
        ]);

        // Run v2 migrations
        $this->runV2Migrations();

        // Verify v1 timer is preserved
        $timer = DB::table('workflow_timers')
            ->where('stored_workflow_id', $workflowId)
            ->first();
        $this->assertNotNull($timer);
        $this->assertEquals(1, $timer->index);
    }

    /**
     * @test
     */
    public function it_preserves_v1_workflow_with_signals()
    {
        $this->runV1Migrations();

        // Create v1 workflow with signals
        $workflowId = DB::table('workflows')->insertGetId([
            'class' => 'App\\OrderWorkflow',
            'arguments' => json_encode(['order_id' => 123]),
            'status' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('workflow_signals')->insert([
            'stored_workflow_id' => $workflowId,
            'method' => 'approve',
            'arguments' => json_encode(['approved_by' => 'admin']),
            'created_at' => now(),
        ]);

        DB::table('workflow_signals')->insert([
            'stored_workflow_id' => $workflowId,
            'method' => 'payment_received',
            'arguments' => json_encode(['amount' => 99.99]),
            'created_at' => now()->addMinutes(5),
        ]);

        // Run v2 migrations
        $this->runV2Migrations();

        // Verify v1 signals are preserved
        $signals = DB::table('workflow_signals')
            ->where('stored_workflow_id', $workflowId)
            ->orderBy('created_at')
            ->get();
        $this->assertCount(2, $signals);
        $this->assertEquals('approve', $signals[0]->method);
        $this->assertEquals('payment_received', $signals[1]->method);
    }

    /**
     * @test
     */
    public function it_preserves_v1_workflow_with_exception()
    {
        $this->runV1Migrations();

        // Create v1 workflow with exception
        $workflowId = DB::table('workflows')->insertGetId([
            'class' => 'App\\FailedWorkflow',
            'arguments' => json_encode(['test' => true]),
            'status' => 'failed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('workflow_exceptions')->insert([
            'stored_workflow_id' => $workflowId,
            'class' => 'RuntimeException',
            'exception' => json_encode([
                'message' => 'Test exception',
                'code' => 500,
                'file' => '/app/test.php',
                'line' => 123,
            ]),
            'created_at' => now(),
        ]);

        // Run v2 migrations
        $this->runV2Migrations();

        // Verify v1 exception is preserved
        $exception = DB::table('workflow_exceptions')
            ->where('stored_workflow_id', $workflowId)
            ->first();
        $this->assertNotNull($exception);
        $this->assertEquals('RuntimeException', $exception->class);
    }

    /**
     * Run v1 migrations (2022_01_01_* files)
     */
    private function runV1Migrations(): void
    {
        $migrations = [
            '2022_01_01_000000_create_workflows_table.php',
            '2022_01_01_000001_create_workflow_logs_table.php',
            '2022_01_01_000002_create_workflow_signals_table.php',
            '2022_01_01_000003_create_workflow_timers_table.php',
            '2022_01_01_000004_create_workflow_exceptions_table.php',
            '2022_01_01_000005_create_workflow_relationships_table.php',
        ];

        foreach ($migrations as $migrationFile) {
            $path = __DIR__ . '/../../src/migrations/' . $migrationFile;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration not found: $path");
            }

            $migration = include $path;
            $migration->up();

            // Record in migrations table
            DB::table('migrations')->insert([
                'migration' => str_replace('.php', '', $migrationFile),
                'batch' => 1,
            ]);
        }
    }

    /**
     * Run v2 migrations (2026_04_* files)
     */
    private function runV2Migrations(): void
    {
        $migrationDir = __DIR__ . '/../../src/migrations/';
        $files = glob($migrationDir . '2026_04_*.php');
        sort($files);

        $batch = 2;
        foreach ($files as $path) {
            if (strpos($path, '.backup') !== false || strpos($path, '.bak') !== false) {
                continue;
            }

            $migration = include $path;
            $migration->up();

            $fileName = basename($path, '.php');
            DB::table('migrations')->insert([
                'migration' => $fileName,
                'batch' => $batch,
            ]);
        }
    }

    /**
     * Seed realistic v1 workflow data
     */
    private function seedV1WorkflowData(): int
    {
        // Create a completed v1 workflow
        $completedId = DB::table('workflows')->insertGetId([
            'class' => 'App\\SimpleWorkflow',
            'arguments' => json_encode(['name' => 'test']),
            'output' => json_encode(['result' => 'success']),
            'status' => 'completed',
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(1),
        ]);

        DB::table('workflow_logs')->insert([
            'stored_workflow_id' => $completedId,
            'index' => 1,
            'now' => now()->subHours(2),
            'class' => 'App\\TestActivity',
            'result' => json_encode(['done' => true]),
            'created_at' => now()->subHours(2),
        ]);

        // Create a running v1 workflow with signal
        $runningId = DB::table('workflows')->insertGetId([
            'class' => 'App\\OrderWorkflow',
            'arguments' => json_encode(['order_id' => 123]),
            'status' => 'running',
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(10),
        ]);

        DB::table('workflow_signals')->insert([
            'stored_workflow_id' => $runningId,
            'method' => 'approve',
            'arguments' => json_encode(['approved_by' => 'admin']),
            'created_at' => now()->subMinutes(20),
        ]);

        // Create a pending v1 workflow
        $pendingId = DB::table('workflows')->insertGetId([
            'class' => 'App\\InvoiceWorkflow',
            'arguments' => json_encode(['invoice_id' => 456]),
            'status' => 'pending',
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        return $completedId; // Return one for verification
    }
}

/**
 * Test workflow for v2 migration tests
 */
class TestMigrationWorkflow extends Workflow
{
    public function handle(array $input)
    {
        return ['migration_test' => 'passed'];
    }
}

/**
 * Test activity for v2 migration tests
 */
class TestMigrationActivity extends Activity
{
    public function handle(array $input)
    {
        return ['activity_result' => 'ok'];
    }
}
