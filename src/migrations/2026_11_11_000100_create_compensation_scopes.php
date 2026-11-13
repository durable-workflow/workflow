<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        Schema::create('workflow_compensation_scopes', static function (Blueprint $table): void {
            $table->string('id', 64)
                ->primary();
            $table->text('identity');
            $table->string('original_run_id', 128)
                ->index();
            $table->string('status', 32);
            $table->unsignedInteger('obligation_count')
                ->default(0);
            $table->unsignedInteger('request_count')
                ->default(0);
            $table->unsignedInteger('event_sequence')
                ->default(0);
        });
        Schema::create('workflow_compensation_obligations', static function (Blueprint $table): void {
            $table->string('id', 64)
                ->primary();
            $table->string('scope_id', 64)
                ->index();
            $table->unsignedInteger('position');
            $table->string('fingerprint', 64);
            $table->text('descriptor');
            $table->string('forward_status', 32);
            $table->string('compensation_status', 32);
            $table->string('forward_activity_id', 26)
                ->nullable();
            $table->string('forward_attempt_id', 26)
                ->nullable();
            $table->string('compensation_attempt_id', 26)
                ->nullable();
            $table->text('forward_result')
                ->nullable();
            $table->text('evidence')
                ->nullable();
            $table->unique(['scope_id', 'position'], 'compensation_obligation_position');
        });
        Schema::create('workflow_compensation_graphs', static function (Blueprint $table): void {
            $table->string('id', 64)
                ->primary();
        });
        Schema::create('workflow_compensation_children', static function (Blueprint $table): void {
            $table->string('child_id', 64)
                ->primary();
            $table->string('parent_id', 64)
                ->index();
            $table->string('obligation_id', 64)
                ->unique();
        });
        Schema::create('workflow_compensation_requests', static function (Blueprint $table): void {
            $table->string('id', 64)
                ->primary();
            $table->string('scope_id', 64)
                ->index();
            $table->unsignedInteger('position');
            $table->string('fingerprint', 64);
            $table->text('descriptor');
            $table->string('authorization_policy_version', 128)
                ->nullable();
            $table->unique(['scope_id', 'position'], 'compensation_request_position');
        });
        Schema::create('workflow_compensation_journal', static function (Blueprint $table): void {
            $table->string('scope_id', 64);
            $table->unsignedInteger('sequence');
            $table->string('kind', 32);
            $table->text('data');
            $table->timestamp('recorded_at', 6);
            $table->primary(['scope_id', 'sequence'], 'compensation_journal_sequence');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_compensation_journal');
        Schema::dropIfExists('workflow_compensation_obligations');
        Schema::dropIfExists('workflow_compensation_requests');
        Schema::dropIfExists('workflow_compensation_children');
        Schema::dropIfExists('workflow_compensation_graphs');
        Schema::dropIfExists('workflow_compensation_scopes');
    }
};
