<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Support\OpenActivityHistoryIndex;

return new class() extends WorkflowMigration {
    public $withinTransaction = false;

    public function up(): void
    {
        $schema = DB::connection($this->getConnection())->getSchemaBuilder();
        if (! $schema->hasTable('workflow_runs') || ! $schema->hasTable('workflow_history_events')) {
            return;
        }
        if (! $schema->hasTable(OpenActivityHistoryIndex::TABLE)) {
            $this->withSchemaLockBudget(static fn () => $schema->create(
                OpenActivityHistoryIndex::TABLE,
                static function (Blueprint $table): void {
                    $table->string('workflow_run_id', 26);
                    $table->string('activity_execution_id', 26);
                    $table->string('history_event_id', 26);
                    $table->unsignedInteger('sequence');
                    $table->primary(['workflow_run_id', 'activity_execution_id']);
                    $table->index(['workflow_run_id', 'sequence'], 'dw_open_activities_run_sequence');
                    $table->index('history_event_id', 'dw_open_activities_event');
                    $table->foreign('history_event_id', 'dw_open_activities_history_reference')
                        ->references('id')
                        ->on('workflow_history_events')
                        ->cascadeOnDelete();
                },
            ));
        }
        if (! $schema->hasColumn('workflow_runs', OpenActivityHistoryIndex::THROUGH)) {
            $this->withSchemaLockBudget(static fn () => $schema->table(
                'workflow_runs',
                static fn (Blueprint $blueprint) => $blueprint->unsignedInteger(OpenActivityHistoryIndex::THROUGH)
                    ->nullable(),
            ));
        }
    }

    public function down(): void
    {
        $schema = DB::connection($this->getConnection())->getSchemaBuilder();
        if ($schema->hasColumn('workflow_runs', OpenActivityHistoryIndex::THROUGH)) {
            $this->withSchemaLockBudget(static fn () => $schema->table(
                'workflow_runs',
                static fn (Blueprint $blueprint) => $blueprint->dropColumn(OpenActivityHistoryIndex::THROUGH),
            ));
        }
        $this->withSchemaLockBudget(static fn () => $schema->dropIfExists(OpenActivityHistoryIndex::TABLE));
    }
};
