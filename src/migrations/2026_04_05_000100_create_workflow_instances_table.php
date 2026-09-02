<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        Schema::create('workflow_instances', static function (Blueprint $table): void {
            $table->string('id', 191)
                ->primary();
            $table->string('workflow_class');
            $table->string('workflow_type');
            $table->string('namespace')
                ->nullable()
                ->index();
            $table->string('business_key', 191)
                ->nullable()
                ->index('workflow_instances_business_key_index');
            $table->json('visibility_labels')
                ->nullable();
            $table->json('memo')
                ->nullable();
            $table->unsignedInteger('execution_timeout_seconds')
                ->nullable();
            $table->string('current_run_id', 26)
                ->nullable()
                ->index();
            $table->unsignedInteger('run_count')
                ->default(0);
            $table->unsignedInteger('last_message_sequence')
                ->default(0);
            $table->timestamp('reserved_at', 6)
                ->nullable();
            $table->timestamp('started_at', 6)
                ->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_instances');
    }
};
