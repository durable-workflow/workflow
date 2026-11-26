<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\SchemaTestCase;
use Workflow\Support\WorkflowMigration;

/**
 * The run summary's timeout cleanup column (#863) is added to the hot summary
 * table without a backfill or a table-locking rebuild: nullable, outside the
 * migrator transaction, under the schema lock budget, and repeatable.
 */
final class V2TimeoutCleanupSummaryMigrationTest extends SchemaTestCase
{
    private const NAME = '2026_11_26_000100_add_timeout_cleanup_to_workflow_run_summaries';

    public function testItRunsOutsideOneTransactionUnderTheSchemaLockBudget(): void
    {
        $migration = $this->migration();
        $this->assertFalse($migration->withinTransaction);

        $source = (string) file_get_contents(self::path());
        $this->assertSame(1, substr_count($source, 'withSchemaLockBudget'));
        // No backfill: a summary gains the value when its run is projected.
        $this->assertDoesNotMatchRegularExpression('/->\s*(update|insert|upsert|delete)\s*\(/', $source);
    }

    public function testItAddsANullableColumnToAPopulatedTableAndCanRunAgain(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('workflow_run_summaries', 'timeout_cleanup'));

        $ids = [];
        foreach (range(1, 5) as $number) {
            $ids[] = $id = (string) Str::ulid();
            DB::table('workflow_run_summaries')->insert([
                'id' => $id,
                'workflow_instance_id' => 'migration-instance-' . $number,
                'run_number' => 1,
                'class' => 'App\\Workflows\\Example',
                'workflow_type' => 'example',
                'status' => 'running',
                'status_bucket' => 'running',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $migration->up();
        // An interrupted or repeated run skips work already done.
        $migration->up();

        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'timeout_cleanup'));
        $this->assertSame(
            5,
            DB::table('workflow_run_summaries')
                ->whereIn('id', $ids)
                ->whereNull('timeout_cleanup')
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
