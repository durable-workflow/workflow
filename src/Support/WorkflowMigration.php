<?php

declare(strict_types=1);

namespace Workflow\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use UnexpectedValueException;

abstract class WorkflowMigration extends Migration
{
    /**
     * Why rolling this migration back would discard retained state, or null.
     *
     * MigrationRollbackPreflight calls this for every migration in a rollback
     * before any of them runs down(), so read only through $connection.
     */
    public function rollbackRefusal(Connection $connection): ?string
    {
        return null;
    }

    public function getConnection(): ?string
    {
        return config('workflows.storage.connection') ?? $this->connection;
    }

    protected function preserveMariaDbScheduledTimestamp(string $table, string $column, string $refusal): void
    {
        $connection = DB::connection($this->getConnection());
        if (! $connection instanceof MySqlConnection || ! $connection->isMaria()) {
            return;
        }

        // Older MariaDB implicitly rewrites its first non-null TIMESTAMP.
        // Remove only that behavior, preserving the rest of its definition.
        $metadata = $connection->selectOne(
            'SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS default_value, '
            . 'DATETIME_PRECISION AS datetime_precision, EXTRA AS extra FROM information_schema.columns '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$connection->getTablePrefix() . $table, $column],
        );
        if ($metadata === null || ! str_contains(strtolower((string) $metadata->extra), 'on update')) {
            return;
        }
        if (strtolower((string) $metadata->column_type) !== 'timestamp(6)'
            || $metadata->is_nullable !== 'NO'
            || strtolower((string) $metadata->default_value) !== 'current_timestamp(6)'
            || (int) $metadata->datetime_precision !== 6
            || strtolower((string) $metadata->extra) !== 'on update current_timestamp(6)') {
            throw new UnexpectedValueException($refusal);
        }

        $grammar = $connection->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedColumn = $grammar->wrap($column);
        $this->withSchemaLockBudget(static function () use ($connection, $wrappedTable, $wrappedColumn): void {
            $connection->statement(
                "ALTER TABLE {$wrappedTable} MODIFY {$wrappedColumn} TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), ALGORITHM=INPLACE, LOCK=NONE",
            );
        });
    }

    /**
     * Run a short schema change with a one-second lock wait.
     *
     * A schema change that waits for a table lock blocks every later reader
     * and writer of that table while it waits. Failing fast keeps live traffic
     * moving; the migration can be run again. The session setting is put back
     * afterwards. Only use this outside a transaction, with
     * `public $withinTransaction = false;`.
     *
     * @template TResult
     *
     * @return TResult
     */
    /**
     * Refuse this migration's own rollback. The operation-wide preflight has
     * already checked the whole rollback when the migrator runs it; this
     * covers a direct down() call.
     */
    protected function refuseRetainedRollback(): void
    {
        $refusal = $this->rollbackRefusal(DB::connection());
        if ($refusal !== null) {
            throw new LogicException($refusal);
        }
    }

    /**
     * Refusal for a nullable column whose non-null values are permanent fences.
     */
    protected static function populatedColumnRefusal(
        Connection $connection,
        string $table,
        string $column,
        string $refusal,
    ): ?string {
        return $connection->getSchemaBuilder()
            ->hasColumn($table, $column)
            && $connection->table($table)
                ->whereNotNull($column)
                ->exists() ? $refusal : null;
    }

    protected function addNullableJsonColumn(string $table, string $column): void
    {
        if (Schema::hasColumn($table, $column)) {
            return;
        }
        $this->withSchemaLockBudget(static function () use ($table, $column): void {
            Schema::table($table, static function (Blueprint $blueprint) use ($column): void {
                $blueprint->json($column)
                    ->nullable();
            });
        });
    }

    protected function dropColumnUnlessRetained(string $table, string $column): void
    {
        $this->refuseRetainedRollback();
        Schema::table($table, static function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropColumn($column);
        });
    }

    protected function withSchemaLockBudget(callable $operation): mixed
    {
        $connection = $this->schemaConnection();
        $driver = $connection->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $previous = (int) ((array) $connection->selectOne(
                'SELECT @@SESSION.lock_wait_timeout AS lock_wait_seconds'
            ))['lock_wait_seconds'];
            $connection->statement('SET SESSION lock_wait_timeout = 1');
            try {
                return $operation();
            } finally {
                $connection->statement('SET SESSION lock_wait_timeout = ' . $previous);
            }
        }

        if ($driver === 'pgsql') {
            $previous = ((array) $connection->selectOne('SHOW lock_timeout'))['lock_timeout'];
            $connection->selectOne("SELECT set_config('lock_timeout', '1s', false)");
            try {
                return $operation();
            } finally {
                $connection->selectOne("SELECT set_config('lock_timeout', ?, false)", [$previous]);
            }
        }

        return $operation();
    }

    /**
     * Create an index without blocking writes to a populated table.
     *
     * PostgreSQL builds it with CREATE INDEX CONCURRENTLY and replaces an
     * invalid index left by an interrupted build. MySQL and MariaDB build it
     * in place with LOCK=NONE and fail rather than copy the table. SQLite
     * uses the schema builder. Does nothing if the index already exists.
     * Only use this outside a transaction, with
     * `public $withinTransaction = false;`.
     *
     * @param list<string|Expression> $columns
     */
    protected function createIndexOnline(string $table, string $index, array $columns): void
    {
        $connection = $this->schemaConnection();
        $driver = $connection->getDriverName();
        $grammar = $connection->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedIndex = $grammar->wrap($index);
        $wrappedColumns = implode(', ', array_map(static fn (string|Expression $column): string => $grammar->wrap(
            $column
        ), $columns));
        $physicalTable = $connection->getTablePrefix() . $table;

        if ($driver === 'pgsql') {
            $existing = $connection->selectOne(
                'SELECT ix.indisvalid FROM pg_index ix JOIN pg_class i ON i.oid = ix.indexrelid '
                . 'JOIN pg_class t ON t.oid = ix.indrelid JOIN pg_namespace n ON n.oid = t.relnamespace '
                . 'WHERE n.nspname = current_schema() AND t.relname = ? AND i.relname = ?',
                [$physicalTable, $index],
            );
            if ($existing !== null && ! in_array($existing->indisvalid, [true, 1, '1', 't'], true)) {
                $connection->statement("DROP INDEX CONCURRENTLY {$wrappedIndex}");
                $existing = null;
            }
            if ($existing === null) {
                // A concurrent build waits for older transactions without
                // blocking readers or writers, so it gets no lock budget.
                $connection->statement(
                    "CREATE INDEX CONCURRENTLY {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumns})"
                );
            }

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $existing = $connection->selectOne(
                'SELECT INDEX_NAME FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$physicalTable, $index],
            );
            if ($existing === null) {
                $this->withSchemaLockBudget(static function () use (
                    $connection,
                    $wrappedTable,
                    $wrappedIndex,
                    $wrappedColumns
                ): void {
                    $connection->statement(
                        "ALTER TABLE {$wrappedTable} ADD INDEX {$wrappedIndex} ({$wrappedColumns}), ALGORITHM=INPLACE, LOCK=NONE"
                    );
                });
            }

            return;
        }

        $schema = Schema::connection($this->getConnection());
        if (! $schema->hasIndex($table, $index)) {
            $connection->statement("CREATE INDEX {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumns})");
        }
    }

    private function schemaConnection(): Connection
    {
        return DB::connection($this->getConnection());
    }
}
