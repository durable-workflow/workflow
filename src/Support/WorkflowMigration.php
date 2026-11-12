<?php

declare(strict_types=1);

namespace Workflow\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class WorkflowMigration extends Migration
{
    public function getConnection(): ?string
    {
        return config('workflows.storage.connection') ?? $this->connection;
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
     * @param callable(): TResult $operation
     * @return TResult
     */
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
     * Create a plain index without blocking writes to a populated table.
     *
     * PostgreSQL builds it with CREATE INDEX CONCURRENTLY and replaces an
     * invalid index left by an interrupted build. MySQL and MariaDB build it
     * in place with LOCK=NONE and fail rather than copy the table. SQLite
     * uses the schema builder. Does nothing if the index already exists.
     * Only use this outside a transaction, with
     * `public $withinTransaction = false;`.
     *
     * @param list<string> $columns
     */
    protected function createIndexOnline(string $table, string $index, array $columns): void
    {
        $connection = $this->schemaConnection();
        $driver = $connection->getDriverName();
        $grammar = $connection->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedIndex = $grammar->wrap($index);
        $wrappedColumns = implode(', ', array_map(static fn (string $column): string => $grammar->wrap(
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
            $schema->table($table, static function ($blueprint) use ($columns, $index): void {
                $blueprint->index($columns, $index);
            });
        }
    }

    private function schemaConnection(): Connection
    {
        return DB::connection($this->getConnection());
    }
}
