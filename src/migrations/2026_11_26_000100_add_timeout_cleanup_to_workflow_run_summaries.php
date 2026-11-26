<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\ConfiguredV2Models;

return new class() extends WorkflowMigration {
    private const COLUMN = 'timeout_cleanup';

    /**
     * Deadline-triggered cleanup (#863): the run summary's additive
     * `timeout_cleanup` field, the same portable shape describe and the run
     * observation return, so run lists show the cleanup outcome.
     *
     * The column is nullable and nothing is backfilled: a summary gains the
     * value the next time it is projected. The summary table is hot, so the
     * column is added outside the migrator transaction under the schema lock
     * budget, and a repeated run skips work already done.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $schema = $this->schema();

        foreach ($this->summaryTables($schema, withColumn: false) as $table) {
            $this->withSchemaLockBudget(static fn () => $schema->table(
                $table,
                static fn (Blueprint $blueprint) => $blueprint->json(self::COLUMN)
                    ->nullable(),
            ));
        }
    }

    public function down(): void
    {
        $schema = $this->schema();

        foreach ($this->summaryTables($schema, withColumn: true) as $table) {
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->dropColumn(self::COLUMN));
        }
    }

    private function schema(): Builder
    {
        return Schema::connection($this->getConnection());
    }

    /**
     * The existing run-summary tables (the package table and a configured
     * model's table) that have, or lack, the column.
     *
     * @return list<string>
     */
    private function summaryTables(Builder $schema, bool $withColumn): array
    {
        $model = ConfiguredV2Models::resolve('run_summary_model', WorkflowRunSummary::class);
        $tables = array_unique(['workflow_run_summaries', (new $model())->getTable()]);

        return array_values(array_filter(
            $tables,
            static fn (string $table): bool => $schema->hasTable($table)
                && $schema->hasColumn($table, self::COLUMN) === $withColumn,
        ));
    }
};
