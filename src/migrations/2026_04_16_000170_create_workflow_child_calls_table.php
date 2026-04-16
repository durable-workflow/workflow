<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_child_calls', static function (Blueprint $table): void {
            $table->id();

            // Parent context
            $table->string('parent_workflow_run_id', 26)->index();
            $table->string('parent_workflow_instance_id', 191)->index();
            $table->unsignedInteger('sequence')->index(); // History event sequence that scheduled the child

            // Child identity (before resolution)
            $table->string('child_workflow_type', 191);
            $table->string('child_workflow_class', 255);
            $table->string('requested_child_id', 191)->nullable(); // User-specified child instance ID

            // Resolved references (after child starts)
            $table->string('resolved_child_instance_id', 191)->nullable()->index();
            $table->string('resolved_child_run_id', 26)->nullable()->index();

            // Snapped parent-close policy
            $table->string('parent_close_policy', 32)->default('abandon'); // abandon, request_cancel, terminate

            // Snapped routing options
            $table->string('connection', 191)->nullable();
            $table->string('queue', 191)->nullable();
            $table->string('compatibility', 191)->nullable();

            // Snapped policy options (for future expansion)
            $table->json('retry_policy')->nullable();
            $table->json('timeout_policy')->nullable();
            $table->boolean('cancellation_propagation')->default(false);

            // Lifecycle status
            $table->string('status', 32)->default('scheduled')->index();
            // States: scheduled, started, completed, failed, cancelled, terminated, abandoned

            // Outcome metadata
            $table->string('result_payload_reference', 191)->nullable();
            $table->string('failure_reference', 191)->nullable();
            $table->string('closed_reason', 64)->nullable(); // completed, failed, cancelled, terminated, abandoned

            // Timing
            $table->timestamp('scheduled_at', 6);
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('closed_at', 6)->nullable();

            // Arguments and metadata
            $table->json('arguments')->nullable(); // Serialized child workflow arguments
            $table->json('metadata')->nullable(); // Extensibility

            $table->timestamps(6);

            // Composite indexes for efficient queries
            $table->index(['parent_workflow_run_id', 'sequence'], 'child_calls_parent_seq');
            $table->index(['parent_workflow_run_id', 'status'], 'child_calls_parent_status');
            $table->index(['resolved_child_instance_id', 'parent_workflow_run_id'], 'child_calls_child_parent');

            // Foreign key cascade
            $table->foreign('parent_workflow_run_id')
                ->references('id')
                ->on('workflow_runs')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_child_calls');
    }
};
