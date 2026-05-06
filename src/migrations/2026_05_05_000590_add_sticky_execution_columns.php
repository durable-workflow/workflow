<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_runs', 'sticky_worker_id')) {
                $table->string('sticky_worker_id')
                    ->nullable()
                    ->index();
            }

            if (! Schema::hasColumn('workflow_runs', 'sticky_until')) {
                $table->timestamp('sticky_until', 6)
                    ->nullable()
                    ->index();
            }
        });

        Schema::table('workflow_tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_tasks', 'sticky_worker_id')) {
                $table->string('sticky_worker_id')
                    ->nullable()
                    ->index();
            }

            if (! Schema::hasColumn('workflow_tasks', 'sticky_until')) {
                $table->timestamp('sticky_until', 6)
                    ->nullable()
                    ->index();
            }

            if (! Schema::hasColumn('workflow_tasks', 'sticky_replay_mode')) {
                $table->string('sticky_replay_mode')
                    ->nullable()
                    ->index();
            }

            if (! Schema::hasColumn('workflow_tasks', 'sticky_claimed_at')) {
                $table->timestamp('sticky_claimed_at', 6)
                    ->nullable()
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('workflow_tasks', function (Blueprint $table): void {
            foreach (['sticky_worker_id', 'sticky_until', 'sticky_replay_mode', 'sticky_claimed_at'] as $column) {
                if (Schema::hasColumn('workflow_tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('workflow_runs', function (Blueprint $table): void {
            foreach (['sticky_worker_id', 'sticky_until'] as $column) {
                if (Schema::hasColumn('workflow_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
