<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        Schema::create('workflow_commands', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_instance_id', 191)
                ->nullable()
                ->index();
            $table->string('workflow_run_id', 26)
                ->nullable()
                ->index();
            $table->string('requested_workflow_run_id', 26)
                ->nullable()
                ->index();
            $table->string('resolved_workflow_run_id', 26)
                ->nullable()
                ->index();
            $table->string('command_type')
                ->index();
            $table->string('target_scope')
                ->default('instance');
            $table->string('source')
                ->default('php')
                ->index();
            $table->json('context')
                ->nullable();
            $table->string('request_id', 191)
                ->nullable();
            $table->string('status')
                ->index();
            $table->string('outcome')
                ->nullable();
            $table->string('workflow_class')
                ->nullable();
            $table->string('workflow_type')
                ->nullable();
            $table->string('payload_codec')
                ->nullable();
            $table->longText('payload')
                ->nullable();
            $table->string('rejection_reason')
                ->nullable();
            $table->unsignedInteger('command_sequence')
                ->nullable()
                ->index();
            $table->unsignedInteger('message_sequence')
                ->nullable();
            $table->timestamp('accepted_at', 6)
                ->nullable();
            $table->timestamp('applied_at', 6)
                ->nullable();
            $table->timestamp('rejected_at', 6)
                ->nullable();
            $table->timestamps(6);
            $table->unique(
                ['workflow_instance_id', 'command_type', 'request_id'],
                'workflow_commands_request_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_commands');
    }
};
