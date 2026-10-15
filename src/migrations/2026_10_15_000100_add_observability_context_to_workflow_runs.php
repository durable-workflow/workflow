<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        if (! Schema::hasColumn('workflow_runs', 'observability_context')) {
            Schema::table('workflow_runs', static function (Blueprint $table): void {
                $table->json('observability_context')
                    ->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('workflow_runs', 'observability_context')) {
            Schema::table('workflow_runs', static function (Blueprint $table): void {
                $table->dropColumn('observability_context');
            });
        }
    }
};
