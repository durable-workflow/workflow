<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workflow_run_waits', static function (Blueprint $table): void {
            $table->string('id', 64)
                ->primary();
            $table->string('workflow_run_id', 26)
                ->index();
            $table->string('workflow_instance_id', 191)
                ->index();
            $table->string('wait_id', 191);
            $table->unsignedInteger('position');
            $table->string('kind')
                ->index();
            $table->unsignedInteger('sequence')
                ->nullable()
                ->index();
            $table->string('status')
                ->index();
            $table->string('source_status')
                ->nullable()
                ->index();
            $table->text('summary')
                ->nullable();
            $table->timestamp('opened_at', 6)
                ->nullable();
            $table->timestamp('deadline_at', 6)
                ->nullable();
            $table->timestamp('resolved_at', 6)
                ->nullable();
            $table->string('target_name', 191)
                ->nullable()
                ->index();
            $table->string('target_type', 191)
                ->nullable();
            $table->boolean('task_backed')
                ->default(false)
                ->index();
            $table->boolean('external_only')
                ->default(false);
            $table->string('resume_source_kind', 191)
                ->nullable()
                ->index();
            $table->string('resume_source_id', 191)
                ->nullable()
                ->index();
            $table->string('task_id', 26)
                ->nullable()
                ->index();
            $table->string('task_type')
                ->nullable();
            $table->string('task_status')
                ->nullable();
            $table->string('command_id', 26)
                ->nullable()
                ->index();
            $table->unsignedInteger('command_sequence')
                ->nullable();
            $table->string('command_status')
                ->nullable();
            $table->string('command_outcome')
                ->nullable();
            $table->string('history_authority')
                ->nullable()
                ->index();
            $table->string('history_unsupported_reason')
                ->nullable();
            $table->json('payload')
                ->nullable();
            $table->timestamps(6);

            $table->unique(['workflow_run_id', 'wait_id'], 'workflow_run_waits_run_wait_unique');
            $table->index(['workflow_run_id', 'status', 'position'], 'workflow_run_waits_run_status_position_index');
            $table->index(['workflow_instance_id', 'kind', 'status'], 'workflow_run_waits_instance_kind_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_run_waits');
    }
};
