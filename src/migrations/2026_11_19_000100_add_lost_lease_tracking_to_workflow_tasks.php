<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ConfiguredV2Models;

/**
 * Count the leases a workflow task lost without completing (#1231).
 */
return new class() extends WorkflowMigration {
    public function up(): void
    {
        $model = ConfiguredV2Models::resolve('task_model', WorkflowTask::class);
        $schema = Schema::connection($this->getConnection());
        foreach (array_unique(['workflow_tasks', (new $model())->getTable()]) as $tableName) {
            if ($schema->hasTable($tableName) && ! $schema->hasColumn($tableName, 'lost_lease_count')) {
                $schema->table($tableName, static function (Blueprint $table): void {
                    $table->unsignedInteger('lost_lease_count')
                        ->default(0);
                    $table->timestamp('last_lease_lost_at', 6)
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
            if ($schema->hasTable($tableName) && $schema->hasColumn($tableName, 'lost_lease_count')) {
                $schema->table($tableName, static function (Blueprint $table): void {
                    $table->dropColumn(['lost_lease_count', 'last_lease_lost_at']);
                });
            }
        }
    }
};
