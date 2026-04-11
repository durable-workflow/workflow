<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_run_timeline_entries', static function (Blueprint $table): void {
            $table->string('id', 64)
                ->primary();
            $table->string('workflow_run_id', 26)
                ->index();
            $table->string('workflow_instance_id', 191)
                ->index();
            $table->string('history_event_id', 26)
                ->index();
            $table->unsignedInteger('sequence')
                ->index();
            $table->string('type')
                ->index();
            $table->string('kind')
                ->index();
            $table->string('entry_kind')
                ->default('point');
            $table->string('source_kind')
                ->nullable()
                ->index();
            $table->string('source_id', 191)
                ->nullable()
                ->index();
            $table->text('summary')
                ->nullable();
            $table->timestamp('recorded_at', 6)
                ->nullable()
                ->index();
            $table->string('command_id', 26)
                ->nullable()
                ->index();
            $table->unsignedInteger('command_sequence')
                ->nullable()
                ->index();
            $table->string('task_id', 26)
                ->nullable()
                ->index();
            $table->string('activity_execution_id', 26)
                ->nullable()
                ->index();
            $table->string('timer_id', 26)
                ->nullable()
                ->index();
            $table->string('failure_id', 26)
                ->nullable()
                ->index();
            $table->json('payload')
                ->nullable();
            $table->timestamps(6);

            $table->unique(['workflow_run_id', 'history_event_id'], 'workflow_run_timeline_run_event_unique');
            $table->index(['workflow_run_id', 'sequence'], 'workflow_run_timeline_run_sequence_index');
            $table->index(
                ['workflow_instance_id', 'kind', 'recorded_at'],
                'workflow_run_timeline_instance_kind_recorded_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_run_timeline_entries');
    }
};
