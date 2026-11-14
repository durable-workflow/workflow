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
            'SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS default_value, '
            . 'DATETIME_PRECISION AS datetime_precision, EXTRA AS extra FROM information_schema.columns '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$connection->getTablePrefix() . 'workflow_run_timers', 'fire_at'],
        );
        if ($column === null || ! str_contains(strtolower((string) $column->extra), 'on update')) {
            return;
        }
        if (strtolower((string) $column->column_type) !== 'timestamp(6)'
            || $column->is_nullable !== 'NO'
            || strtolower((string) $column->default_value) !== 'current_timestamp(6)'
            || (int) $column->datetime_precision !== 6
            || strtolower((string) $column->extra) !== 'on update current_timestamp(6)') {
            throw new UnexpectedValueException('workflow_timer_fire_at_legacy_shape_unavailable');
        }

        $table = $connection->getQueryGrammar()
            ->wrapTable('workflow_run_timers');
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
