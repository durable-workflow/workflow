<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ConfiguredV2Models;

return new class() extends WorkflowMigration {
    public $withinTransaction = false;

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach ($this->tables('history_event_model', WorkflowHistoryEvent::class) as $table) {
            if (! $schema->hasTable($table)) {
                continue;
            }

            if (! $schema->hasColumn($table, 'indexed_activity_execution_id')) {
                $this->withSchemaLockBudget(
                    static fn () => $schema->table($table, static function (Blueprint $table): void {
                        $table->string('indexed_activity_execution_id', 26)
                            ->nullable();
                    })
                );
            }
            $this->createIndexOnline($table, $this->indexName($table), [
                'workflow_run_id',
                'indexed_activity_execution_id',
                'sequence',
            ]);
        }

        foreach ($this->tables('activity_execution_model', ActivityExecution::class) as $table) {
            if ($schema->hasTable($table)) {
                $this->createIndexOnline($table, $this->openActivityIndexName($table), [
                    'workflow_run_id', 'status', 'sequence',
                ]);
            }
        }

        foreach ($this->tables('run_model', WorkflowRun::class) as $table) {
            if ($schema->hasTable($table) && ! $schema->hasColumn($table, 'activity_history_index_through')) {
                $this->withSchemaLockBudget(
                    static fn () => $schema->table($table, static function (Blueprint $table): void {
                        $table->unsignedInteger('activity_history_index_through')
                            ->nullable();
                    })
                );
            }
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach ($this->tables('run_model', WorkflowRun::class) as $table) {
            if ($schema->hasColumn($table, 'activity_history_index_through')) {
                $this->withSchemaLockBudget(
                    static fn () => $schema->table($table, static function (Blueprint $table): void {
                        $table->dropColumn('activity_history_index_through');
                    })
                );
            }
        }

        foreach ($this->tables('activity_execution_model', ActivityExecution::class) as $table) {
            $index = $this->openActivityIndexName($table);
            if ($schema->hasTable($table) && $schema->hasIndex($table, $index)) {
                $this->withSchemaLockBudget(static fn () => $schema->table($table, static function (Blueprint $table) use (
                    $index
                ): void {
                    $table->dropIndex($index);
                }));
            }
        }
        foreach ($this->tables('history_event_model', WorkflowHistoryEvent::class) as $table) {
            if (! $schema->hasColumn($table, 'indexed_activity_execution_id')) {
                continue;
            }

            $index = $this->indexName($table);
            if ($schema->hasIndex($table, $index)) {
                $this->withSchemaLockBudget(static fn () => $schema->table($table, static function (Blueprint $table) use (
                    $index
                ): void {
                    $table->dropIndex($index);
                }));
            }
            $this->withSchemaLockBudget(
                static fn () => $schema->table($table, static function (Blueprint $table): void {
                    $table->dropColumn('indexed_activity_execution_id');
                })
            );
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

    private function indexName(string $table): string
    {
        return WorkflowHistoryEvent::activityIndexName(
            $table,
            DB::connection($this->getConnection())->getTablePrefix()
        );
    }

    private function openActivityIndexName(string $table): string
    {
        $prefix = DB::connection($this->getConnection())->getTablePrefix();

        return 'dw_run_open_activity_' . substr(hash('sha256', $prefix . $table), 0, 12);
    }
};
