<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_signal_records', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_command_id', 26)
                ->unique();
            $table->string('workflow_instance_id', 191)
                ->nullable()
                ->index();
            $table->string('workflow_run_id', 26)
                ->nullable()
                ->index();
            $table->string('target_scope')
                ->default('instance');
            $table->string('requested_workflow_run_id', 26)
                ->nullable()
                ->index();
            $table->string('resolved_workflow_run_id', 26)
                ->nullable()
                ->index();
            $table->string('signal_name')
                ->index();
            $table->string('signal_wait_id')
                ->nullable()
                ->index();
            $table->string('status')
                ->index();
            $table->string('outcome')
                ->nullable()
                ->index();
            $table->unsignedInteger('command_sequence')
                ->nullable()
                ->index();
            $table->unsignedInteger('workflow_sequence')
                ->nullable()
                ->index();
            $table->string('payload_codec')
                ->nullable();
            $table->longText('arguments')
                ->nullable();
            $table->json('validation_errors')
                ->nullable();
            $table->string('rejection_reason')
                ->nullable();
            $table->timestamp('received_at', 6)
                ->nullable();
            $table->timestamp('applied_at', 6)
                ->nullable();
            $table->timestamp('rejected_at', 6)
                ->nullable();
            $table->timestamp('closed_at', 6)
                ->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_signal_records');
    }
};
