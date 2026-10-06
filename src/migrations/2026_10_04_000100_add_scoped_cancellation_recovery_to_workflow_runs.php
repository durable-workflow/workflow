<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->timestamp('cancellation_scope_recovery_until', 6)
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->dropColumn('cancellation_scope_recovery_until');
        });
    }
};
