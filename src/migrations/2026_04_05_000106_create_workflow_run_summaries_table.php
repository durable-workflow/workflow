<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_run_summaries', static function (Blueprint $table): void {
            // Primary key
            $table->string('id', 26)
                ->primary();

            // Instance relationship
            $table->string('workflow_instance_id', 191)
                ->index();
            $table->unsignedInteger('run_number');
            $table->boolean('is_current_run')
                ->default(false)
                ->index();

            // Engine metadata
            $table->string('engine_source')
                ->default('v2');
            $table->unsignedSmallInteger('projection_schema_version')
                ->nullable()
                ->index();

            // Workflow identity
            $table->string('class');
            $table->string('workflow_type');
            $table->string('namespace')
                ->nullable()
                ->index();
            $table->string('compatibility')
                ->nullable();

            // Contract and visibility metadata
            $table->string('declared_entry_mode')
                ->nullable()
                ->index('wfrs_decl_entry_mode_idx');
            $table->string('declared_contract_source')
                ->nullable()
                ->index('wfrs_decl_contract_source_idx');

            // Business visibility
            $table->string('business_key', 191)
                ->nullable()
                ->index('workflow_run_summaries_business_key_index');
            $table->json('visibility_labels')
                ->nullable();
            $table->json('memo')
                ->nullable();
            $table->json('search_attributes')
                ->nullable();

            // Execution status
            $table->string('status')
                ->index();
            $table->string('status_bucket')
                ->index();
            $table->string('closed_reason')
                ->nullable();

            // Queue configuration
            $table->string('connection')
                ->nullable();
            $table->string('queue')
                ->nullable();

            // Timing
            $table->timestamp('started_at', 6)
                ->nullable();
            $table->timestamp('sort_timestamp', 6)
                ->nullable();
            $table->string('sort_key', 64)
                ->nullable();
            $table->timestamp('closed_at', 6)
                ->nullable();
            $table->timestamp('archived_at', 6)
                ->nullable()
                ->index();
            $table->string('archive_command_id', 26)
                ->nullable()
                ->index();
            $table->string('archive_reason')
                ->nullable();
            $table->bigInteger('duration_ms')
                ->nullable();

            // Wait state
            $table->string('wait_kind')
                ->nullable();
            $table->text('wait_reason')
                ->nullable();
            $table->timestamp('wait_started_at', 6)
                ->nullable();
            $table->timestamp('wait_deadline_at', 6)
                ->nullable();
            $table->string('open_wait_id', 191)
                ->nullable();
            $table->string('resume_source_kind')
                ->nullable();
            $table->string('resume_source_id', 191)
                ->nullable();

            // Next task scheduling
            $table->timestamp('next_task_at', 6)
                ->nullable();

            // Liveness tracking
            $table->string('liveness_state')
                ->nullable();
            $table->text('liveness_reason')
                ->nullable();
            $table->string('repair_blocked_reason')
                ->nullable()
                ->index('workflow_run_summaries_repair_blocked_reason_index');
            $table->boolean('repair_attention')
                ->default(false)
                ->index('workflow_run_summaries_repair_attention_index');
            $table->boolean('task_problem')
                ->default(false);

            // Next task details
            $table->string('next_task_id', 26)
                ->nullable();
            $table->string('next_task_type')
                ->nullable();
            $table->string('next_task_status')
                ->nullable();
            $table->timestamp('next_task_lease_expires_at', 6)
                ->nullable();

            // Exception tracking
            $table->unsignedInteger('exception_count')
                ->default(0);

            // History budget tracking
            $table->unsignedInteger('history_event_count')
                ->default(0);
            $table->unsignedBigInteger('history_size_bytes')
                ->default(0);
            $table->boolean('continue_as_new_recommended')
                ->default(false)
                ->index('workflow_run_summaries_continue_as_new_recommended_index');

            // Timestamps
            $table->timestamps(6);

            // Composite indexes
            $table->index(['status_bucket', 'started_at']);
            $table->index(['sort_timestamp', 'id'], 'workflow_run_summaries_sort_order_index');
            $table->index('sort_key', 'workflow_run_summaries_sort_key_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_run_summaries');
    }
};
