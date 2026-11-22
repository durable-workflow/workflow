<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Models\WorkflowTask;
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
                $this->withSchemaLockBudget(static fn () => $schema->table(
                    $table,
                    static fn (Blueprint $blueprint) => $blueprint->dropIndex($name),
                ));
            }
        }
    }

    /**
     * @return list<array{string, string, list<string>}>
     */
    private function indexes(): array
    {
        $model = ConfiguredV2Models::resolve('task_model', WorkflowTask::class);
        $default = 'workflow_tasks';
        $prefix = DB::connection($this->getConnection())->getTablePrefix();
        $indexes = [];
        foreach (array_unique([$default, (new $model())->getTable()]) as $table) {
            $suffix = $table === $default && $prefix === '' ? '' : '_' . substr(
                hash('sha256', $prefix . $table),
                0,
                12
            );
            $indexes[] = [$table, 'dw_task_run_status_order' . $suffix,
                ['workflow_run_id', 'status', 'available_at', 'created_at', 'id']];
            $indexes[] = [$table, 'dw_task_run_status_expiry' . $suffix,
                ['workflow_run_id', 'status', 'lease_expires_at']];
            $indexes[] = [$table, 'dw_task_run_type' . $suffix, ['workflow_run_id', 'task_type']];
        }

        return $indexes;
    }
};
