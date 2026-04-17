<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('workflow_schedule_history_events')) {
            if (Schema::hasColumns('workflow_schedule_history_events', [
                'id',
                'workflow_schedule_id',
                'schedule_id',
                'sequence',
                'event_type',
                'payload',
                'recorded_at',
            ])) {
                return;
            }

            throw new RuntimeException(
                'workflow_schedule_history_events already exists but is missing expected schedule-history columns.'
            );
        }

        Schema::create('workflow_schedule_history_events', static function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('workflow_schedule_id', 26)->index();
            $table->string('schedule_id', 255)->index();
            $table->string('namespace', 255)->nullable()->index();
            $table->unsignedInteger('sequence');
            $table->string('event_type');
            $table->json('payload')->nullable();
            $table->string('workflow_instance_id', 191)->nullable()->index();
            $table->string('workflow_run_id', 26)->nullable()->index();
            $table->timestamp('recorded_at', 6)->nullable();
            $table->timestamps(6);

            $table->unique(['workflow_schedule_id', 'sequence']);
            $table->index(['namespace', 'schedule_id']);
            $table->index(['event_type', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_schedule_history_events');
    }
};
