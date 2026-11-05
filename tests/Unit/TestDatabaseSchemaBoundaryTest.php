<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Depends;
use Tests\TestCase;

final class TestDatabaseSchemaBoundaryTest extends TestCase
{
    public function testFrameworkAndWorkflowTablesPrimeTheTruncationCache(): void
    {
        foreach (['users', 'jobs', 'workflow_instances', 'workflow_runs'] as $table) {
            self::assertTrue(Schema::hasTable($table), "Expected [{$table}] to be migrated.");
        }
    }

    #[Depends('testFrameworkAndWorkflowTablesPrimeTheTruncationCache')]
    public function testAnotherMigrationScopeCanLeaveOnlyWorkflowTables(): void
    {
        // A standalone RefreshDatabase application can recreate the physical
        // database with package migrations but without framework migrations.
        Schema::drop('users');
        Schema::drop('jobs');
        DB::table('workflow_instances')->insert([
            'id' => 'schema-boundary-probe',
            'workflow_class' => self::class,
            'workflow_type' => 'test.schema-boundary',
            'run_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertFalse(Schema::hasTable('users'));
        self::assertFalse(Schema::hasTable('jobs'));
        self::assertSame(1, DB::table('workflow_instances')->count());
    }

    #[Depends('testAnotherMigrationScopeCanLeaveOnlyWorkflowTables')]
    public function testNextTestRestoresTheWholeSchemaAndClearsCommittedRows(): void
    {
        foreach (['users', 'jobs', 'workflow_instances', 'workflow_runs'] as $table) {
            self::assertTrue(Schema::hasTable($table), "Expected [{$table}] to be restored.");
            self::assertSame(0, DB::table($table)->count(), "Expected [{$table}] to be empty.");
        }
        self::assertGreaterThan(0, DB::table('migrations')->count());
    }
}
