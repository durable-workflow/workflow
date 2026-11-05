<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\SchemaTestCase;
use Workflow\V2\Exceptions\RuntimeProfileRefusal;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\RuntimeProfileSchema;

/** Native SQL Server controls; other drivers cannot qualify this dialect. */
#[RequiresPhpExtension('pdo_sqlsrv')]
final class RuntimeProfileSqlServerBackendTest extends SchemaTestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'sqlsrv') {
            $this->markTestSkipped('Requires the separately admitted native SQL Server profile.');
        }
        parent::setUp();
    }

    #[DataProvider('canonicalProfiles')]
    public function testNullAndCanonicalProfilesRemainDistinctBeforeObservers(string $profile): void
    {
        $legacy = $this->row(null);
        $future = $this->row($profile);
        DB::table('workflow_runs')->insert([$legacy, $future]);
        $observed = 0;
        WorkflowRun::retrieved(static function () use (&$observed): void {
            $observed++;
        });
        $this->assertSame($legacy['id'], WorkflowRun::query()->findOrFail($legacy['id'])->id);
        $this->assertSame(1, $observed);
        try {
            WorkflowRun::query()->findOrFail($future['id']);
            $this->fail('Canonical shape never makes a non-NULL profile supported.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_unsupported', $error->reason);
        }
        $this->assertSame(1, $observed);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function canonicalProfiles(): iterable
    {
        yield 'one ASCII byte' => ['x'];
        yield '64 ASCII bytes' => [str_repeat('x', 64)];
        yield 'canonical punctuation and digits' => ['future.v1-2_a'];
    }

    public function testMixedHydrationBatchRefusesBeforeEveryObserver(): void
    {
        $legacy = $this->row(null);
        $future = $this->row('future');
        DB::table('workflow_runs')->insert([$legacy, $future]);
        $before = $this->durableRows();
        $observed = 0;
        WorkflowRun::retrieved(static function () use (&$observed): void {
            $observed++;
        });
        try {
            WorkflowRun::query()->select(['id', 'workflow_instance_id', 'namespace'])
                ->whereIn('id', [$legacy['id'], $future['id']])->orderBy('id')->get();
            $this->fail('No observer may see any part of a refused mixed batch.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_unsupported', $error->reason);
        }
        $this->assertSame(0, $observed);
        $this->assertSame($before, $this->durableRows());
    }

    #[DataProvider('invalidInputs')]
    public function testSentinelAndOrdinalValidationRefuseWholeStatements(bool $warnings, string $profile): void
    {
        $before = $this->durableRows();
        $prior = (int) DB::selectOne("SELECT SESSIONPROPERTY('ANSI_WARNINGS') AS value")->value;
        DB::statement('SET ANSI_WARNINGS ' . ($warnings ? 'ON' : 'OFF'));
        try {
            DB::table('workflow_runs')->insert([$this->row(null), $this->row($profile)]);
            $this->fail('No member of a mixed invalid statement may be retained.');
        } catch (QueryException $error) {
            if ($warnings && strlen($profile) > 65 && preg_match('/\Ax+\z/', $profile) === 1) {
                $this->assertContains((int) $error->errorInfo[1], [8152, 2628]);
            } else {
                $this->assertStringContainsString('runtime_profile_immutable', $error->getMessage());
            }
        } finally {
            DB::statement('SET ANSI_WARNINGS ' . ($prior === 1 ? 'ON' : 'OFF'));
        }
        $this->assertSame($before, $this->durableRows());
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function invalidInputs(): iterable
    {
        foreach ([false, true] as $warnings) {
            foreach ([
                '65 ASCII' => str_repeat('x', 65),
                '66 ASCII' => str_repeat('x', 66),
                'large ASCII' => str_repeat('x', 4096),
                'NUL' => "future\0",
                'non-ASCII' => 'futüré',
                'trailing space' => 'future ',
                'uppercase' => 'Future',
                'empty' => '',
            ] as $name => $profile) {
                yield $name . ($warnings ? ' warnings ON' : ' warnings OFF') => [$warnings, $profile];
            }
        }
    }

    public function testSurrogateCodeUnitRefusesWithoutDriverTextConversion(): void
    {
        $before = $this->durableRows();
        $row = $this->row(null);
        $grammar = DB::connection()->getQueryGrammar();
        $columns = implode(', ', array_map($grammar->wrap(...), array_keys($row)));
        $values = array_fill(0, count($row), '?');
        $values[array_search('runtime_profile', array_keys($row), true)] = 'CONVERT(nvarchar(1), 0x00D8)';
        unset($row['runtime_profile']);
        try {
            DB::statement(
                'INSERT INTO workflow_runs (' . $columns . ') VALUES (' . implode(', ', $values) . ')',
                array_values($row)
            );
            $this->fail('An unpaired UTF-16 surrogate is not an ASCII token.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('runtime_profile_immutable', $error->getMessage());
        }
        $this->assertSame($before, $this->durableRows());
    }

    #[DataProvider('protectedMutations')]
    public function testProtectedMutationRefusesAndPreservesDependents(string $operation): void
    {
        $protected = $this->row('future');
        $legacy = $this->row(null);
        DB::table('workflow_runs')->insert([$protected, $legacy]);
        DB::table('workflow_tasks')->insert([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $protected['id'],
            'namespace' => $protected['namespace'],
            'task_type' => 'workflow',
            'status' => 'ready',
            'payload' => '{}',
        ]);
        $before = $this->durableRows();
        try {
            $query = DB::table('workflow_runs')->where('id', $protected['id']);
            match ($operation) {
                'delete' => $query->delete(),
                'primary key' => $query->update([
                    'id' => (string) Str::ulid(),
                ]),
                'owner' => $query->update([
                    'workflow_instance_id' => 'moved-owner',
                ]),
                'namespace' => $query->update([
                    'namespace' => 'moved-namespace',
                ]),
                'profile' => $query->update([
                    'runtime_profile' => null,
                ]),
                'no-op' => $query->update([
                    'status' => $protected['status'],
                ]),
                'unrelated' => $query->update([
                    'status' => 'running',
                ]),
                'mixed delete' => DB::table('workflow_runs')->whereIn(
                    'id',
                    [$protected['id'], $legacy['id']]
                )->delete(),
                'mixed update' => DB::table('workflow_runs')->whereIn('id', [$protected['id'], $legacy['id']])->update([
                    'status' => 'running',
                ]),
                default => throw new \LogicException('Unknown SQL Server fixture operation.'),
            };
            $this->fail('No protected-row mutation may be applied.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('runtime_profile_immutable', $error->getMessage());
        }
        $this->assertSame($before, $this->durableRows());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function protectedMutations(): iterable
    {
        foreach ([
            'delete',
            'primary key',
            'owner',
            'namespace',
            'profile',
            'no-op',
            'unrelated',
            'mixed delete',
            'mixed update',
        ] as $operation) {
            yield $operation => [$operation];
        }
    }

    public function testLegacyPurgeDoesNotClaimPostPurgeIdentityRetention(): void
    {
        $legacy = $this->row(null);
        DB::table('workflow_runs')->insert($legacy);
        $before = $this->durableRows();
        try {
            DB::table('workflow_runs')->where('id', $legacy['id'])->update([
                'runtime_profile' => 'future',
            ]);
            $this->fail('A retained legacy row cannot be converted.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('runtime_profile_immutable', $error->getMessage());
        }
        $this->assertSame($before, $this->durableRows());
        $this->assertSame(1, DB::table('workflow_runs')->where('id', $legacy['id'])->delete());
        // Synthetic raw reuse after a lawful NULL purge is outside the
        // retained-row guarantee; it still cannot become supported work.
        $legacy['runtime_profile'] = 'future';
        DB::table('workflow_runs')->insert($legacy);
        try {
            WorkflowRun::query()->findOrFail($legacy['id']);
            $this->fail('A synthetic unsupported row remains refused after a legacy purge.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_unsupported', $error->reason);
        }
    }

    #[DataProvider('mergeActions')]
    public function testMixedMergeActionsRefuseTheCompleteStatement(string $action): void
    {
        $protected = $this->row('future');
        $new = $this->row(null);
        DB::table('workflow_runs')->insert($protected);
        $before = $this->durableRows();
        $matched = $action === 'update' ? 'UPDATE SET status = incoming.status' : 'DELETE';
        try {
            DB::statement(
                'MERGE workflow_runs AS target
                USING (VALUES (?, ?, ?), (?, ?, ?)) AS incoming(id, workflow_instance_id, status)
                ON target.id = incoming.id
                WHEN MATCHED THEN ' . $matched . '
                WHEN NOT MATCHED THEN INSERT
                    (id, workflow_instance_id, run_number, workflow_class, workflow_type, namespace, status, runtime_profile)
                    VALUES (incoming.id, incoming.workflow_instance_id, 1,
                        \'sql-profile-fixture\', \'sql-profile-fixture\', \'profile-sql\', incoming.status, NULL);',
                [
                    $protected['id'],
                    $protected['workflow_instance_id'],
                    'running',
                    $new['id'],
                    $new['workflow_instance_id'],
                    'waiting',
                ]
            );
            $this->fail('A mixed MERGE cannot retain an insertion or protected mutation.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('runtime_profile_immutable', $error->getMessage());
        }
        $this->assertSame($before, $this->durableRows());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mergeActions(): iterable
    {
        yield 'protected update and new insert' => ['update'];
        yield 'protected delete and new insert' => ['delete'];
    }

    public function testExplicitPreTruncationIsAnHonestTableBoundary(): void
    {
        $row = $this->row(null);
        $grammar = DB::connection()->getQueryGrammar();
        $columns = implode(', ', array_map($grammar->wrap(...), array_keys($row)));
        $values = array_fill(0, count($row), '?');
        $values[array_search('runtime_profile', array_keys($row), true)] = '@profile';
        unset($row['runtime_profile']);
        DB::statement('DECLARE @profile nvarchar(64) = REPLICATE(N\'x\', 65);
            INSERT INTO workflow_runs (' . $columns . ') VALUES (' . implode(', ', $values) . ')', array_values($row));
        $this->assertSame(
            str_repeat('x', 64),
            DB::table('workflow_runs')->where('id', $row['id'])->value('runtime_profile')
        );
        try {
            WorkflowRun::query()->findOrFail($row['id']);
            $this->fail('A pre-truncated canonical shape still is not a supported profile.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_unsupported', $error->reason);
        }
    }

    public function testExactInstalledFloorIsIdempotentAndDownRefusesBeforeDdl(): void
    {
        RuntimeProfileSchema::up(null);
        $before = $this->durableRows();
        $catalog = $this->catalog();
        $ddl = [];
        DB::listen(static function ($event) use (&$ddl): void {
            if (preg_match('/\A\s*(?:create|alter|drop|rename|truncate)\b/i', $event->sql)) {
                $ddl[] = $event->sql;
            }
        });
        try {
            RuntimeProfileSchema::down(null);
            $this->fail('Automatic SQL Server down has no admitted atomic plan.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_storage', $error->reason);
        }
        $this->assertSame([], $ddl);
        $this->assertSame($before, $this->durableRows());
        $this->assertEquals($catalog, $this->catalog());
    }

    public function testConfiguredSchemaPrefixAndConnectionRemainAuthoritative(): void
    {
        $schema = 'runtime_profile_fixture';
        $settings = config('database.connections.' . config('database.default'));
        $settings['prefix'] = 'rp_';
        config()
            ->set('database.connections.runtime_profile_sql_alias', $settings);
        $alias = DB::connection('runtime_profile_sql_alias');
        $grammar = $alias->getQueryGrammar();
        $table = $grammar->wrapTable($schema . '.runs');
        DB::unprepared('CREATE SCHEMA ' . $grammar->wrap($schema));
        try {
            $alias->unprepared('SELECT TOP (0) * INTO ' . $table . ' FROM '
                . DB::connection()->getQueryGrammar()->wrapTable('workflow_runs'));
            $alias->unprepared('ALTER TABLE ' . $table . ' ADD PRIMARY KEY (id)');
            config()
                ->set('workflows.v2.run_model', SqlServerConfiguredProfileRun::class);
            RuntimeProfileSchema::up(null);
            $legacy = $this->row(null);
            $protected = $this->row('future');
            $alias->table($schema . '.runs')->insert([$legacy, $protected]);
            $observed = 0;
            SqlServerConfiguredProfileRun::retrieved(static function () use (&$observed): void {
                $observed++;
            });
            $this->assertSame($legacy['id'], SqlServerConfiguredProfileRun::query()->findOrFail($legacy['id'])->id);
            $this->assertSame(1, $observed);
            try {
                SqlServerConfiguredProfileRun::query()->findOrFail($protected['id']);
                $this->fail('Configured storage must refuse before observers.');
            } catch (RuntimeProfileRefusal $error) {
                $this->assertSame('runtime_profile_unsupported', $error->reason);
            }
            $this->assertSame(1, $observed);
            $this->assertSame(0, DB::table('workflow_runs')->whereIn('id', [$legacy['id'], $protected['id']])->count());
        } finally {
            config()->set('workflows.v2.run_model', WorkflowRun::class);
            $alias->unprepared('DROP TABLE IF EXISTS ' . $table);
            DB::unprepared('DROP SCHEMA ' . $grammar->wrap($schema));
            DB::purge('runtime_profile_sql_alias');
        }
    }

    public function testPreexistingSentinelValueRefusesBeforeInstallingAnyGuard(): void
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $schema = (string) DB::scalar(
            'SELECT OBJECT_SCHEMA_NAME(OBJECT_ID(?))',
            [$grammar->wrapTable('workflow_runs')]
        );
        $base = 'wf_runtime_profile_' . substr(hash('sha256', $connection->getTablePrefix() . 'workflow_runs'), 0, 20);
        foreach (['', '_insert', '_delete'] as $suffix) {
            DB::unprepared('DROP TRIGGER ' . $grammar->wrap($schema . '.' . $base . $suffix));
        }
        DB::table('workflow_runs')->insert($this->row(str_repeat('x', 65)));
        $catalog = $this->catalog();
        $rows = $this->durableRows();
        $ddl = [];
        DB::listen(static function ($event) use (&$ddl): void {
            if (preg_match('/\A\s*(?:create|alter|drop|rename|truncate)\b/i', $event->sql)) {
                $ddl[] = $event->sql;
            }
        });
        try {
            RuntimeProfileSchema::up(null);
            $this->fail('An invalid pre-existing value cannot adopt a guard floor.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_storage', $error->reason);
        }
        $this->assertSame([], $ddl);
        $this->assertEquals($catalog, $this->catalog());
        $this->assertSame($rows, $this->durableRows());
    }

    #[DataProvider('duplicateStatements')]
    public function testDuplicateInsertRefusesWithoutChangingAnyRow(bool $mixed): void
    {
        $protected = $this->row('future');
        DB::table('workflow_runs')->insert($protected);
        $duplicate = $protected;
        $duplicate['runtime_profile'] = null;
        $before = $this->durableRows();
        try {
            DB::table('workflow_runs')->insert($mixed ? [$this->row(null), $duplicate] : $duplicate);
            $this->fail('The native unique constraint must refuse the entire duplicate statement.');
        } catch (QueryException $error) {
            $this->assertContains((int) $error->errorInfo[1], [2601, 2627]);
        }
        $this->assertSame($before, $this->durableRows());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function duplicateStatements(): iterable
    {
        yield 'single duplicate' => [false];
        yield 'mixed fresh and duplicate' => [true];
    }

    #[DataProvider('wrongObjects')]
    public function testWrongCatalogShapeRefusesBeforeAnyDdl(string $kind): void
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable('workflow_runs');
        $schema = (string) DB::scalar('SELECT OBJECT_SCHEMA_NAME(OBJECT_ID(?))', [$table]);
        $base = 'wf_runtime_profile_' . substr(hash('sha256', $connection->getTablePrefix() . 'workflow_runs'), 0, 20);
        $trigger = $grammar->wrap($schema . '.' . $base);
        $index = $grammar->wrap(
            'wf_rp_idx_' . substr(hash('sha256', $connection->getTablePrefix() . 'workflow_runs'), 0, 20)
        );
        if (in_array($kind, ['column length', 'column type', 'not nullable'], true)) {
            DB::unprepared('DROP INDEX ' . $index . ' ON ' . $table);
            DB::unprepared('ALTER TABLE ' . $table . ' ALTER COLUMN runtime_profile '
                . match ($kind) {
                    'column length' => 'nvarchar(64) NULL',
                    'column type' => 'varchar(65) NULL',
                    default => 'nvarchar(65) NOT NULL',
                });
        } elseif ($kind === 'default') {
            DB::unprepared(
                'ALTER TABLE ' . $table . ' ADD CONSTRAINT guard_fixture_default DEFAULT (NULL) FOR runtime_profile'
            );
        } elseif ($kind === 'disabled index') {
            DB::unprepared('ALTER INDEX ' . $index . ' ON ' . $table . ' DISABLE');
        } elseif (in_array($kind, ['wrong index', 'included index', 'filtered index'], true)) {
            DB::unprepared('DROP INDEX ' . $index . ' ON ' . $table);
            $columns = $kind === 'wrong index' ? 'namespace' : 'runtime_profile';
            $extra = match ($kind) {
                'included index' => ' INCLUDE (namespace)',
                'filtered index' => ' WHERE runtime_profile IS NOT NULL',
                default => '',
            };
            DB::unprepared('CREATE INDEX ' . $index . ' ON ' . $table . ' (' . $columns . ')' . $extra);
        } elseif ($kind === 'ignore duplicate primary key') {
            $primary = (string) DB::scalar(
                'SELECT name FROM sys.indexes WHERE object_id = OBJECT_ID(?) AND is_primary_key = 1',
                [$table]
            );
            DB::unprepared(
                'ALTER INDEX ' . $grammar->wrap($primary) . ' ON ' . $table . ' REBUILD WITH (IGNORE_DUP_KEY = ON)'
            );
        } elseif ($kind === 'disabled trigger') {
            DB::unprepared('DISABLE TRIGGER ' . $trigger . ' ON ' . $table);
        } elseif (str_starts_with($kind, 'missing ')) {
            $suffix = match ($kind) {
                'missing insert' => '_insert',
                'missing delete' => '_delete',
                default => '',
            };
            DB::unprepared('DROP TRIGGER ' . $grammar->wrap($schema . '.' . $base . $suffix));
        } else {
            DB::unprepared('DROP TRIGGER ' . $trigger);
            if ($kind === 'same-name procedure') {
                DB::unprepared('CREATE PROCEDURE ' . $trigger . ' AS SELECT 1');
            } else {
                $target = $kind === 'wrong parent' ? $grammar->wrapTable('workflow_instances') : $table;
                $events = $kind === 'multiple events' ? 'UPDATE, DELETE' : 'UPDATE';
                DB::unprepared(
                    'CREATE TRIGGER ' . $trigger . ' ON ' . $target . ' AFTER ' . $events . ' AS BEGIN SET NOCOUNT ON; END'
                );
            }
        }
        $catalog = $this->catalog();
        $rows = $this->durableRows();
        $ddl = [];
        DB::listen(static function ($event) use (&$ddl): void {
            if (preg_match('/\A\s*(?:create|alter|drop|rename|truncate|disable|enable)\b/i', $event->sql)) {
                $ddl[] = $event->sql;
            }
        });
        foreach (['up', 'down'] as $direction) {
            try {
                RuntimeProfileSchema::{$direction}(null);
                $this->fail('A stale catalog cannot authorize migration ' . $direction . '.');
            } catch (RuntimeProfileRefusal $error) {
                $this->assertSame('runtime_profile_storage', $error->reason);
            }
            $this->assertSame([], $ddl);
            $this->assertEquals($catalog, $this->catalog());
            $this->assertSame($rows, $this->durableRows());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrongObjects(): iterable
    {
        foreach (['column length', 'column type', 'not nullable', 'default', 'disabled index', 'wrong index',
            'included index', 'filtered index', 'ignore duplicate primary key', 'disabled trigger',
            'wrong body', 'wrong parent', 'multiple events', 'same-name procedure',
            'missing insert', 'missing update', 'missing delete'] as $kind) {
            yield $kind => [$kind];
        }
    }

    /**
     * @return array<string, list<object>>
     */
    private function catalog(): array
    {
        $table = DB::connection()->getQueryGrammar()->wrapTable('workflow_runs');
        return [
            'columns' => DB::select(
                'SELECT * FROM sys.columns WHERE object_id = OBJECT_ID(?) ORDER BY column_id',
                [$table]
            ),
            'indexes' => DB::select(
                'SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(?) ORDER BY index_id',
                [$table]
            ),
            'index columns' => DB::select(
                'SELECT * FROM sys.index_columns WHERE object_id = OBJECT_ID(?) ORDER BY index_id, index_column_id',
                [$table]
            ),
            'guard objects' => DB::select("SELECT o.*, m.definition, m.uses_ansi_nulls, m.uses_quoted_identifier,
                tr.parent_id AS trigger_parent, tr.is_disabled AS trigger_disabled, tr.is_instead_of_trigger,
                tr.is_not_for_replication FROM sys.objects o
                LEFT JOIN sys.sql_modules m ON m.object_id = o.object_id
                LEFT JOIN sys.triggers tr ON tr.object_id = o.object_id
                WHERE o.name LIKE 'wf_runtime_profile_%' OR o.parent_object_id = OBJECT_ID(?) ORDER BY o.object_id", [
                $table,
            ]),
            'events' => DB::select("SELECT e.* FROM sys.trigger_events e JOIN sys.objects o ON o.object_id = e.object_id
                WHERE o.name LIKE 'wf_runtime_profile_%' ORDER BY e.object_id, e.type", []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(?string $profile): array
    {
        $id = (string) Str::ulid();
        return [
            'id' => $id,
            'workflow_instance_id' => 'sql-profile-' . $id,
            'run_number' => 1,
            'workflow_class' => 'sql-profile-fixture',
            'workflow_type' => 'sql-profile-fixture',
            'namespace' => 'profile-sql',
            'status' => 'waiting',
            'runtime_profile' => $profile,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function durableRows(): array
    {
        $rows = [];
        foreach (DB::getSchemaBuilder()->getTableListing() as $table) {
            $values = DB::table($table)->get()->map(
                static fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR)
            )->all();
            sort($values);
            $rows[$table] = $values;
        }
        ksort($rows);
        return $rows;
    }
}

final class SqlServerConfiguredProfileRun extends WorkflowRun
{
    protected $table = 'runtime_profile_fixture.runs';

    protected $connection = 'runtime_profile_sql_alias';
}
