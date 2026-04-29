<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_runs', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_instance_id', 191)
                ->index();
            $table->unsignedInteger('run_number');
            $table->string('workflow_class');
            $table->string('workflow_type');
            $table->string('namespace')
                ->nullable()
                ->index();
            $table->string('business_key', 191)
                ->nullable()
                ->index('workflow_runs_business_key_index');
            $table->json('visibility_labels')
                ->nullable();
            $table->string('status');
            $table->string('closed_reason')
                ->nullable();
            $table->string('compatibility')
                ->nullable();
            $table->string('payload_codec')
                ->nullable();
            $table->longText('arguments')
                ->nullable();
            $table->longText('output')
                ->nullable();
            $table->string('connection')
                ->nullable();
            $table->string('queue')
                ->nullable();
            $table->unsignedInteger('last_history_sequence')
                ->default(0);
            $table->unsignedInteger('last_command_sequence')
                ->default(0);
            $table->unsignedInteger('message_cursor_position')
                ->default(0);
            $table->unsignedInteger('run_timeout_seconds')
                ->nullable();
            $table->timestamp('execution_deadline_at', 6)
                ->nullable();
            $table->timestamp('run_deadline_at', 6)
                ->nullable();
            $table->timestamp('started_at', 6)
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
            $table->timestamp('last_progress_at', 6)
                ->nullable();
            $table->timestamps(6);

            $table->unique(['workflow_instance_id', 'run_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
    }
};
