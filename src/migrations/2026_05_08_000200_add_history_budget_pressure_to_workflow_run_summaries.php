<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->unsignedInteger('history_fan_out')
                ->default(0)
                ->after('history_size_bytes');
            $table->string('history_budget_pressure', 32)
                ->default('ok')
                ->after('continue_as_new_recommended')
                ->index('workflow_run_summaries_history_budget_pressure_index');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->dropIndex('workflow_run_summaries_history_budget_pressure_index');
            $table->dropColumn(['history_fan_out', 'history_budget_pressure']);
        });
    }
};
