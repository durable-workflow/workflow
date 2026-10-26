<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    private const TABLE = 'workflow_service_call_dispatches';

    public function up(): void
    {
        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->string('service_call_id', 26)
                ->primary();
            $table->string('state', 32);
            $table->json('dispatch_options');
            $table->unsignedInteger('attempt')
                ->default(1);
            $table->unsignedInteger('max_attempts')
                ->default(1);
            $table->timestamp('available_at', 6);
            $table->unsignedBigInteger('claim_generation')
                ->default(0);
            $table->char('claim_token', 64)
                ->nullable();
            $table->timestamp('claimed_at', 6)
                ->nullable();
            $table->timestamp('claim_expires_at', 6)
                ->nullable();
            $table->char('authorized_catalog_revision', 64)
                ->nullable();
            $table->char('authorized_policy_revision', 64)
                ->nullable();
            $table->timestamp('dispatch_attempted_at', 6)
                ->nullable();
            $table->timestamp('repair_available_at', 6)
                ->nullable();
            $table->timestamp('completed_at', 6)
                ->nullable();
            $table->text('last_error')
                ->nullable();
            $table->timestamps(6);

            $table->index(['state', 'available_at'], 'wf_service_dispatch_due_idx');
            $table->index(['state', 'claim_expires_at'], 'wf_service_dispatch_claim_idx');
            $table->index(['state', 'repair_available_at'], 'wf_service_dispatch_repair_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
