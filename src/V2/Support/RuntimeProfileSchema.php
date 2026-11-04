<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\V2\Exceptions\RuntimeProfileRefusal;
use Workflow\V2\Models\WorkflowRun;

/** @internal Expand-only profile storage and database-enforced immutability. */
final class RuntimeProfileSchema
{
    public static function up(?string $migrationConnection): void
    {
        $targets = self::targets($migrationConnection);
        foreach ($targets as [$connection, $table]) {
            $schema = $connection->getSchemaBuilder();
            if (! $schema->hasTable($table)) {
                continue;
            }
            if (! $schema->hasColumn($table, 'runtime_profile')) {
                $schema->table($table, static function (Blueprint $blueprint): void {
                    $blueprint->string('runtime_profile', 64)->nullable();
                });
            }
            self::installImmutability($connection, $table);
        }
    }

    public static function down(?string $migrationConnection): void
    {
        $targets = self::targets($migrationConnection);
        // MySQL DDL commits implicitly: finish every refusal check first.
        try {
            foreach ($targets as [$connection, $table]) {
                $schema = $connection->getSchemaBuilder();
                if (! $schema->hasTable($table)) {
                    continue;
                }
                if (! $schema->hasColumn($table, 'runtime_profile')
                    || $connection->table($table)->useWritePdo()->whereNotNull('runtime_profile')->exists()) {
                    throw new RuntimeProfileRefusal('runtime_profile_storage');
                }
            }
        } catch (RuntimeProfileRefusal $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new RuntimeProfileRefusal('runtime_profile_storage', $error);
        }
        foreach ($targets as [$connection, $table]) {
            $schema = $connection->getSchemaBuilder();
            if (! $schema->hasTable($table)) {
                continue;
            }
            self::dropImmutability($connection, $table);
            $schema->table($table, static function (Blueprint $blueprint): void {
                $blueprint->dropColumn('runtime_profile');
            });
        }
    }

    /** @return list<array{Connection, string}> */
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
            $identity = spl_object_id($connection) . ':' . $table;
            if (! isset($seen[$identity])) {
                $targets[] = [$connection, $table];
                $seen[$identity] = true;
            }
        }

        return $targets;
    }

    private static function name(Connection $connection, string $table): string
    {
        $name = 'wf_runtime_profile_' . substr(hash('sha256', $connection->getTablePrefix() . $table), 0, 20);
        $separator = strrpos($table, '.');

        return $separator === false ? $name : substr($table, 0, $separator + 1) . $name;
    }

    private static function installImmutability(Connection $connection, string $table): void
    {
        if (self::hasImmutability($connection, $table)) {
            return;
        }
        $grammar = $connection->getQueryGrammar();
        $quotedTable = $grammar->wrapTable($table);
        $name = $grammar->wrap(self::name($connection, $table));
        $column = $grammar->wrap('runtime_profile');
        switch ($connection->getDriverName()) {
            case 'sqlite':
                $connection->unprepared("CREATE TRIGGER {$name} BEFORE UPDATE OF {$column} ON {$quotedTable}
                    FOR EACH ROW WHEN
                        (OLD.{$column} IS NULL AND NEW.{$column} IS NOT NULL)
                        OR (OLD.{$column} IS NOT NULL AND NEW.{$column} IS NULL)
                        OR (CAST(OLD.{$column} AS BLOB) != CAST(NEW.{$column} AS BLOB))
                    BEGIN SELECT RAISE(ABORT, 'runtime_profile_immutable'); END");
                break;
            case 'mysql':
            case 'mariadb':
                $connection->unprepared("CREATE TRIGGER {$name} BEFORE UPDATE ON {$quotedTable}
                    FOR EACH ROW BEGIN
                    IF NOT (BINARY OLD.{$column} <=> BINARY NEW.{$column}) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'runtime_profile_immutable';
                    END IF; END");
                break;
            case 'pgsql':
                $trigger = $grammar->wrap(self::unqualifiedName($connection, $table));
                $connection->unprepared("CREATE OR REPLACE FUNCTION {$name}() RETURNS trigger AS \$guard\$
                    BEGIN
                        IF convert_to(OLD.{$column}, 'UTF8') IS DISTINCT FROM convert_to(NEW.{$column}, 'UTF8') THEN
                            RAISE EXCEPTION 'runtime_profile_immutable' USING ERRCODE = '23514';
                        END IF;
                        RETURN NEW;
                    END; \$guard\$ LANGUAGE plpgsql");
                $connection->unprepared("CREATE TRIGGER {$trigger} BEFORE UPDATE OF {$column} ON {$quotedTable}
                    FOR EACH ROW EXECUTE FUNCTION {$name}()");
                break;
            default:
                throw new RuntimeProfileRefusal('runtime_profile_storage');
        }
    }

    private static function dropImmutability(Connection $connection, string $table): void
    {
        $grammar = $connection->getQueryGrammar();
        $name = $grammar->wrap(self::name($connection, $table));
        switch ($connection->getDriverName()) {
            case 'sqlite':
            case 'mysql':
            case 'mariadb':
                $connection->unprepared("DROP TRIGGER IF EXISTS {$name}");
                break;
            case 'pgsql':
                $trigger = $grammar->wrap(self::unqualifiedName($connection, $table));
                $connection->unprepared('DROP TRIGGER IF EXISTS ' . $trigger . ' ON ' . $grammar->wrapTable($table));
                $connection->unprepared("DROP FUNCTION IF EXISTS {$name}()");
                break;
            default:
                throw new RuntimeProfileRefusal('runtime_profile_storage');
        }
    }

    private static function unqualifiedName(Connection $connection, string $table): string
    {
        $parts = explode('.', self::name($connection, $table));

        return $parts[count($parts) - 1];
    }

    private static function hasImmutability(Connection $connection, string $table): bool
    {
        $name = self::unqualifiedName($connection, $table);
        $parts = explode('.', $table);
        $schema = count($parts) > 1 ? $parts[0] : null;

        return match ($connection->getDriverName()) {
            'sqlite' => $connection->selectOne(
                'SELECT 1 FROM ' . $connection->getQueryGrammar()->wrap(($schema ?? 'main') . '.sqlite_master')
                    . ' WHERE type = ? AND name = ?',
                ['trigger', $name],
            ) !== null,
            'mysql', 'mariadb' => $connection->selectOne(
                'SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = ?',
                [$schema ?? $connection->getDatabaseName(), $name],
            ) !== null,
            'pgsql' => $connection->selectOne(
                'SELECT 1 FROM pg_trigger WHERE tgname = ? AND tgrelid = CAST(? AS regclass)',
                [$name, $connection->getQueryGrammar()->wrapTable($table)],
            ) !== null,
            default => throw new RuntimeProfileRefusal('runtime_profile_storage'),
        };
    }
}
