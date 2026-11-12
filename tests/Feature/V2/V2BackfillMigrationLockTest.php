<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DateTimeImmutable;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\SchemaTestCase;
use Workflow\Serializers\Avro;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;

/**
 * Upgrade migrations that rewrite existing rows must not keep a schema lock
 * while they do it (#871). Laravel wraps each migration in one transaction on
 * PostgreSQL and SQLite, so an ALTER TABLE followed by a backfill in the same
 * migration would block every reader and writer of the table until the whole
 * backfill commits.
 */
final class V2BackfillMigrationLockTest extends SchemaTestCase
{
    /**
     * Migrations that rewrite existing rows. Each must commit its schema change
     * before the backfill and commit the backfill in bounded batches.
     */
    private const BACKFILLING = [
        '2026_07_13_000100_add_output_payload_codec_to_workflow_runs',
        '2026_09_24_000100_encode_legacy_local_activity_exceptions',
        '2026_09_29_000100_index_schedule_occurrences',
        '2026_10_11_000100_add_original_service_call_deadlines',
    ];

    /**
     * Row-rewriting migrations that deliberately stay in one transaction.
     */
    private const EXEMPT = [
        // Takes no schema lock and only touches the small schedule control
        // table. Converting a timestamp twice would shift it twice, so the
        // whole rewrite must commit or roll back as one unit.
        '2026_09_26_000100_normalize_workflow_schedule_timestamps',
    ];

    private const ROWS = 5;

    private const BATCH = 2;

    private ?object $interruption = null;

    public function testEveryRowRewritingMigrationIsInventoried(): void
    {
        $rewriting = [];

        foreach (glob(dirname(__DIR__, 3) . '/src/migrations/*.php') ?: [] as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);

            if (preg_match('/->\s*(update|insert|upsert|delete)\s*\(/', $contents) === 1) {
                $rewriting[] = basename($file, '.php');
            }
        }

        $expected = [...self::BACKFILLING, ...self::EXEMPT];
        sort($expected);

        $this->assertSame(
            $expected,
            $rewriting,
            'A migration that rewrites rows must be listed here and must not hold a schema lock across its backfill.',
        );
    }

    public function testBackfillingMigrationsOptOutOfTheMigratorTransaction(): void
    {
        foreach (self::BACKFILLING as $name) {
            $migration = $this->migration($name);

            $this->assertFalse(
                $migration->withinTransaction,
                $name . ' must not run inside one migration transaction.'
            );
            $this->assertIsInt($migration->backfillBatchSize ?? null, $name . ' must backfill in bounded batches.');
        }
    }

    public function testExemptMigrationsTakeNoSchemaLock(): void
    {
        foreach (self::EXEMPT as $name) {
            $contents = file_get_contents(self::path($name));
            $this->assertIsString($contents);

            foreach (['Schema::table', 'Schema::create', 'Blueprint', 'ALTER TABLE', 'CREATE INDEX'] as $schemaChange) {
                $this->assertStringNotContainsStringIgnoringCase($schemaChange, $contents, $name);
            }
        }
    }

    #[DataProvider('backfillingMigrations')]
    public function testMigratorCommitsSchemaChangesBeforeBatchedBackfill(string $name): void
    {
        $fixture = $this->prepare($name);
        $statements = $this->recordStatements();

        $this->app['migration.repository']->delete((object) [
            'migration' => $name,
        ]);
        $this->artisan('migrate', [
            '--path' => self::path($name),
            '--realpath' => true,
        ])->assertSuccessful()
            ->run();
        $this->app[Kernel::class]->setArtisan(null);
        $this->app->offsetUnset(OutputStyle::class);

        $ddl = array_values(array_filter($statements->all, static fn (array $statement): bool => $statement['ddl']));
        $updates = array_values(array_filter(
            $statements->all,
            static fn (array $statement): bool => $statement['update'] === $fixture['table'],
        ));

        if ($fixture['ddl']) {
            $this->assertNotEmpty($ddl, $name . ' should have changed the schema.');
        }
        $this->assertCount(self::ROWS, $updates, $name . ' should have backfilled every fixture row.');

        foreach ($ddl as $statement) {
            $this->assertSame(0, $statement['level'], $name . ' ran DDL inside a transaction: ' . $statement['sql']);
        }

        foreach ($updates as $statement) {
            // Level 1 is the backfill's own batch transaction. Level 2 would
            // mean the whole migration, DDL included, is one transaction.
            $this->assertSame(1, $statement['level'], $name . ' backfilled outside a bounded batch transaction.');
        }

        $this->assertSame($fixture['expected'], $this->values($fixture));
    }

    #[DataProvider('backfillingMigrations')]
    public function testInterruptedBackfillResumesAndRepeatsToTheSameData(string $name): void
    {
        $fixture = $this->prepare($name);
        $migration = $this->migration($name);
        $migration->backfillBatchSize = self::BATCH;

        // Fail on the first row of the second batch.
        $this->failOnUpdate($fixture['table'], self::BATCH + 1);

        try {
            $migration->up();
            $this->fail($name . ' should have been interrupted.');
        } catch (RuntimeException $interruption) {
            $this->assertSame('Simulated interruption.', $interruption->getMessage());
        }
        $this->failOnUpdate($fixture['table'], null);

        // The schema change and the first batch are committed; the failed
        // batch rolled back.
        $this->assertTrue(Schema::hasColumn($fixture['table'], $fixture['column']));
        $partial = $this->values($fixture);
        $done = array_intersect_assoc($partial, $fixture['expected']);
        $this->assertCount(self::BATCH, $done, $name . ' should keep exactly the committed batch.');
        $this->assertSame(
            array_diff_key($fixture['before'], $done),
            array_diff_key($partial, $done),
            $name . ' rolled back the interrupted batch.',
        );

        $migration->up();
        $this->assertSame($fixture['expected'], $this->values($fixture));
        $snapshot = $this->snapshot($fixture['table']);

        $migration->up();
        $this->assertSame($snapshot, $this->snapshot($fixture['table']));
        $this->assertSame($fixture['expected'], $this->values($fixture));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function backfillingMigrations(): iterable
    {
        foreach (self::BACKFILLING as $name) {
            yield $name => [$name];
        }
    }

    /**
     * Seed legacy rows with the migration rolled back, so up() has work to do.
     *
     * @return array{table: string, column: string, ddl: bool, before: array<string, mixed>, expected: array<string, mixed>}
     */
    private function prepare(string $name): array
    {
        return match ($name) {
            '2026_07_13_000100_add_output_payload_codec_to_workflow_runs' => $this->prepareOutputCodecs($name),
            '2026_09_24_000100_encode_legacy_local_activity_exceptions' => $this->prepareLocalActivityExceptions(),
            '2026_09_29_000100_index_schedule_occurrences' => $this->prepareScheduleOccurrences($name),
            '2026_10_11_000100_add_original_service_call_deadlines' => $this->prepareServiceCallDeadlines($name),
        };
    }

    /**
     * @return array{table: string, column: string, ddl: bool, before: array<string, mixed>, expected: array<string, mixed>}
     */
    private function prepareOutputCodecs(string $name): array
    {
        $this->migration($name)
            ->down();
        $now = now();
        $before = [];
        $expected = [];

        for ($i = 1; $i <= self::ROWS; $i++) {
            $runId = sprintf('01J000000000000000000%05d', $i);
            DB::table('workflow_instances')->insert([
                'id' => 'backfill-instance-' . $i,
                'workflow_class' => 'Tests\\Fixtures\\MigrationWorkflow',
                'workflow_type' => 'migration.output-codec',
                'run_count' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('workflow_runs')->insert([
                'id' => $runId,
                'workflow_instance_id' => 'backfill-instance-' . $i,
                'run_number' => 1,
                'workflow_class' => 'Tests\\Fixtures\\MigrationWorkflow',
                'workflow_type' => 'migration.output-codec',
                'status' => 'completed',
                'closed_reason' => 'completed',
                'payload_codec' => 'avro',
                'output' => 'inline-output',
                'last_history_sequence' => 2,
                'last_command_sequence' => 0,
                'message_cursor_position' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // The newest completion with a codec wins, as before.
            foreach ([
                1 => 'older-codec',
                2 => ' codec-' . $i . ' ',
            ] as $sequence => $codec) {
                DB::table('workflow_history_events')->insert([
                    'id' => (string) Str::ulid(),
                    'workflow_run_id' => $runId,
                    'sequence' => $sequence,
                    'event_type' => 'WorkflowCompleted',
                    'payload' => json_encode([
                        'output' => 'inline-output',
                        'payload_codec' => $codec,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $before[$runId] = null;
            $expected[$runId] = 'codec-' . $i;
        }

        return [
            'table' => 'workflow_runs',
            'column' => 'output_payload_codec',
            'ddl' => true,
            'before' => $before,
            'expected' => $expected,
        ];
    }

    /**
     * @return array{table: string, column: string, ddl: bool, before: array<string, mixed>, expected: array<string, mixed>}
     */
    private function prepareLocalActivityExceptions(): array
    {
        $now = now();
        $before = [];
        $expected = [];

        for ($i = 1; $i <= self::ROWS; $i++) {
            $id = sprintf('01J000000000000000000%05d', $i);
            $message = 'Legacy local activity failure ' . $i . '.';
            DB::table('activity_executions')->insert([
                'id' => $id,
                'workflow_run_id' => '01J0000000000000000000RUN1',
                'sequence' => $i,
                'activity_class' => 'Tests\\Fixtures\\LegacyActivity',
                'activity_type' => 'legacy-local-activity',
                'status' => 'failed',
                'payload_codec' => 'avro',
                'exception' => $message,
                'activity_options' => json_encode([
                    'execution_mode' => 'local',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $before[$id] = $message;
            $expected[$id] = Avro::serialize($message);
        }

        return [
            'table' => 'activity_executions',
            'column' => 'exception',
            'ddl' => false,
            'before' => $before,
            'expected' => $expected,
        ];
    }

    /**
     * @return array{table: string, column: string, ddl: bool, before: array<string, mixed>, expected: array<string, mixed>}
     */
    private function prepareScheduleOccurrences(string $name): array
    {
        $this->migration($name)
            ->down();
        $before = [];
        $expected = [];

        for ($i = 1; $i <= self::ROWS; $i++) {
            $id = sprintf('01J000000000000000000%05d', $i);
            $occurrence = sprintf('2026-04-14T0%d:00:00+03:00', $i);
            DB::table('workflow_schedule_history_events')->insert([
                'id' => $id,
                'workflow_schedule_id' => '01J0000000000000000SCHED01',
                'schedule_id' => 'legacy-occurrences',
                'sequence' => $i,
                'event_type' => 'ScheduleTriggered',
                'payload' => json_encode([
                    'occurrence_time' => $occurrence,
                ], JSON_THROW_ON_ERROR),
            ]);
            $before[$id] = null;
            $expected[$id] = WorkflowScheduleHistoryEvent::utcOccurrenceKey(new DateTimeImmutable($occurrence));
        }

        return [
            'table' => 'workflow_schedule_history_events',
            'column' => 'occurrence_at_utc',
            'ddl' => true,
            'before' => $before,
            'expected' => $expected,
        ];
    }

    /**
     * @return array{table: string, column: string, ddl: bool, before: array<string, mixed>, expected: array<string, mixed>}
     */
    private function prepareServiceCallDeadlines(string $name): array
    {
        $this->migration($name)
            ->down();
        $now = now();
        $before = [];
        $expected = [];

        for ($i = 1; $i <= self::ROWS; $i++) {
            $id = sprintf('01J000000000000000000%05d', $i);
            DB::table('workflow_service_calls')->insert([
                'id' => $id,
                'endpoint_name' => 'billing',
                'service_name' => 'invoices',
                'operation_name' => 'create',
                'status' => 'accepted',
                'operation_mode' => 'sync',
                'accepted_at' => '2026-10-01 10:00:00.123456',
                'deadline_policy' => json_encode([
                    'timeout_seconds' => $i,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $before[$id] = null;
            $expected[$id] = sprintf('2026-10-01 10:00:%02d.123456', $i);
        }

        return [
            'table' => 'workflow_service_calls',
            'column' => 'call_deadline_at',
            'ddl' => true,
            'before' => $before,
            'expected' => $expected,
        ];
    }

    /**
     * @param array{table: string, column: string} $fixture
     * @return array<string, mixed>
     */
    private function values(array $fixture): array
    {
        $values = DB::table($fixture['table'])
            ->orderBy('id')
            ->pluck($fixture['column'], 'id')
            ->all();

        if ($fixture['column'] === 'call_deadline_at') {
            // Drivers differ in how they print datetime(6) values.
            $values = array_map(
                static fn ($value) => $value === null ? null : (new DateTimeImmutable((string) $value))->format(
                    'Y-m-d H:i:s.u'
                ),
                $values,
            );
        }

        return $values;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshot(string $table): array
    {
        return DB::table($table)
            ->orderBy('id')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    private function recordStatements(): object
    {
        $log = new class() {
            /**
             * @var list<array{sql: string, level: int, ddl: bool, update: ?string}>
             */
            public array $all = [];
        };

        DB::listen(static function (QueryExecuted $query) use ($log): void {
            $sql = ltrim($query->sql);
            $log->all[] = [
                'sql' => $sql,
                'level' => $query->connection->transactionLevel(),
                'ddl' => preg_match('/^(alter|create|drop)\s/i', $sql) === 1,
                'update' => preg_match('/^update\s+[`"]?([\w]+)[`"]?/i', $sql, $match) === 1
                    ? substr($match[1], strlen($query->connection->getTablePrefix()))
                    : null,
            ];
        });

        return $log;
    }

    private function failOnUpdate(string $table, ?int $failAt): void
    {
        if ($this->interruption === null) {
            $interruption = new class() {
                public ?int $failAt = null;

                public string $table = '';

                public int $seen = 0;
            };
            $this->interruption = $interruption;

            DB::listen(static function (QueryExecuted $query) use ($interruption): void {
                if ($interruption->failAt === null) {
                    return;
                }

                $table = $query->connection->getTablePrefix() . $interruption->table;
                if (preg_match('/^\s*update\s+[`"]?' . preg_quote($table, '/') . '[`"]?\s/i', $query->sql) !== 1) {
                    return;
                }

                if (++$interruption->seen === $interruption->failAt) {
                    throw new RuntimeException('Simulated interruption.');
                }
            });
        }

        $this->interruption->table = $table;
        $this->interruption->failAt = $failAt;
        $this->interruption->seen = 0;
    }

    private function migration(string $name): WorkflowMigration
    {
        return require self::path($name);
    }

    private static function path(string $name): string
    {
        return dirname(__DIR__, 3) . '/src/migrations/' . $name . '.php';
    }
}
