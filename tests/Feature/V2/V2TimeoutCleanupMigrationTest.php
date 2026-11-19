<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\SchemaTestCase;
use Workflow\Support\WorkflowMigration;

/**
 * The timeout cleanup work-deadline column (#863) is added without a backfill
 * or a table-locking rebuild: nullable, outside the migrator transaction, the
 * column under the schema lock budget and the index built online.
 */
final class V2TimeoutCleanupMigrationTest extends SchemaTestCase
{
    private const NAME = '2026_11_13_000100_add_timeout_cleanup_work_deadline_to_workflow_runs';

    public function testItRunsOutsideOneTransactionAndOnlyUsesTheOnlineSchemaHelpers(): void
    {
        $migration = $this->migration();
        $this->assertFalse($migration->withinTransaction);

        $source = (string) file_get_contents(self::path());
        $this->assertStringContainsString('withSchemaLockBudget', $source);
        $this->assertStringContainsString('createIndexOnline', $source);
        // No backfill: existing runs never opted in, so nothing is rewritten.
        $this->assertDoesNotMatchRegularExpression('/->\s*(update|insert|upsert|delete)\s*\(/', $source);
    }

    public function testItAddsANullableIndexedColumnToAPopulatedTableAndCanRunAgain(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('workflow_runs', 'timeout_cleanup_work_deadline_at'));

        $ids = [];
        foreach (range(1, 5) as $number) {
            $ids[] = $id = (string) Str::ulid();
            DB::table('workflow_runs')->insert([
                'id' => $id,
                'workflow_instance_id' => 'migration-instance-' . $number,
                'run_number' => 1,
                'workflow_class' => 'App\\Workflows\\Example',
                'workflow_type' => 'example',
                'status' => 'running',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $migration->up();
        // An interrupted or repeated run skips work already done.
        $migration->up();

        $this->assertTrue(Schema::hasColumn('workflow_runs', 'timeout_cleanup_work_deadline_at'));
        $this->assertTrue(Schema::hasIndex('workflow_runs', 'workflow_runs_timeout_cleanup_work_deadline_idx'));
        $this->assertSame(
            5,
            DB::table('workflow_runs')->whereIn('id', $ids)->whereNull('timeout_cleanup_work_deadline_at')->count(),
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
