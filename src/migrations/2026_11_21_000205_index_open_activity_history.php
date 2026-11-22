<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\OpenActivityHistoryIndex;

return new class() extends WorkflowMigration {
    public $withinTransaction = false;

    public function up(): void
    {
        $schema = DB::connection($this->getConnection())->getSchemaBuilder();
        foreach ($this->tables('history_event_model', WorkflowHistoryEvent::class) as $table) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            $this->addSequenceColumn($table, OpenActivityHistoryIndex::SEQUENCE);
            $this->createIndexOnline($table, $this->index($table), [
                'workflow_run_id', OpenActivityHistoryIndex::SEQUENCE,
            ]);
        }
        foreach ($this->tables('run_model', WorkflowRun::class) as $table) {
            if ($schema->hasTable($table)) {
                $this->addSequenceColumn($table, OpenActivityHistoryIndex::THROUGH);
            }
        }
    }

    public function down(): void
    {
        $schema = DB::connection($this->getConnection())->getSchemaBuilder();
        foreach ($this->tables('run_model', WorkflowRun::class) as $table) {
            if ($schema->hasColumn($table, OpenActivityHistoryIndex::THROUGH)) {
                $this->withSchemaLockBudget(static fn () => $schema->table(
                    $table,
                    static fn (Blueprint $blueprint) => $blueprint->dropColumn(OpenActivityHistoryIndex::THROUGH),
                ));
            }
        }
        foreach ($this->tables('history_event_model', WorkflowHistoryEvent::class) as $table) {
            $index = $this->index($table);
            if ($schema->hasTable($table) && $schema->hasIndex($table, $index)) {
                $this->withSchemaLockBudget(static fn () => $schema->table(
                    $table,
                    static fn (Blueprint $blueprint) => $blueprint->dropIndex($index),
                ));
            }
            if ($schema->hasColumn($table, OpenActivityHistoryIndex::SEQUENCE)) {
                $this->withSchemaLockBudget(static fn () => $schema->table(
                    $table,
                    static fn (Blueprint $blueprint) => $blueprint->dropColumn(OpenActivityHistoryIndex::SEQUENCE),
                ));
            }
        }
    }

    private function addSequenceColumn(string $table, string $column): void
    {
        $schema = DB::connection($this->getConnection())->getSchemaBuilder();
        if (! $schema->hasColumn($table, $column)) {
            $this->withSchemaLockBudget(static fn () => $schema->table(
                $table,
                static fn (Blueprint $blueprint) => $blueprint->unsignedInteger($column)
                    ->nullable(),
            ));
        }
    }

    /**
     * @param class-string<Model> $default
     * @return list<string>
     */
    private function tables(string $key, string $default): array
    {
        $model = ConfiguredV2Models::resolve($key, $default);

        return array_values(array_unique([(new $default())->getTable(), (new $model())->getTable()]));
    }

    private function index(string $table): string
    {
        $prefix = DB::connection($this->getConnection())->getTablePrefix();

        return 'dw_history_open_activity_' . substr(hash('sha256', $prefix . $table), 0, 12);
    }
};
