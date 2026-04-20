<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_messages', static function (Blueprint $table): void {
            $table->id();

            // Message ownership
            $table->string('workflow_instance_id', 191)
                ->index();
            // Nullable because inbound messages can be created for a target
            // instance before it has a running run (signal-with-start). A run
            // claims these messages by setting workflow_run_id on consume.
            $table->string('workflow_run_id', 26)
                ->nullable()
                ->index();

            // Message directionality (inbound, outbound)
            $table->string('direction', 16)
                ->index();

            // Message channel (signal, update, workflow_message, external, child_signal, etc.)
            $table->string('channel', 64)
                ->index();

            // Stream grouping and sequencing
            $table->string('stream_key', 191)
                ->index();
            $table->unsignedBigInteger('sequence')
                ->index();

            // Message routing
            $table->string('source_workflow_instance_id', 191)
                ->nullable()
                ->index();
            $table->string('source_workflow_run_id', 26)
                ->nullable();
            $table->string('target_workflow_instance_id', 191)
                ->nullable()
                ->index();
            $table->string('target_workflow_run_id', 26)
                ->nullable();

            // Correlation and idempotency
            $table->string('correlation_id', 191)
                ->nullable()
                ->index();
            $table->string('idempotency_key', 191)
                ->nullable()
                ->index();

            // Payload reference (pointer to payload storage)
            $table->string('payload_reference', 191)
                ->nullable();

            // Consume state (pending, consumed, failed, expired)
            $table->string('consume_state', 16)
                ->default('pending')
                ->index();
            $table->timestamp('consumed_at', 6)
                ->nullable();
            $table->unsignedInteger('consumed_by_sequence')
                ->nullable();

            // Expiry and delivery metadata
            $table->timestamp('expires_at', 6)
                ->nullable();
            $table->unsignedInteger('delivery_attempt_count')
                ->default(0);
            $table->timestamp('last_delivery_attempt_at', 6)
                ->nullable();
            $table->text('last_delivery_error')
                ->nullable();

            // Extensibility
            $table->json('metadata')
                ->nullable();

            $table->timestamps(6);

            // Composite indexes for efficient queries
            $table->index(['workflow_instance_id', 'stream_key', 'sequence'], 'wf_msgs_instance_stream_seq');
            $table->index(['workflow_run_id', 'direction', 'consume_state'], 'wf_msgs_run_dir_state');
            $table->index(['stream_key', 'sequence', 'consume_state'], 'wf_msgs_stream_seq_state');
            $table->index(['target_workflow_instance_id', 'consume_state'], 'wf_msgs_target_state');

            // Unique constraint for stream sequence ordering
            $table->unique(['workflow_instance_id', 'stream_key', 'sequence'], 'wf_msgs_stream_seq_unique');

            // Foreign key cascade
            $table->foreign('workflow_run_id')
                ->references('id')
                ->on('workflow_runs')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_messages');
    }
};
