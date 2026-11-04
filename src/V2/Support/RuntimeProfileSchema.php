<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\V2\Exceptions\RuntimeProfileRefusal;
use Workflow\V2\Models\WorkflowRun;

/** @internal Guard-only storage. This class never admits a non-NULL profile. */
final class RuntimeProfileSchema
{
    public static function up(?string $migrationConnection): void
    {
        $targets = self::targets($migrationConnection);
        // Validate every pre-existing object before changing any target.
        foreach ($targets as [$connection, $table]) {
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeProfileRefusal('runtime_profile_storage');
            }
            self::primaryKey($connection, $table);
            self::assertColumn($connection, $table, allowMissing: true);
            self::hasIndex($connection, $table);
            $present = array_map(
                static fn (string $operation): bool => self::hasImmutability($connection, $table, $operation),
                ['update', 'insert', 'delete']
            );
            if (count(array_filter($present)) !== 0 && count(array_filter($present)) !== 3) {
                throw new RuntimeProfileRefusal('runtime_profile_storage');
            }
        }
        foreach ($targets as [$connection, $table]) {
            $schema = $connection->getSchemaBuilder();
            if (! $schema->hasTable($table)) {
                throw new RuntimeProfileRefusal('runtime_profile_storage');
            }
            if (! $schema->hasColumn($table, 'runtime_profile')) {
                $schema->table($table, static function (Blueprint $blueprint): void {
                    $blueprint->string('runtime_profile', 64)
                        ->nullable();
                });
            }
            if (! self::hasIndex($connection, $table)) {
                $index = self::indexName($connection, $table);
                $schema->table($table, static function (Blueprint $blueprint) use ($index): void {
                    $blueprint->index('runtime_profile', $index);
                });
            }
            foreach (['update', 'insert', 'delete'] as $operation) {
                if (! self::hasImmutability($connection, $table, $operation)) {
                    if ($connection->getDriverName() === 'pgsql') {
                        $connection->unprepared(self::functionSql($connection, $table, $operation));
                    }
                    $connection->unprepared(self::triggerSql($connection, $table, $operation));
                }
            }
        }
    }

    public static function down(?string $migrationConnection): void
    {
        $targets = self::targets($migrationConnection);
        $plans = [];
        try {
            foreach ($targets as [$connection, $table]) {
                if (! $connection->getSchemaBuilder()->hasTable($table)) {
                    throw new RuntimeProfileRefusal('runtime_profile_storage');
                }
                self::assertColumn($connection, $table);
                if (! self::hasIndex($connection, $table)
                    || ! self::hasImmutability($connection, $table, 'update')
                    || ! self::hasImmutability($connection, $table, 'insert')
                    || ! self::hasImmutability($connection, $table, 'delete')
                    || $connection->table($table)
                        ->useWritePdo()
                        ->whereNotNull('runtime_profile')
                        ->exists()) {
                    throw new RuntimeProfileRefusal('runtime_profile_storage');
                }
                $plans[] = [$connection, $table];
            }
        } catch (RuntimeProfileRefusal $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new RuntimeProfileRefusal('runtime_profile_storage', $error);
        }
        if ($plans === []) {
            return;
        }
        // Never rely on best-effort trigger restoration after committed DDL.
        // Multiple targets and nontransactional DDL require an explicit offline
        // rollback plan. Automatic down leaves all their objects untouched.
        if (count($plans) !== 1 || ! in_array($plans[0][0]->getDriverName(), ['sqlite', 'pgsql'], true)) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }
        [$connection, $table] = $plans[0];
        $connection->transaction(static function () use ($connection, $table): void {
            if ($connection->getDriverName() === 'pgsql') {
                // Prevent a new protected INSERT between inventory and DDL.
                $connection->statement(
                    'LOCK TABLE ' . $connection->getQueryGrammar()->wrapTable($table) . ' IN ACCESS EXCLUSIVE MODE'
                );
            }
            // The immutability trigger prevents profile conversion. The complete
            // inventory is checked again in the same transaction as the DDL.
            self::assertColumn($connection, $table);
            if (! self::hasIndex($connection, $table)
                || ! self::hasImmutability($connection, $table, 'update')
                || ! self::hasImmutability($connection, $table, 'insert')
                || ! self::hasImmutability($connection, $table, 'delete')) {
                throw new RuntimeProfileRefusal('runtime_profile_storage');
            }
            if ($connection->table($table)->useWritePdo()->whereNotNull('runtime_profile')->exists()) {
                throw new RuntimeProfileRefusal('runtime_profile_storage');
            }
            foreach (['delete', 'insert', 'update'] as $operation) {
                self::dropImmutability($connection, $table, $operation);
            }
            $connection->getSchemaBuilder()
                ->table($table, static function (Blueprint $blueprint) use ($connection, $table): void {
                    $blueprint->dropIndex(self::indexName($connection, $table));
                });
            $connection->getSchemaBuilder()
                ->table($table, static function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('runtime_profile');
                });
        });
    }

    /**
     * @return list<array{Connection, string}>
     */
    private static function targets(?string $migrationConnection): array
    {
        $configuredClass = ConfiguredV2Models::resolve('run_model', WorkflowRun::class);
        $configured = new $configuredClass();
        $default = new WorkflowRun();
        $candidates = [
            [DB::connection($migrationConnection), $default->getTable()],
            [$default->getConnection(), $default->getTable()],
            [$configured->getConnection(), $configured->getTable()],
        ];
        $targets = [];
        $seen = [];
        foreach ($candidates as [$connection, $table]) {
            $identity = self::physicalIdentity($connection, $table);
            if (! isset($seen[$identity])) {
                $targets[] = [$connection, $table];
                $seen[$identity] = true;
            }
        }
        return $targets;
    }

    /**
     * Different Laravel aliases do not imply different physical storage.
     */
    private static function physicalIdentity(Connection $connection, string $table): string
    {
        [$schema, $physicalTable] = self::tableParts($connection, $table);
        $driver = $connection->getDriverName();
        if ($driver === 'sqlite') {
            return hash(
                'sha256',
                serialize([$driver, self::sqliteDatabaseIdentity($connection, $schema), $physicalTable])
            );
        }
        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }
        // Server-reported hostname/port are not unique endpoint authority,
        // especially over sockets. Keep different connections distinct unless
        // equivalence is proven. Automatic down then refuses before any DDL.
        return hash('sha256', serialize([$driver, spl_object_id($connection), $schema, $physicalTable]));
    }

    /**
     * @return array{string, string}
     */
    private static function tableParts(Connection $connection, string $table): array
    {
        $parts = explode('.', $table);
        if (count($parts) > 2) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }
        $name = $connection->getTablePrefix() . array_pop($parts);
        $schema = $parts[0] ?? match ($connection->getDriverName()) {
            'sqlite' => 'main',
            'mysql', 'mariadb' => $connection->getDatabaseName(),
            'pgsql' => (string) $connection->selectOne('SELECT current_schema() AS name', [], false)->name,
            default => throw new RuntimeProfileRefusal('runtime_profile_storage'),
        };
        return [$schema, $name];
    }

    private static function sqliteDatabaseIdentity(Connection $connection, string $schema): string
    {
        foreach ($connection->select('PRAGMA database_list', [], false) as $database) {
            if ($database->name === $schema) {
                // Empty filename denotes a private in-memory/temp database.
                return $database->file === '' ? 'memory:' . spl_object_id($connection->getPdo()) : (realpath(
                    $database->file
                ) ?: $database->file);
            }
        }
        throw new RuntimeProfileRefusal('runtime_profile_storage');
    }

    private static function assertColumn(Connection $connection, string $table, bool $allowMissing = false): void
    {
        [$schema, $name] = self::tableParts($connection, $table);
        $driver = $connection->getDriverName();
        if ($driver === 'sqlite') {
            $grammar = $connection->getQueryGrammar();
            $columns = $connection->select(
                'PRAGMA ' . $grammar->wrap($schema) . '.table_info(' . $grammar->wrap($name) . ')',
                [],
                false
            );
            $column = null;
            foreach ($columns as $candidate) {
                if ($candidate->name === 'runtime_profile') {
                    $column = $candidate;
                }
            }
            // SQLite's string grammar emits VARCHAR without an enforced length.
            $valid = $column !== null && strtolower($column->type) === 'varchar'
                && (int) $column->notnull === 0 && $column->dflt_value === null && (int) $column->pk === 0;
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $column = $connection->selectOne(
                'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$schema, $name, 'runtime_profile'],
                false
            );
            $valid = $column !== null && $column->type === 'varchar(64)' && $column->nullable === 'YES'
                && $column->default_value === null && $column->extra === '';
        } elseif ($driver === 'pgsql') {
            $column = $connection->selectOne(
                'SELECT data_type, character_maximum_length AS length, is_nullable AS nullable, column_default AS default_value, is_generated AS generated FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
                [$schema, $name, 'runtime_profile'],
                false
            );
            $valid = $column !== null && $column->data_type === 'character varying' && (int) $column->length === 64
                && $column->nullable === 'YES' && $column->default_value === null && $column->generated === 'NEVER';
        } else {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }
        if (($column === null && $allowMissing) || $valid) {
            return;
        }
        throw new RuntimeProfileRefusal('runtime_profile_storage');
    }

    private static function name(Connection $connection, string $table, string $operation = 'update'): string
    {
        $name = 'wf_runtime_profile_' . substr(hash('sha256', $connection->getTablePrefix() . $table), 0, 20);
        $name .= $operation === 'update' ? '' : '_' . $operation;
        $separator = strrpos($table, '.');
        return $separator === false ? $name : substr($table, 0, $separator + 1) . $name;
    }

    private static function indexName(Connection $connection, string $table): string
    {
        return 'wf_rp_idx_' . substr(hash('sha256', $connection->getTablePrefix() . $table), 0, 20);
    }

    private static function hasIndex(Connection $connection, string $table): bool
    {
        foreach ($connection->getSchemaBuilder()->getIndexes($table) as $index) {
            if ($index['name'] === self::indexName($connection, $table)) {
                if ($index['columns'] !== ['runtime_profile'] || $index['unique'] || $index['primary']) {
                    throw new RuntimeProfileRefusal('runtime_profile_storage');
                }
                [$schema, $physicalTable] = self::tableParts($connection, $table);
                $name = self::indexName($connection, $table);
                $valid = match ($connection->getDriverName()) {
                    'sqlite' => (int) $connection->selectOne(
                        'SELECT partial FROM pragma_index_list(?, ?) WHERE name = ?',
                        [$physicalTable, $schema, $name],
                        false
                    )->partial === 0,
                    'mysql', 'mariadb' => $connection->selectOne(
                        "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? AND (SUB_PART IS NOT NULL OR INDEX_TYPE != 'BTREE') LIMIT 1",
                        [$schema, $physicalTable, $name],
                        false,
                    ) === null,
                    'pgsql' => (bool) $connection->selectOne(
                        'SELECT (i.indpred IS NULL AND i.indexprs IS NULL AND i.indisvalid AND i.indisready) AS valid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname = ?',
                        [$schema, $name],
                        false
                    )->valid,
                    default => false,
                };
                if (! $valid) {
                    throw new RuntimeProfileRefusal('runtime_profile_storage');
                }
                return true;
            }
        }
        return false;
    }

    private static function primaryKey(Connection $connection, string $table): string
    {
        $indexes = array_values(array_filter(
            $connection->getSchemaBuilder()
                ->getIndexes($table),
            static fn (array $index): bool => $index['primary'],
        ));
        if (count($indexes) !== 1 || count($indexes[0]['columns']) !== 1
            || ! is_string($indexes[0]['columns'][0])) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }
        $key = $indexes[0]['columns'][0];
        $columns = array_column($connection->getSchemaBuilder()->getColumns($table), 'name');
        foreach ([$key, 'workflow_instance_id', 'namespace'] as $authority) {
            if (! in_array($authority, $columns, true)) {
                throw new RuntimeProfileRefusal('runtime_profile_storage');
            }
        }
        return $connection->getQueryGrammar()
            ->wrap($key);
    }

    private static function body(string $driver, string $operation, Connection $connection, string $table): string
    {
        if ($operation !== 'update') {
            $profile = $connection->getQueryGrammar()
                ->wrap('runtime_profile');
            if ($operation === 'delete') {
                $condition = 'OLD.' . $profile . ' IS NOT NULL';
            } elseif ($operation === 'insert') {
                $key = self::primaryKey($connection, $table);
                $condition = 'EXISTS (SELECT 1 FROM ' . $connection->getQueryGrammar()->wrapTable($table)
                    . ' WHERE ' . $key . ' = NEW.' . $key
                    . ' AND (' . $profile . ' IS NOT NULL OR NEW.' . $profile . ' IS NOT NULL))';
            } else {
                throw new RuntimeProfileRefusal('runtime_profile_storage');
            }
            return match ($driver) {
                'sqlite' => "FOR EACH ROW WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'runtime_profile_immutable'); END",
                'mysql', 'mariadb' => "BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'runtime_profile_immutable'; END IF; END",
                'pgsql' => "BEGIN IF {$condition} THEN RAISE EXCEPTION 'runtime_profile_immutable' USING ERRCODE = '23514'; END IF; RETURN " . ($operation === 'delete' ? 'OLD' : 'NEW') . '; END;',
                default => throw new RuntimeProfileRefusal('runtime_profile_storage'),
            };
        }
        $key = self::primaryKey($connection, $table);
        $identity = [
            $key,
            $connection->getQueryGrammar()
                ->wrap('workflow_instance_id'),
            $connection->getQueryGrammar()
                ->wrap('namespace'),
        ];
        $sqliteChanges = implode(
            ' OR ',
            array_map(
                static fn (string $column): string => "CAST(OLD.{$column} AS BLOB) IS NOT CAST(NEW.{$column} AS BLOB)",
                $identity
            )
        );
        $mysqlChanges = implode(
            ' OR ',
            array_map(
                static fn (string $column): string => "NOT (BINARY OLD.{$column} <=> BINARY NEW.{$column})",
                $identity
            )
        );
        $postgresChanges = implode(
            ' OR ',
            array_map(
                static fn (string $column): string => "convert_to(OLD.{$column}::text, 'UTF8') IS DISTINCT FROM convert_to(NEW.{$column}::text, 'UTF8')",
                $identity
            )
        );
        $protectedTarget = 'EXISTS (SELECT 1 FROM ' . $connection->getQueryGrammar()->wrapTable($table)
            . ' WHERE ' . $key . ' = NEW.' . $key . ' AND ' . $key . ' <> OLD.' . $key . ' AND runtime_profile IS NOT NULL)';
        return match ($driver) {
            'sqlite' => "FOR EACH ROW WHEN
                {$protectedTarget}
                OR CAST(OLD.\"runtime_profile\" AS BLOB) IS NOT CAST(NEW.\"runtime_profile\" AS BLOB)
                OR (OLD.\"runtime_profile\" IS NOT NULL AND ({$sqliteChanges}))
                BEGIN SELECT RAISE(ABORT, 'runtime_profile_immutable'); END",
            'mysql', 'mariadb' => "BEGIN IF {$protectedTarget} OR NOT (BINARY OLD.`runtime_profile` <=> BINARY NEW.`runtime_profile`)
                OR (OLD.`runtime_profile` IS NOT NULL AND ({$mysqlChanges})) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'runtime_profile_immutable'; END IF; END",
            'pgsql' => "BEGIN IF {$protectedTarget} OR convert_to(OLD.\"runtime_profile\", 'UTF8') IS DISTINCT FROM convert_to(NEW.\"runtime_profile\", 'UTF8')
                OR (OLD.\"runtime_profile\" IS NOT NULL AND ({$postgresChanges})) THEN
                RAISE EXCEPTION 'runtime_profile_immutable' USING ERRCODE = '23514'; END IF; RETURN NEW; END;",
            default => throw new RuntimeProfileRefusal('runtime_profile_storage'),
        };
    }

    private static function quotedName(Connection $connection, string $name): string
    {
        // Grammar::wrap on a dotted identifier prefixes its first segment as
        // a table. Generated object names need literal schema/name segments.
        return implode('.', array_map($connection->getQueryGrammar()->wrap(...), explode('.', $name)));
    }

    private static function triggerSql(Connection $connection, string $table, string $operation = 'update'): string
    {
        $grammar = $connection->getQueryGrammar();
        $name = self::quotedName($connection, self::name($connection, $table, $operation));
        $quotedTable = $grammar->wrapTable($table);
        $event = strtoupper($operation);
        $columns = '';
        return match ($connection->getDriverName()) {
            'sqlite' => 'CREATE TRIGGER ' . $name . ' BEFORE ' . $event . $columns . ' ON ' . $quotedTable . ' ' . self::body(
                'sqlite',
                $operation,
                $connection,
                $table
            ),
            'mysql', 'mariadb' => 'CREATE TRIGGER ' . $name . ' BEFORE ' . $event . ' ON ' . $quotedTable . ' FOR EACH ROW ' . self::body(
                $connection->getDriverName(),
                $operation,
                $connection,
                $table
            ),
            'pgsql' => 'CREATE TRIGGER ' . $grammar->wrap(
                self::unqualifiedName($connection, $table, $operation)
            ) . ' BEFORE ' . $event . $columns . ' ON ' . $quotedTable . ' FOR EACH ROW EXECUTE FUNCTION ' . $name . '()',
            default => throw new RuntimeProfileRefusal('runtime_profile_storage'),
        };
    }

    private static function functionSql(Connection $connection, string $table, string $operation = 'update'): string
    {
        return 'CREATE FUNCTION ' . self::quotedName($connection, self::name($connection, $table, $operation))
            . '() RETURNS trigger AS $guard$ ' . self::body(
                'pgsql',
                $operation,
                $connection,
                $table
            ) . ' $guard$ LANGUAGE plpgsql';
    }

    private static function normalize(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    private static function hasImmutability(Connection $connection, string $table, string $operation = 'update'): bool
    {
        $name = self::unqualifiedName($connection, $table, $operation);
        [$schema, $physicalTable] = self::tableParts($connection, $table);
        $grammar = $connection->getQueryGrammar();
        switch ($connection->getDriverName()) {
            case 'sqlite':
                $row = $connection->selectOne(
                    'SELECT tbl_name, sql FROM ' . $grammar->wrap($schema)
                        . '.' . $grammar->wrap('sqlite_master') . ' WHERE type = ? AND name = ?',
                    ['trigger', $name],
                    false
                );
                if ($row === null) {
                    return false;
                }
                $expected = self::triggerSql($connection, $table, $operation);
                $expected = str_replace(
                    self::quotedName($connection, self::name($connection, $table, $operation)),
                    $grammar->wrap($name),
                    $expected
                );
                $valid = $row->tbl_name === $physicalTable && self::normalize($row->sql) === self::normalize($expected);
                break;
            case 'mysql':
            case 'mariadb':
                $row = $connection->selectOne(
                    'SELECT EVENT_OBJECT_TABLE AS table_name, EVENT_MANIPULATION AS event, ACTION_TIMING AS timing, ACTION_ORIENTATION AS orientation, ACTION_STATEMENT AS body FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = ?',
                    [$schema, $name],
                    false
                );
                if ($row === null) {
                    return false;
                }
                $valid = $row->table_name === $physicalTable && $row->event === strtoupper(
                    $operation
                ) && $row->timing === 'BEFORE' && $row->orientation === 'ROW'
                    && self::normalize($row->body) === self::normalize(
                        self::body($connection->getDriverName(), $operation, $connection, $table)
                    );
                break;
            case 'pgsql':
                $function = $connection->selectOne(
                    'SELECT p.oid, p.prosrc, p.prosecdef, p.proconfig, p.pronargs, p.prorettype::regtype::text AS return_type, l.lanname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace JOIN pg_language l ON l.oid = p.prolang WHERE n.nspname = ? AND p.proname = ?',
                    [$schema, $name],
                    false
                );
                $row = $connection->selectOne(
                    'SELECT t.tgfoid, t.tgtype, t.tgenabled, t.tgqual, t.tgconstraint, t.tgattr::text AS columns, a.attnum::text AS profile_column FROM pg_trigger t JOIN pg_attribute a ON a.attrelid = t.tgrelid AND a.attname = ? WHERE t.tgname = ? AND t.tgrelid = CAST(? AS regclass)',
                    ['runtime_profile', $name, $grammar->wrapTable($table)],
                    false
                );
                if ($function !== null && ($function->return_type !== 'trigger' || $function->lanname !== 'plpgsql' || $function->prosecdef || $function->proconfig !== null || (int) $function->pronargs !== 0 || self::normalize(
                    $function->prosrc
                ) !== self::normalize(self::body('pgsql', $operation, $connection, $table)))) {
                    throw new RuntimeProfileRefusal('runtime_profile_storage');
                }
                // A detached pre-existing function is not ours to adopt or replace.
                if ($row === null && $function !== null) {
                    throw new RuntimeProfileRefusal('runtime_profile_storage');
                }
                if ($row === null) {
                    return false;
                }
                $valid = $function !== null && (string) $row->tgfoid === (string) $function->oid && (int) $row->tgtype === match ($operation) {
                    'update' => 19, 'insert' => 7, 'delete' => 11
                }
                    && $row->tgenabled === 'O' && $row->tgqual === null && (int) $row->tgconstraint === 0 && $row->columns === '';
                break;
            default:
                throw new RuntimeProfileRefusal('runtime_profile_storage');
        }
        if (! $valid) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }
        return true;
    }

    private static function dropImmutability(Connection $connection, string $table, string $operation = 'update'): void
    {
        $grammar = $connection->getQueryGrammar();
        $name = self::quotedName($connection, self::name($connection, $table, $operation));
        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared(
                'DROP TRIGGER ' . $grammar->wrap(
                    self::unqualifiedName($connection, $table, $operation)
                ) . ' ON ' . $grammar->wrapTable($table)
            );
            $connection->unprepared('DROP FUNCTION ' . $name . '()');
            return;
        }
        $connection->unprepared('DROP TRIGGER ' . $name);
    }

    private static function unqualifiedName(Connection $connection, string $table, string $operation = 'update'): string
    {
        $parts = explode('.', self::name($connection, $table, $operation));
        return $parts[count($parts) - 1];
    }
}
