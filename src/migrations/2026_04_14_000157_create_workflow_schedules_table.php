<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_schedules', static function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('schedule_id', 255);
            $table->string('namespace', 255)->nullable()->index();

            // Canonical temporal-style schedule descriptor. Supports multiple
            // cron expressions and/or interval specs:
            //   { "cron_expressions": [...], "intervals": [{"every": "PT30M", "offset": "PT5M"}], "timezone": "UTC" }
            $table->json('spec')->nullable();

            // Canonical workflow-start action descriptor:
            //   { "workflow_type": "...", "workflow_class": "...", "task_queue": "...",
            //     "input": [...], "execution_timeout_seconds": ?, "run_timeout_seconds": ? }
            $table->json('action')->nullable();

            $table->string('status', 16)->default('active');
            $table->string('overlap_policy', 32)->default('skip');
            $table->text('note')->nullable();

            $table->json('memo')->nullable();
            $table->json('search_attributes')->nullable();
            $table->json('visibility_labels')->nullable();

            $table->unsignedInteger('jitter_seconds')->default(0);
            $table->unsignedBigInteger('max_runs')->nullable();
            $table->unsignedBigInteger('remaining_actions')->nullable();
            $table->unsignedBigInteger('fires_count')->default(0);
            $table->unsignedBigInteger('failures_count')->default(0);

            $table->json('recent_actions')->nullable();
            $table->json('buffered_actions')->nullable();

            $table->timestamp('last_fired_at', 6)->nullable();
            $table->timestamp('next_fire_at', 6)->nullable();

            $table->string('latest_workflow_instance_id', 191)->nullable()->index();
            $table->string('connection', 255)->nullable();
            $table->string('queue', 255)->nullable();

            $table->timestamp('paused_at', 6)->nullable();
            $table->timestamp('deleted_at', 6)->nullable();

            $table->string('last_skip_reason', 64)->nullable();
            $table->timestamp('last_skipped_at', 6)->nullable();
            $table->unsignedBigInteger('skipped_trigger_count')->default(0);

            $table->timestamps(6);

            $table->unique(['namespace', 'schedule_id']);
            $table->index(['status', 'next_fire_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_schedules');
    }
};
