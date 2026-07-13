<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        Schema::create('workflow_child_projection_repairs', static function (Blueprint $table): void {
            $table->string('workflow_history_event_id', 26)
                ->primary();
            $table->string('workflow_run_id', 26);
            $table->string('workflow_task_id', 26);
            $table->unsignedInteger('history_sequence');
            $table->string('failure_id', 26)
                ->nullable();
            $table->timestamp('failed_child_counted_at', 6)
                ->nullable();
            $table->timestamps(6);

            $table->index(
                ['workflow_run_id', 'workflow_task_id', 'history_sequence'],
                'workflow_child_projection_repairs_drain_idx',
            );
            $table->index(
                ['workflow_run_id', 'failure_id'],
                'workflow_child_projection_repairs_failure_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_child_projection_repairs');
    }
};
