<?php

declare(strict_types=1);

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Support\ActivityTaskLookup;

return new class() extends WorkflowMigration {
    public $withinTransaction = false;

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $driver = $connection->getDriverName();
        $schema = $connection->getSchemaBuilder();
        $expression = ActivityTaskLookup::expression($driver);
        if ($expression === null || ! $schema->hasTable('workflow_tasks')) {
            return;
        }

        $key = new Expression('(' . $expression . ')');
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $key = ActivityTaskLookup::COLUMN;
            if (! $schema->hasColumn('workflow_tasks', $key)) {
                $grammar = $connection->getQueryGrammar();
                $table = $grammar->wrapTable('workflow_tasks');
                $column = $grammar->wrap($key);
                $this->withSchemaLockBudget(static fn () => $connection->statement(
                    "ALTER TABLE {$table} ADD COLUMN {$column} VARCHAR(26) GENERATED ALWAYS AS ({$expression}) VIRTUAL INVISIBLE, "
                    . 'ALGORITHM=INPLACE, LOCK=NONE',
                ));
            }
        }

        $this->createIndexOnline('workflow_tasks', $this->indexName(), ['workflow_run_id', 'task_type', $key]);
    }

    public function down(): void
    {
        $schema = DB::connection($this->getConnection())->getSchemaBuilder();
        if (! $schema->hasTable('workflow_tasks')) {
            return;
        }
        $index = $this->indexName();
        if ($schema->hasIndex('workflow_tasks', $index)) {
            $this->withSchemaLockBudget(static fn () => $schema->table(
                'workflow_tasks',
                static fn (Blueprint $table) => $table->dropIndex($index),
            ));
        }
        if ($schema->hasColumn('workflow_tasks', ActivityTaskLookup::COLUMN)) {
            $this->withSchemaLockBudget(static fn () => $schema->table(
                'workflow_tasks',
                static fn (Blueprint $table) => $table->dropColumn(ActivityTaskLookup::COLUMN),
            ));
        }
    }

    private function indexName(): string
    {
        $prefix = DB::connection($this->getConnection())->getTablePrefix();
        $name = 'dw_task_run_activity_execution';

        return $prefix === '' ? $name : $name . '_' . substr(hash('sha256', $prefix), 0, 12);
    }
};
