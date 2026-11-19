<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ConfiguredV2Models;

return new class() extends WorkflowMigration {
    /**
     * Lost workflow-task leases: the leases a workflow task lost without
     * completing, and the run summary's additive task_attention field.
     *
     * Every column is nullable or has a constant default, so nothing is
     * backfilled. Both tables are hot, so each column is added outside the
     * migrator transaction under the schema lock budget: an ALTER that would
     * wait behind an open task transaction fails fast instead of queueing
     * every read behind it. Each step skips work already done, so an
     * interrupted run can simply be run again.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach (self::taskTables() as $table) {
            if ($schema->hasTable($table) && ! $schema->hasColumn($table, 'lost_lease_count')) {
                $this->withSchemaLockBudget(static function () use ($schema, $table): void {
                    $schema->table($table, static function (Blueprint $blueprint): void {
                        $blueprint->unsignedInteger('lost_lease_count')
                            ->default(0);
                    });
                });
            }
            if ($schema->hasTable($table) && ! $schema->hasColumn($table, 'last_lease_lost_at')) {
                $this->withSchemaLockBudget(static function () use ($schema, $table): void {
                    $schema->table($table, static function (Blueprint $blueprint): void {
                        $blueprint->timestamp('last_lease_lost_at', 6)
                            ->nullable();
                    });
                });
            }
        }

        foreach (self::summaryTables() as $table) {
            if ($schema->hasTable($table) && ! $schema->hasColumn($table, 'task_attention')) {
                $this->withSchemaLockBudget(static function () use ($schema, $table): void {
                    $schema->table($table, static function (Blueprint $blueprint): void {
                        $blueprint->json('task_attention')
                            ->nullable();
                    });
                });
            }
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach (self::summaryTables() as $table) {
            if ($schema->hasTable($table) && $schema->hasColumn($table, 'task_attention')) {
                $schema->table($table, static function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('task_attention');
                });
            }
        }

        foreach (self::taskTables() as $table) {
            foreach (['last_lease_lost_at', 'lost_lease_count'] as $column) {
                if ($schema->hasTable($table) && $schema->hasColumn($table, $column)) {
                    $schema->table($table, static function (Blueprint $blueprint) use ($column): void {
                        $blueprint->dropColumn($column);
                    });
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function taskTables(): array
    {
        $model = ConfiguredV2Models::resolve('task_model', WorkflowTask::class);

        return array_values(array_unique(['workflow_tasks', (new $model())->getTable()]));
    }

    /**
     * @return list<string>
     */
    private static function summaryTables(): array
    {
        $model = ConfiguredV2Models::resolve('run_summary_model', WorkflowRunSummary::class);

        return array_values(array_unique(['workflow_run_summaries', (new $model())->getTable()]));
    }
};
