<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ConfiguredV2Models;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        $model = ConfiguredV2Models::resolve('task_model', WorkflowTask::class);
        $schema = Schema::connection($this->getConnection());
        foreach (array_unique(['workflow_tasks', (new $model())->getTable()]) as $tableName) {
            if ($schema->hasTable($tableName) && ! $schema->hasColumn($tableName, 'observability_context')) {
                $schema->table($tableName, static function (Blueprint $table): void {
                    $table->json('observability_context')
                        ->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        $model = ConfiguredV2Models::resolve('task_model', WorkflowTask::class);
        $schema = Schema::connection($this->getConnection());
        foreach (array_unique(['workflow_tasks', (new $model())->getTable()]) as $tableName) {
            if ($schema->hasTable($tableName) && $schema->hasColumn($tableName, 'observability_context')) {
                $schema->table($tableName, static function (Blueprint $table): void {
                    $table->dropColumn('observability_context');
                });
            }
        }
    }
};
