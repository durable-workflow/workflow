<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_run_summaries', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_instance_id', 26)
                ->index();
            $table->unsignedInteger('run_number');
            $table->boolean('is_current_run')
                ->default(false)
                ->index();
            $table->string('engine_source')
                ->default('v2');
            $table->string('class');
            $table->string('workflow_type');
            $table->string('status')
                ->index();
            $table->string('status_bucket')
                ->index();
            $table->string('closed_reason')
                ->nullable();
            $table->string('connection')
                ->nullable();
            $table->string('queue')
                ->nullable();
            $table->timestamp('started_at', 6)
                ->nullable();
            $table->timestamp('closed_at', 6)
                ->nullable();
            $table->bigInteger('duration_ms')
                ->nullable();
            $table->string('wait_kind')
                ->nullable();
            $table->text('wait_reason')
                ->nullable();
            $table->timestamp('wait_started_at', 6)
                ->nullable();
            $table->timestamp('wait_deadline_at', 6)
                ->nullable();
            $table->timestamp('next_task_at', 6)
                ->nullable();
            $table->unsignedInteger('exception_count')
                ->default(0);
            $table->timestamps(6);

            $table->index(['status_bucket', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_run_summaries');
    }
};
