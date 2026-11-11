<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

// Additive and nullable, with no backfill. Attempts written before this
// migration keep their progress in ActivityHeartbeatRecorded history only.
return new class() extends WorkflowMigration {
    public function up(): void
    {
        Schema::table('activity_attempts', static function (Blueprint $table): void {
            if (! Schema::hasColumn('activity_attempts', 'last_heartbeat_progress')) {
                $table->json('last_heartbeat_progress')
                    ->nullable();
            }

            if (! Schema::hasColumn('activity_attempts', 'last_heartbeat_progress_at')) {
                $table->timestamp('last_heartbeat_progress_at', 6)
                    ->nullable();
            }

            if (! Schema::hasColumn('activity_attempts', 'last_heartbeat_recorded_at')) {
                $table->timestamp('last_heartbeat_recorded_at', 6)
                    ->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_attempts', static function (Blueprint $table): void {
            foreach ([
                'last_heartbeat_progress',
                'last_heartbeat_progress_at',
                'last_heartbeat_recorded_at',
            ] as $column) {
                if (Schema::hasColumn('activity_attempts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
