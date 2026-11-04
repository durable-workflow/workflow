<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;
use Tests\Support\RuntimeProfileRetainedIdentityControls;
use Workflow\V2\Exceptions\RuntimeProfileRefusal;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\PortableCancellationScopeDelivery;
use Workflow\V2\Support\RuntimeProfileSchema;

final class RuntimeProfileStorageBackendTest extends SchemaTestCase
{
    use RuntimeProfileRetainedIdentityControls {
        retainedProfileConflicts as private allRetainedProfileConflicts;
    }

    protected function tearDown(): void
    {
        config()->set('workflows.v2.run_model', WorkflowRun::class);
        DB::purge('profile_guard_prefix');
        DB::purge('profile_guard_alias');
        parent::tearDown();
    }

    public static function retainedProfileConflicts(): iterable
    {
        $driver = getenv('DB_CONNECTION') ?: '';
        foreach (self::allRetainedProfileConflicts() as $name => $case) {
            if (! in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true) || $case[2] === $driver) {
                yield $name => $case;
            }
        }
    }

    public function testNullOnlyAutomaticDownIsAtomicOrRefusesBeforeAnyDdl(): void
    {
        $driver = DB::connection()->getDriverName();
        $before = $this->objectDefinitions();
        $ddl = [];
        DB::listen(static function ($event) use (&$ddl): void {
            if (preg_match('/\A\s*(?:create|alter|drop|rename|truncate)\b/i', $event->sql)) {
                $ddl[] = $event->sql;
            }
        });
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            try {
                RuntimeProfileSchema::down(null);
                $this->fail('Nontransactional automatic down must refuse.');
            } catch (RuntimeProfileRefusal $error) {
                $this->assertSame('runtime_profile_storage', $error->reason);
            }
            $this->assertSame([], $ddl);
            $this->assertEquals($before, $this->objectDefinitions());
            return;
        }
        RuntimeProfileSchema::down(null);
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('workflow_runs', 'runtime_profile'));
        RuntimeProfileSchema::up(null);
        $this->assertEquals($before, $this->objectDefinitions());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wrongObjectKinds')]
    public function testWrongBackendObjectIsRefusedWithoutAnyDdl(string $kind): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (($kind === 'function' && $driver !== 'pgsql') || ($kind === 'column' && $driver === 'sqlite')) {
            $this->markTestSkipped('This exact object shape is driver-specific.');
        }
        $grammar = $connection->getQueryGrammar();
        $name = 'wf_runtime_profile_' . substr(hash('sha256', $connection->getTablePrefix() . 'workflow_runs'), 0, 20);
        $quoted = $grammar->wrap($name);
        if ($kind === 'index') {
            $index = 'wf_rp_idx_' . substr(hash('sha256', $connection->getTablePrefix() . 'workflow_runs'), 0, 20);
            DB::getSchemaBuilder()->table('workflow_runs', static function (Blueprint $table) use ($index): void {
                $table->dropIndex($index);
            });
            DB::getSchemaBuilder()->table('workflow_runs', static function (Blueprint $table) use ($index): void {
                $table->index('namespace', $index);
            });
        } elseif ($kind === 'column') {
            DB::getSchemaBuilder()->table('workflow_runs', static function (Blueprint $table): void {
                $table->string('runtime_profile', 32)
                    ->nullable()
                    ->change();
            });
        } elseif ($kind === 'function') {
            DB::unprepared('CREATE OR REPLACE FUNCTION ' . $quoted
                . '() RETURNS trigger AS $$ BEGIN RETURN NEW; END; $$ LANGUAGE plpgsql');
        } else {
            $table = $kind === 'table' ? 'workflow_instances' : 'workflow_runs';
            if ($driver === 'pgsql') {
                DB::unprepared('DROP TRIGGER ' . $quoted . ' ON workflow_runs');
                // Same table/event name and function signature are not enough.
                if ($kind === 'trigger') {
                    DB::unprepared('CREATE TRIGGER ' . $quoted . ' AFTER UPDATE ON ' . $table
                        . ' FOR EACH ROW EXECUTE FUNCTION ' . $quoted . '()');
                } else {
                    DB::unprepared('CREATE TRIGGER ' . $quoted . ' BEFORE UPDATE ON ' . $table
                        . ' FOR EACH ROW EXECUTE FUNCTION ' . $quoted . '()');
                }
            } else {
                DB::unprepared('DROP TRIGGER ' . $quoted);
                DB::unprepared('CREATE TRIGGER ' . $quoted . ' BEFORE UPDATE ON ' . $table . ' '
                    . ($driver === 'sqlite' ? 'BEGIN SELECT 1; END' : 'FOR EACH ROW BEGIN SET @guard_probe = 1; END'));
            }
        }
        $before = $this->objectDefinitions();
        $ddl = [];
        DB::listen(static function ($event) use (&$ddl): void {
            if (preg_match('/\A\s*(?:create|alter|drop|rename|truncate)\b/i', $event->sql)) {
                $ddl[] = $event->sql;
            }
        });
        try {
            RuntimeProfileSchema::up(null);
            $this->fail('Object names must not authorize stale definitions.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_storage', $error->reason);
        }
        $this->assertSame([], $ddl);
        $this->assertEquals($before, $this->objectDefinitions());
    }

    public static function wrongObjectKinds(): iterable
    {
        foreach (['trigger', 'table', 'index', 'column', 'function'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('postgresDownFailureSteps')]
    public function testPostgresDownRestoresExactObjectsAfterAnInjectedFailure(string $step): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Transactional PostgreSQL DDL control.');
        }
        $before = $this->objectDefinitions();
        $armed = true;
        $injected = false;
        DB::listen(static function ($event) use ($step, &$armed, &$injected): void {
            if ($armed && str_contains(strtolower($event->sql), $step)) {
                $armed = false;
                $injected = true;
                throw new \RuntimeException('injected-guard-ddl-failure');
            }
        });
        try {
            RuntimeProfileSchema::down(null);
            $this->fail('The selected DDL failure was not injected.');
        } catch (\RuntimeException $error) {
            $this->assertSame('injected-guard-ddl-failure', $error->getMessage());
        } finally {
            $armed = false;
        }
        $this->assertTrue($injected);
        $this->assertEquals($before, $this->objectDefinitions());
    }

    public static function postgresDownFailureSteps(): iterable
    {
        foreach (['drop trigger', 'drop function', 'drop index', 'drop column'] as $step) {
            yield $step => [$step];
        }
    }

    public function testDistinctServerConnectionAliasesCannotAuthorizeDestructiveDeduplication(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite proven file aliases have a separate positive control.');
        }
        config()
            ->set('database.connections.profile_guard_alias', config('database.connections.' . config('database.default')));
        config()
            ->set('workflows.v2.run_model', RuntimeProfileAliasRun::class);
        $this->assertNotSame(DB::connection(), DB::connection('profile_guard_alias'));
        $before = $this->objectDefinitions();
        RuntimeProfileSchema::up(null);
        $this->assertEquals($before, $this->objectDefinitions());
        $ddl = [];
        DB::listen(static function ($event) use (&$ddl): void {
            if (preg_match('/\A\s*(?:create|alter|drop|rename|truncate)\b/i', $event->sql)) {
                $ddl[] = $event->sql;
            }
        });
        try {
            RuntimeProfileSchema::down(null);
            $this->fail('Unproven alias equivalence cannot authorize destructive down.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_storage', $error->reason);
        }
        $this->assertSame([], $ddl);
        $this->assertEquals($before, $this->objectDefinitions());
    }

    public function testLegacySourceCannotReplaceProtectedAuthorityOrItsDependents(): void
    {
        $future = $this->identityRow('future');
        $legacy = $this->identityRow(null);
        $taskId = (string) \Illuminate\Support\Str::ulid();
        DB::table('workflow_tasks')->insert([
            'id' => $taskId,
            'workflow_run_id' => $future['id'],
            'namespace' => $future['namespace'],
            'task_type' => 'workflow',
            'status' => 'ready',
            'payload' => '{}',
        ]);
        DB::table('workflow_history_events')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'workflow_run_id' => $future['id'],
            'workflow_task_id' => $taskId,
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => '{}',
            'recorded_at' => now()
                ->format('Y-m-d H:i:s.u'),
        ]);
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA recursive_triggers = OFF');
        }
        $before = $this->durableRows();
        try {
            $verb = DB::connection()->getDriverName() === 'sqlite' ? 'UPDATE OR REPLACE' : 'UPDATE';
            DB::update($verb . ' workflow_runs SET id = ? WHERE id = ?', [$future['id'], $legacy['id']]);
            $this->fail('Legacy update conflict cannot remove a protected target.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('runtime_profile_immutable', $error->getMessage());
        }
        $this->assertSame($before, $this->durableRows());
        try {
            DB::table('workflow_runs')->where('id', $future['id'])->update([
                'id' => 'moved-key',
            ]);
            $this->fail('The retained key and its dependents must remain unchanged.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('runtime_profile_immutable', $error->getMessage());
        }
        $this->assertSame($before, $this->durableRows());
        $replacement = $future;
        $replacement['runtime_profile'] = null;
        try {
            DB::table('workflow_runs')->insert($replacement);
            $this->fail('The original protected key cannot be recreated as legacy.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('runtime_profile_immutable', $error->getMessage());
        }
        $this->assertSame($before, $this->durableRows());
    }

    public static function qualifiedTableForms(): iterable
    {
        yield 'unqualified' => [false];
        yield 'schema qualified' => [true];
    }

    public function testInvalidNamespaceCannotHideAProtectedProfileBeforeTaskObservers(): void
    {
        $run = $this->identityRow('future');
        $taskId = (string) \Illuminate\Support\Str::ulid();
        DB::table('workflow_tasks')->insert([
            'id' => $taskId,
            'workflow_run_id' => $run['id'],
            'namespace' => 'different-namespace',
            'task_type' => 'workflow',
            'status' => 'ready',
            'payload' => '{}',
        ]);
        $retrieved = 0;
        WorkflowTask::retrieved(static function () use (&$retrieved): void {
            ++$retrieved;
        });
        $before = $this->durableRows();
        try {
            PortableCancellationScopeDelivery::mutate(
                true,
                $taskId,
                'worker',
                1,
                'root',
                'request',
                1,
                'activity',
                1,
                null,
                1,
                '1.20'
            );
            $this->fail('Invalid claim binding must not hide an unsupported profile.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_unsupported', $error->reason);
        }
        $this->assertSame(0, $retrieved);
        $this->assertSame($before, $this->durableRows());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('qualifiedTableForms')]
    public function testConfiguredPrefixedAuthorityIsGuardedAndAmbiguousDownIsNonDestructive(bool $qualified): void
    {
        $settings = config('database.connections.' . config('database.default'));
        $settings['prefix'] = 'rp_guard_';
        config()
            ->set('database.connections.profile_guard_prefix', $settings);
        $storage = DB::connection('profile_guard_prefix');
        foreach (['runs', 'workflow_runs'] as $name) {
            $storage->getSchemaBuilder()
                ->create($name, static function (Blueprint $table): void {
                    $table->string('id')
                        ->primary();
                    $table->string('workflow_instance_id');
                    $table->string('namespace')
                        ->nullable();
                });
        }
        config()
            ->set(
                'workflows.v2.run_model',
                $qualified ? RuntimeProfileQualifiedPrefixedRun::class : RuntimeProfilePrefixedRun::class
            );
        RuntimeProfileSchema::up(null);
        // Repeating up must inspect the actual unprefixed backend metadata.
        RuntimeProfileSchema::up(null);
        $storage->table('runs')
            ->insert([
                'id' => 'prefix-future',
                'workflow_instance_id' => 'prefix-owner',
                'namespace' => 'prefix-tests',
                'runtime_profile' => 'future',
            ]);
        $before = $storage->table('runs')
            ->get()
            ->toArray();
        foreach (['id', 'workflow_instance_id', 'namespace', 'runtime_profile'] as $column) {
            try {
                $storage->table('runs')
                    ->where('id', 'prefix-future')
                    ->update([
                        $column => 'other',
                    ]);
                $this->fail('Configured protected authority cannot move.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('runtime_profile_immutable', $error->getMessage());
            }
            $this->assertEquals($before, $storage->table('runs')->get()->toArray());
        }
        try {
            RuntimeProfileSchema::down(null);
            $this->fail('A protected or independent target must prevent automatic down.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_storage', $error->reason);
        }
        $this->assertTrue($storage->getSchemaBuilder()->hasColumn('runs', 'runtime_profile'));
        $this->assertEquals($before, $storage->table('runs')->get()->toArray());
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('workflow_runs', 'runtime_profile'));
    }

    /**
     * @return array<string, mixed>
     */
    private function objectDefinitions(): array
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            return [
                'objects' => $connection->select(
                    'SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name'
                ),
            ];
        }
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $grammar = $connection->getQueryGrammar();
            $objects = [];
            foreach ($connection->getSchemaBuilder()->getTableListing() as $table) {
                $objects['table:' . $table] = $connection->select('SHOW CREATE TABLE ' . $grammar->wrapTable($table));
            }
            foreach ($connection->select(
                'SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME',
                [$connection->getDatabaseName()]
            ) as $trigger) {
                $objects['trigger:' . $trigger->name] = $connection->select(
                    'SHOW CREATE TRIGGER ' . $grammar->wrap($trigger->name)
                );
            }
            ksort($objects);
            return $objects;
        }
        return [
            'columns' => $connection->select(
                'SELECT table_name, column_name, data_type, character_maximum_length, is_nullable, column_default, is_generated FROM information_schema.columns WHERE table_schema = current_schema() ORDER BY table_name, ordinal_position'
            ),
            'indexes' => $connection->select(
                'SELECT c.relname, pg_get_indexdef(i.indexrelid) AS definition FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() ORDER BY c.relname'
            ),
            'triggers' => $connection->select(
                'SELECT c.relname, t.tgname, pg_get_triggerdef(t.oid) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND NOT t.tgisinternal ORDER BY c.relname, t.tgname'
            ),
            'functions' => $connection->select(
                "SELECT p.proname, pg_get_functiondef(p.oid) AS definition FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = current_schema() AND p.proname LIKE 'wf_runtime_profile_%' ORDER BY p.proname"
            ),
        ];
    }
}

final class RuntimeProfilePrefixedRun extends WorkflowRun
{
    protected $table = 'runs';

    protected $connection = 'profile_guard_prefix';
}

final class RuntimeProfileAliasRun extends WorkflowRun
{
    protected $connection = 'profile_guard_alias';
}

final class RuntimeProfileQualifiedPrefixedRun extends WorkflowRun
{
    protected $connection = 'profile_guard_prefix';

    public function getTable()
    {
        $connection = $this->getConnection();
        $schema = match ($connection->getDriverName()) {
            'sqlite' => 'main',
            'pgsql' => $connection->selectOne('SELECT current_schema() AS name')
->name,
            default => $connection->getDatabaseName(),
        };
        return $schema . '.runs';
    }
}
