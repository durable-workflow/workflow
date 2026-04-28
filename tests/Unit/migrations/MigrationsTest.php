<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRunTimerEntry;

final class MigrationsTest extends TestCase
{
    public function testV2MigrationSetDoesNotShipPreviewBackfillMigrations(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/src/migrations/2026_04_*.php') ?: [];

        $this->assertNotEmpty($files);

        $backfillFiles = array_values(array_filter(
            array_map('basename', $files),
            static fn (string $file): bool => str_contains($file, 'backfill'),
        ));

        $this->assertSame(
            [],
            $backfillFiles,
            'Unreleased v2 migrations must not ship preview-era backfill migrations.',
        );
    }

    public function testV2MigrationSlateIsCreateTableOnly(): void
    {
        $files = array_map('basename', glob(dirname(__DIR__, 3) . '/src/migrations/2026_04_*.php') ?: []);

        $this->assertNotEmpty($files);

        $nonCreateFiles = array_values(array_filter(
            $files,
            static fn (string $file): bool => ! str_contains($file, '_create_') || ! str_ends_with($file, '_table.php'),
        ));

        $this->assertSame(
            [],
            $nonCreateFiles,
            'Unreleased v2 migrations must be final-form create-table migrations only.',
        );
    }

    public function testV2MigrationSlateDoesNotUseSchemaDetectionGuards(): void
    {
        $violations = [];

        foreach (glob(dirname(__DIR__, 3) . '/src/migrations/2026_04_*.php') ?: [] as $file) {
            $contents = file_get_contents($file);

            foreach (['Schema::hasTable', 'Schema::hasColumn', 'Schema::hasColumns', 'Schema::table'] as $guard) {
                if (str_contains($contents, $guard)) {
                    $violations[] = basename($file) . ' contains ' . $guard;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Final v2 migrations must not contain write-on-read schema detection or ALTER-style table patches.',
        );
    }

    /**
     * Mirrors `App\Support\MigrationAdoption::createdTablesIn()` in the
     * `durableworkflow/server` package. That class drives BLK-S002 recovery
     * by scanning each pending package migration's `Schema::create()` target
     * and skipping migrations whose tables already exist on the connection.
     * It recognizes only two source patterns:
     *
     *   - `Schema::create('table_name', ...)` — string literal target
     *   - `Schema::create(self::CONST, ...)` — where `const CONST = 'string';`
     *     is declared in the same file as a string literal
     *
     * If a future package migration's `Schema::create()` call falls outside
     * those two patterns (string concatenation, helper method call,
     * `parent::CONST`, dynamic name from config), the server's parser
     * silently resolves to an empty list, the adoption pass skips the
     * migration, and a partial-bootstrap retry against an already-created
     * table re-surfaces the BLK-S002 "table already exists" failure. This
     * contract test fails closed when that drift would happen, so the
     * server-side recovery cannot regress without a workflow-side signal.
     */
    public function testPackageMigrationCreateTablesAreDetectableByServerAdoptionPatterns(): void
    {
        $migrationFiles = array_merge(
            glob(dirname(__DIR__, 3) . '/src/migrations/2022_*.php') ?: [],
            glob(dirname(__DIR__, 3) . '/src/migrations/2026_04_*.php') ?: [],
        );
        sort($migrationFiles);

        $this->assertNotEmpty($migrationFiles);

        $undetectable = [];
        $totalDetected = 0;

        foreach ($migrationFiles as $file) {
            $contents = file_get_contents($file);

            $this->assertIsString($contents);

            $createCount = preg_match_all('/Schema::create\s*\(/', $contents);

            if ($createCount === 0) {
                continue;
            }

            $constants = [];

            if (preg_match_all("/\\bconst\\s+(\\w+)\\s*=\\s*['\"]([^'\"]+)['\"]/", $contents, $constMatches)) {
                $constants = array_combine($constMatches[1], $constMatches[2]);
            }

            $detected = [];

            if (preg_match_all(
                "/Schema::create\\(\\s*(?:['\"]([^'\"]+)['\"]|self::(\\w+))/",
                $contents,
                $createMatches,
            )) {
                foreach ($createMatches[1] as $i => $literal) {
                    if ($literal !== '') {
                        $detected[] = $literal;

                        continue;
                    }

                    $constName = $createMatches[2][$i] ?? '';

                    if ($constName !== '' && isset($constants[$constName])) {
                        $detected[] = $constants[$constName];
                    }
                }
            }

            if (count($detected) !== $createCount) {
                $undetectable[] = sprintf(
                    '%s: %d Schema::create() call(s) but %d recognized by adoption regex',
                    basename($file),
                    $createCount,
                    count($detected),
                );
            }

            $totalDetected += count($detected);
        }

        $this->assertSame(
            [],
            $undetectable,
            "Every package migration's Schema::create() target must match one of the two patterns "
            . 'recognized by App\\Support\\MigrationAdoption::createdTablesIn() in the standalone server: '
            . "Schema::create('literal', ...) or Schema::create(self::CONST, ...) "
            . "with `const CONST = 'string';` declared in the same file. "
            . 'A miss silently regresses BLK-S002 partial-bootstrap recovery.',
        );

        $this->assertGreaterThan(0, $totalDetected);
    }

    public function testDownMethodsDropTables(): void
    {
        $this->assertTrue(Schema::hasTable('workflows'));
        $this->assertTrue(Schema::hasTable('workflow_logs'));
        $this->assertTrue(Schema::hasTable('workflow_signals'));
        $this->assertTrue(Schema::hasTable('workflow_timers'));
        $this->assertTrue(Schema::hasTable('workflow_exceptions'));
        $this->assertTrue(Schema::hasTable('workflow_relationships'));
        $this->assertTrue(Schema::hasTable('workflow_commands'));
        $this->assertTrue(Schema::hasTable('workflow_links'));
        $this->assertTrue(Schema::hasTable('activity_attempts'));
        $this->assertTrue(Schema::hasTable('workflow_signal_records'));
        $this->assertTrue(Schema::hasTable('workflow_run_timeline_entries'));
        $this->assertTrue(Schema::hasTable('workflow_run_timer_entries'));
        $this->assertTrue(Schema::hasTable('workflow_run_lineage_entries'));
        $this->assertTrue(Schema::hasColumn('activity_executions', 'current_attempt_id'));
        $this->assertTrue(Schema::hasColumn('workflow_history_events', 'workflow_command_id'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'sort_timestamp'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'sort_key'));
        $this->assertTrue(Schema::hasColumn('workflow_run_timer_entries', 'schema_version'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'open_wait_id'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'resume_source_kind'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'resume_source_id'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'repair_blocked_reason'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'repair_attention'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'task_problem'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'declared_entry_mode'));
        $this->assertTrue(Schema::hasColumn('workflow_run_summaries', 'declared_contract_source'));
        $this->assertFalse(Schema::hasColumn('workflow_run_summaries', 'memo'));
        $this->assertFalse(Schema::hasColumn('workflow_run_summaries', 'search_attributes'));
        $this->assertTrue(Schema::hasColumn('workflow_links', 'sequence'));
        $this->assertTrue(Schema::hasColumn('workflow_tasks', 'last_dispatch_attempt_at'));
        $this->assertTrue(Schema::hasColumn('workflow_tasks', 'last_dispatch_error'));
        $this->assertTrue(Schema::hasColumn('workflow_tasks', 'last_claim_failed_at'));
        $this->assertTrue(Schema::hasColumn('workflow_tasks', 'last_claim_error'));
        $this->assertTrue(Schema::hasColumn('workflow_commands', 'requested_workflow_run_id'));
        $this->assertTrue(Schema::hasColumn('workflow_commands', 'resolved_workflow_run_id'));
        $this->assertTrue(Schema::hasColumn('activity_executions', 'parallel_group_path'));
        $this->assertTrue(Schema::hasColumn('workflow_links', 'parallel_group_path'));
        $this->assertTrue(Schema::hasColumn('workflow_instances', 'memo'));
        $this->assertTrue(Schema::hasColumn('workflow_runs', 'memo'));

        $this->artisan('migrate:reset', [
            '--path' => dirname(__DIR__, 3) . '/src/migrations',
            '--realpath' => true,
        ])->run();

        $this->assertFalse(Schema::hasTable('workflows'));
        $this->assertFalse(Schema::hasTable('workflow_logs'));
        $this->assertFalse(Schema::hasTable('workflow_signals'));
        $this->assertFalse(Schema::hasTable('workflow_timers'));
        $this->assertFalse(Schema::hasTable('workflow_exceptions'));
        $this->assertFalse(Schema::hasTable('workflow_relationships'));
        $this->assertFalse(Schema::hasTable('workflow_commands'));
        $this->assertFalse(Schema::hasTable('workflow_links'));
        $this->assertFalse(Schema::hasTable('activity_attempts'));
        $this->assertFalse(Schema::hasTable('workflow_signal_records'));
        $this->assertFalse(Schema::hasTable('workflow_run_timeline_entries'));
        $this->assertFalse(Schema::hasTable('workflow_run_timer_entries'));
        $this->assertFalse(Schema::hasTable('workflow_run_lineage_entries'));
    }

    /**
     * @return list<array{string}>
     */
    public static function sqliteRollbackCommandProvider(): array
    {
        return [['migrate:rollback'], ['migrate:reset']];
    }

    /**
     * @dataProvider sqliteRollbackCommandProvider
     */
    public function testSqlitePackageMigrationsSupportRollbackCommands(string $command): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'workflow-sqlite-migrations-');

        $this->assertIsString($databasePath);

        $connection = 'workflow_test_sqlite';
        config()
            ->set(
                "database.connections.{$connection}",
                [
                    'driver' => 'sqlite',
                    'database' => $databasePath,
                    'prefix' => '',
                    'foreign_key_constraints' => true,
                ],
            );

        try {
            $this->artisan('migrate:fresh', [
                '--database' => $connection,
                '--path' => dirname(__DIR__, 3) . '/src/migrations',
                '--realpath' => true,
            ])->assertExitCode(0);

            $this->assertSqliteWorkflowTablesExist($connection);

            $this->artisan($command, [
                '--database' => $connection,
                '--path' => dirname(__DIR__, 3) . '/src/migrations',
                '--realpath' => true,
            ])->assertExitCode(0);

            $this->assertSqliteWorkflowTablesMissing($connection);
        } finally {
            if (is_file($databasePath)) {
                @unlink($databasePath);
            }
        }
    }

    public function testTimerProjectionRowsDefaultToCurrentSchemaVersion(): void
    {
        /** @var WorkflowRunTimerEntry $entry */
        $entry = WorkflowRunTimerEntry::query()->create([
            'id' => 'migration-default-timer-schema',
            'workflow_run_id' => 'migration-default-run',
            'workflow_instance_id' => 'migration-default-instance',
            'timer_id' => 'migration-default-timer',
            'position' => 0,
            'status' => 'pending',
        ]);

        $this->assertSame(WorkflowRunTimerEntry::CURRENT_SCHEMA_VERSION, $entry->refresh()->schema_version);
        $this->assertTrue($entry->usesCurrentSchema());
    }

    public function testRunSummaryWorkflowInstanceIdSupportsServerWorkflowIds(): void
    {
        $summaryLength = $this->stringColumnLength('workflow_run_summaries', 'workflow_instance_id');
        $runLength = $this->stringColumnLength('workflow_runs', 'workflow_instance_id');

        $this->assertSame($runLength, $summaryLength);
        $this->assertGreaterThanOrEqual(128, $summaryLength);
    }

    public function testTimelineTimerIdMatchesTimerProjectionLength(): void
    {
        $timelineLength = $this->stringColumnLength('workflow_run_timeline_entries', 'timer_id');
        $timerProjectionLength = $this->stringColumnLength('workflow_run_timer_entries', 'timer_id');

        $this->assertSame($timerProjectionLength, $timelineLength);
        $this->assertGreaterThanOrEqual(128, $timelineLength);
    }

    private function stringColumnLength(string $table, string $column): int
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->markTestSkipped('SQLite does not expose string column length metadata.');
        }

        foreach (Schema::getColumns($table) as $definition) {
            if (($definition['name'] ?? null) !== $column) {
                continue;
            }

            if (preg_match('/\((\d+)\)/', (string) ($definition['type'] ?? ''), $matches) === 1) {
                return (int) $matches[1];
            }
        }

        $this->fail("Unable to determine {$table}.{$column} length for {$driver}.");
    }

    private function assertSqliteWorkflowTablesExist(string $connection): void
    {
        foreach (self::sqliteWorkflowTables() as $table) {
            $this->assertTrue(
                Schema::connection($connection)->hasTable($table),
                "Expected SQLite table [{$table}] to exist.",
            );
        }
    }

    private function assertSqliteWorkflowTablesMissing(string $connection): void
    {
        foreach (self::sqliteWorkflowTables() as $table) {
            $this->assertFalse(
                Schema::connection($connection)->hasTable($table),
                "Expected SQLite table [{$table}] to be dropped.",
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function sqliteWorkflowTables(): array
    {
        return [
            'workflows',
            'workflow_logs',
            'workflow_signals',
            'workflow_timers',
            'workflow_exceptions',
            'workflow_relationships',
            'workflow_instances',
            'workflow_runs',
            'workflow_run_timers',
            'workflow_tasks',
            'activity_executions',
            'workflow_failures',
            'workflow_run_summaries',
            'workflow_history_events',
            'workflow_commands',
            'workflow_links',
            'activity_attempts',
            'workflow_worker_compatibility_heartbeats',
            'workflow_updates',
            'workflow_signal_records',
            'workflow_run_waits',
            'workflow_run_timeline_entries',
            'workflow_run_lineage_entries',
            'workflow_run_timer_entries',
            'workflow_schedules',
            'workflow_messages',
            'workflow_memos',
            'workflow_search_attributes',
            'workflow_child_calls',
            'workflow_schedule_history_events',
        ];
    }
}
