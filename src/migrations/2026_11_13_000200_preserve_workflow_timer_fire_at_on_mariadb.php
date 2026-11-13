<?php

declare(strict_types=1);

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public $withinTransaction = false;

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        if (! $connection instanceof MySqlConnection || ! $connection->isMaria()) {
            return;
        }

        $column = $connection->selectOne(
            'SELECT EXTRA AS extra FROM information_schema.columns '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$connection->getTablePrefix() . 'workflow_run_timers', 'fire_at'],
        );
        if ($column === null || ! str_contains(strtolower((string) $column->extra), 'on update')) {
            return;
        }

        $table = $connection->getQueryGrammar()->wrapTable('workflow_run_timers');
        $this->withSchemaLockBudget(static function () use ($connection, $table): void {
            // Older MariaDB adds ON UPDATE to the first non-null TIMESTAMP.
            // Keep its existing insert default, precision and stored values.
            $connection->statement(
                "ALTER TABLE {$table} MODIFY fire_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), ALGORITHM=INPLACE, LOCK=NONE",
            );
        });
    }

    public function down(): void
    {
        // Older runtime code can use the corrected column. Reinstating an
        // automatic deadline rewrite would corrupt retained timer evidence.
    }
};
