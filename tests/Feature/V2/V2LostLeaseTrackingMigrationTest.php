<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\SchemaTestCase;
use Workflow\Support\WorkflowMigration;

/**
 * The lost-lease tracking columns are added to the hot task and run-summary
 * tables without a backfill or a table-locking rebuild: outside the migrator
 * transaction, each column under the schema lock budget, and repeatable.
 */
final class V2LostLeaseTrackingMigrationTest extends SchemaTestCase
{
    private const NAME = '2026_11_18_000100_add_lost_lease_tracking';

    public function testItRunsOutsideOneTransactionUnderTheSchemaLockBudget(): void
    {
        $migration = $this->migration();
        $this->assertFalse($migration->withinTransaction);

        $source = (string) file_get_contents(self::path());
        $this->assertSame(3, substr_count($source, 'withSchemaLockBudget'));
        // No backfill: existing tasks have lost no lease and need no attention.
        $this->assertDoesNotMatchRegularExpression('/->\s*(update|insert|upsert|delete)\s*\(/', $source);
    }

    public function testItAddsTheColumnsToPopulatedTablesAndCanRunAgain(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('workflow_tasks', 'lost_lease_count'));
        $this->assertFalse(Schema::hasColumn('workflow_tasks', 'last_lease_lost_at'));
        $this->assertFalse(Schema::hasColumn('workflow_run_summaries', 'task_attention'));

        $ids = [];
        foreach (range(1, 5) as $number) {
            $ids[] = $id = (string) Str::ulid();
            DB::table('workflow_tasks')->insert([
                'id' => $id,
                'workflow_run_id' => (string) Str::ulid(),
                'task_type' => 'workflow',
                'status' => 'ready',
                'attempt_count' => 0,
                'repair_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $migration->up();
        // An interrupted or repeated run skips work already done.
        $migration->up();

        $this->assertTrue(Schema::hasColumn('workflow_tasks', 'lost_lease_count'));
        $this->assertTrue(Schema::hasColumn('workflow_tasks', 'last_lease_lost_at'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'task_attention'));
        $this->assertSame(
            5,
            DB::table('workflow_tasks')
                ->whereIn('id', $ids)
                ->where('lost_lease_count', 0)
                ->whereNull('last_lease_lost_at')
                ->count(),
        );
    }

    private function migration(): WorkflowMigration
    {
        return require self::path();
    }

    private static function path(): string
    {
        return dirname(__DIR__, 3) . '/src/migrations/' . self::NAME . '.php';
    }
}
