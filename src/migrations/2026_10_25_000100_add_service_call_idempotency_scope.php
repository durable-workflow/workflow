<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    private const TABLE = 'workflow_service_calls';

    private const INDEX = 'wf_service_calls_idem_scope_unique';

    public $withinTransaction = false;

    public function up(): void
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();
        $previous = null;
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $previous = (int) ((array) $connection->selectOne(
                'SELECT @@SESSION.lock_wait_timeout AS seconds'
            ))['seconds'];
            $connection->statement('SET SESSION lock_wait_timeout = 1');
        } elseif ($driver === 'pgsql') {
            $previous = ((array) $connection->selectOne('SHOW lock_timeout'))['lock_timeout'];
            $connection->selectOne("SELECT set_config('lock_timeout', '1s', false)");
        }
        try {
            $table = $connection->getQueryGrammar()
                ->wrapTable(self::TABLE);
            if (! Schema::hasColumn(self::TABLE, 'idempotency_scope_hash')) {
                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    $connection->statement(
                        "ALTER TABLE {$table} ADD COLUMN idempotency_scope_hash CHAR(64) NULL, ALGORITHM=INSTANT"
                    );
                } else {
                    Schema::table(self::TABLE, static function (Blueprint $table): void {
                        $table->char('idempotency_scope_hash', 64)
                            ->nullable();
                    });
                }
            }
            $physical = $connection->getTablePrefix() . self::TABLE;
            $index = $connection->getQueryGrammar()
                ->wrap(self::INDEX);
            if ($driver === 'pgsql') {
                $existing = $connection->selectOne(
                    'SELECT ix.indisvalid FROM pg_index ix JOIN pg_class i ON i.oid = ix.indexrelid '
                    . 'JOIN pg_class t ON t.oid = ix.indrelid JOIN pg_namespace n ON n.oid = t.relnamespace '
                    . 'WHERE n.nspname = current_schema() AND t.relname = ? AND i.relname = ?',
                    [$physical, self::INDEX],
                );
                if ($existing !== null && ! in_array($existing->indisvalid, [true, 1, '1', 't'], true)) {
                    $connection->statement("DROP INDEX CONCURRENTLY {$index}");
                    $existing = null;
                }
                if ($existing === null) {
                    $connection->statement(
                        "CREATE UNIQUE INDEX CONCURRENTLY {$index} ON {$table} (idempotency_scope_hash)"
                    );
                }
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                $existing = $connection->selectOne(
                    'SELECT INDEX_NAME FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    [$physical, self::INDEX],
                );
                if ($existing === null) {
                    $connection->statement(
                        "ALTER TABLE {$table} ADD UNIQUE INDEX {$index} (idempotency_scope_hash), ALGORITHM=INPLACE, LOCK=NONE"
                    );
                }
            } else {
                $indexes = $connection->select("PRAGMA index_list({$table})");
                if (! in_array(self::INDEX, array_column($indexes, 'name'), true)) {
                    Schema::table(self::TABLE, static function (Blueprint $table): void {
                        $table->unique('idempotency_scope_hash', self::INDEX);
                    });
                }
            }
        } finally {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $connection->statement('SET SESSION lock_wait_timeout = ' . $previous);
            } elseif ($driver === 'pgsql') {
                $connection->selectOne("SELECT set_config('lock_timeout', ?, false)", [$previous]);
            }
        }
    }

    public function down(): void
    {
        // Keep the nullable expansion and canonical claims on rollback. Older
        // readers can ignore them; dropping the guard would race live writers.
    }
};
