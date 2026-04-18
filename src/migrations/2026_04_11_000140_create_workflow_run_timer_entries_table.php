<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\V2\Models\WorkflowRunTimerEntry;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_run_timer_entries', static function (Blueprint $table): void {
            $table->string('id', 64)
                ->primary();
            $table->string('workflow_run_id', 26)
                ->index();
            $table->string('workflow_instance_id', 191)
                ->index();
            $table->string('timer_id', 191);
            $table->unsignedSmallInteger('schema_version')
                ->default(WorkflowRunTimerEntry::CURRENT_SCHEMA_VERSION);
            $table->unsignedInteger('position');
            $table->unsignedInteger('sequence')
                ->nullable()
                ->index();
            $table->string('status')
                ->index();
            $table->string('source_status')
                ->nullable()
                ->index();
            $table->integer('delay_seconds')
                ->nullable();
            $table->timestamp('fire_at', 6)
                ->nullable();
            $table->timestamp('fired_at', 6)
                ->nullable();
            $table->timestamp('cancelled_at', 6)
                ->nullable();
            $table->string('timer_kind', 191)
                ->nullable()
                ->index();
            $table->string('condition_wait_id', 191)
                ->nullable()
                ->index();
            $table->string('condition_key', 191)
                ->nullable()
                ->index();
            $table->string('condition_definition_fingerprint', 191)
                ->nullable();
            $table->string('history_authority')
                ->nullable()
                ->index();
            $table->string('history_unsupported_reason')
                ->nullable();
            $table->json('payload')
                ->nullable();
            $table->timestamps(6);

            $table->unique(['workflow_run_id', 'timer_id'], 'workflow_run_timer_entries_run_timer_unique');
            $table->index(
                ['workflow_run_id', 'status', 'position'],
                'workflow_run_timer_entries_run_status_position_index'
            );
            $table->index(
                ['workflow_instance_id', 'timer_kind', 'status'],
                'workflow_run_timer_entries_instance_kind_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_run_timer_entries');
    }
};
