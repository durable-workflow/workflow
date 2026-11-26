<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\ConfiguredV2Models;

return new class() extends WorkflowMigration {
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
        $schema = Schema::connection($this->getConnection());

        foreach (self::summaryTables() as $table) {
            if ($schema->hasTable($table) && ! $schema->hasColumn($table, 'timeout_cleanup')) {
                $this->withSchemaLockBudget(static function () use ($schema, $table): void {
                    $schema->table($table, static function (Blueprint $blueprint): void {
                        $blueprint->json('timeout_cleanup')
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
            if ($schema->hasTable($table) && $schema->hasColumn($table, 'timeout_cleanup')) {
                $schema->table($table, static function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('timeout_cleanup');
                });
            }
        }
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
