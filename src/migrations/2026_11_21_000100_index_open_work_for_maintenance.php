<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ConfiguredV2Models;

return new class() extends WorkflowMigration {
    public $withinTransaction = false;

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach ($this->indexes() as [$table, $name, $columns]) {
            if ($schema->hasTable($table)) {
                $this->createIndexOnline($table, $name, $columns);
            }
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach ($this->indexes() as [$table, $name]) {
            if ($schema->hasTable($table) && $schema->hasIndex($table, $name)) {
                $this->withSchemaLockBudget(static function () use ($schema, $table, $name): void {
                    $schema->table($table, static function (Blueprint $blueprint) use ($name): void {
                        $blueprint->dropIndex($name);
                    });
                });
            }
        }
    }

    /**
     * Share the names and column order of native Server's maintenance indexes
     * on standard tables. Custom tables and connection prefixes need distinct
     * names because a PostgreSQL or SQLite schema can contain both tables.
     *
     * @return list<array{string, string, list<string>}>
     */
    private function indexes(): array
    {
        $run = ConfiguredV2Models::resolve('run_model', WorkflowRun::class);
        $activity = ConfiguredV2Models::resolve('activity_execution_model', ActivityExecution::class);
        $prefix = DB::connection($this->getConnection())->getTablePrefix();
        $targets = [
            [
                'workflow_runs',
                (new $run())->getTable(),
                'dw_workflow_runs_status_deadline',
                ['status', 'cancellation_deadline_at'],
            ],
            ['activity_executions', (new $activity())->getTable(), 'dw_activity_executions_status', ['status']],
        ];
        $indexes = [];

        foreach ($targets as [$default, $configured, $name, $columns]) {
            foreach (array_unique([$default, $configured]) as $table) {
                $index = $table === $default && $prefix === ''
                    ? $name
                    : $name . '_' . substr(hash('sha256', $prefix . $table), 0, 12);
                $indexes[] = [$table, $index, $columns];
            }
        }

        return $indexes;
    }
};
