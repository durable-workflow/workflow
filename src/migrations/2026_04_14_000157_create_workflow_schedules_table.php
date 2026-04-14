<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_schedules', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('schedule_id', 255)
                ->unique();
            $table->string('namespace', 255)
                ->nullable()
                ->index();
            $table->string('workflow_type', 255);
            $table->string('workflow_class', 255);
            $table->text('cron_expression');
            $table->string('timezone', 64)
                ->default('UTC');
            $table->string('status');
            $table->string('overlap_policy', 32)
                ->default('skip');
            $table->json('workflow_arguments')
                ->nullable();
            $table->json('memo')
                ->nullable();
            $table->json('search_attributes')
                ->nullable();
            $table->json('visibility_labels')
                ->nullable();
            $table->unsignedInteger('jitter_seconds')
                ->default(0);
            $table->unsignedBigInteger('max_runs')
                ->nullable();
            $table->unsignedBigInteger('total_runs')
                ->default(0);
            $table->unsignedBigInteger('remaining_actions')
                ->nullable();
            $table->string('latest_workflow_instance_id', 26)
                ->nullable()
                ->index();
            $table->string('connection', 255)
                ->nullable();
            $table->string('queue', 255)
                ->nullable();
            $table->text('notes')
                ->nullable();
            $table->timestamp('next_run_at', 6)
                ->nullable()
                ->index();
            $table->timestamp('last_triggered_at', 6)
                ->nullable();
            $table->timestamp('paused_at', 6)
                ->nullable();
            $table->timestamp('deleted_at', 6)
                ->nullable();
            $table->timestamps(6);

            $table->index(['status', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_schedules');
    }
};
