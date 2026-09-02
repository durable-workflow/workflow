<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        Schema::table('activity_attempts', static function (Blueprint $table): void {
            if (! Schema::hasColumn('activity_attempts', 'worker_attempt_id')) {
                $table->string('worker_attempt_id', 255)
                    ->nullable()
                    ->after('workflow_task_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_attempts', static function (Blueprint $table): void {
            if (Schema::hasColumn('activity_attempts', 'worker_attempt_id')) {
                $table->dropColumn('worker_attempt_id');
            }
        });
    }
};
