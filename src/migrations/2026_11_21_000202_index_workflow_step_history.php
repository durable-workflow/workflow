<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ConfiguredV2Models;

return new class() extends WorkflowMigration {
    public $withinTransaction = false;

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach ($this->historyTables() as $name) {
            if (! $schema->hasTable($name)) {
                continue;
            }
            if (! $schema->hasColumn($name, 'indexed_step_sequence')) {
                $this->withSchemaLockBudget(
                    static fn () => $schema->table($name, static function (Blueprint $table): void {
                        $table->unsignedBigInteger('indexed_step_sequence')
                            ->nullable();
                    }),
                );
            }
            $this->createIndexOnline($name, $this->indexName($name), [
                'workflow_run_id', 'indexed_step_sequence', 'sequence',
            ]);
        }

        foreach ($this->runTables() as $name) {
            if ($schema->hasTable($name) && ! $schema->hasColumn($name, 'step_history_index_through')) {
                $this->withSchemaLockBudget(
                    static fn () => $schema->table($name, static function (Blueprint $table): void {
                        $table->unsignedInteger('step_history_index_through')
                            ->nullable();
                    }),
                );
            }
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach ($this->runTables() as $name) {
            if ($schema->hasColumn($name, 'step_history_index_through')) {
                $this->withSchemaLockBudget(
                    static fn () => $schema->table($name, static function (Blueprint $table): void {
                        $table->dropColumn('step_history_index_through');
                    }),
                );
            }
        }

        foreach ($this->historyTables() as $name) {
            $index = $this->indexName($name);
            if ($schema->hasTable($name) && $schema->hasIndex($name, $index)) {
                $this->withSchemaLockBudget(
                    static fn () => $schema->table($name, static function (Blueprint $table) use ($index): void {
                        $table->dropIndex($index);
                    }),
                );
            }
            if ($schema->hasColumn($name, 'indexed_step_sequence')) {
                $this->withSchemaLockBudget(
                    static fn () => $schema->table($name, static function (Blueprint $table): void {
                        $table->dropColumn('indexed_step_sequence');
                    }),
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function historyTables(): array
    {
        $model = ConfiguredV2Models::resolve('history_event_model', WorkflowHistoryEvent::class);

        return array_values(array_unique([(new WorkflowHistoryEvent())->getTable(), (new $model())->getTable()]));
    }

    /**
     * @return list<string>
     */
    private function runTables(): array
    {
        $model = ConfiguredV2Models::resolve('run_model', WorkflowRun::class);

        return array_values(array_unique([(new WorkflowRun())->getTable(), (new $model())->getTable()]));
    }

    private function indexName(string $table): string
    {
        return WorkflowHistoryEvent::stepIndexName($table, DB::connection($this->getConnection())->getTablePrefix());
    }
};
