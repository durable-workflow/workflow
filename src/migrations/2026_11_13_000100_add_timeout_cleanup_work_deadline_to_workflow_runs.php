<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    /**
     * Deadline-triggered cleanup (#863): the derived work deadline of a run
     * that opted in, so the watchdog can wake an idle run at that time.
     *
     * The column is nullable with no backfill: only runs started with timeout
     * cleanup set it. It is added under the schema lock budget and the index is
     * built last without blocking writes (CREATE INDEX CONCURRENTLY on
     * PostgreSQL, LOCK=NONE on MySQL and MariaDB). Each step skips work that is
     * already done, so an interrupted run can simply be run again.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('workflow_runs', 'timeout_cleanup_work_deadline_at')) {
            $this->withSchemaLockBudget(static function (): void {
                Schema::table('workflow_runs', static function (Blueprint $table): void {
                    $table->timestamp('timeout_cleanup_work_deadline_at', 6)
                        ->nullable();
                });
            });
        }

        $this->createIndexOnline('workflow_runs', 'workflow_runs_timeout_cleanup_work_deadline_idx', [
            'timeout_cleanup_work_deadline_at',
        ]);
    }

    public function down(): void
    {
        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->dropIndex('workflow_runs_timeout_cleanup_work_deadline_idx');
            $table->dropColumn('timeout_cleanup_work_deadline_at');
        });
    }
};
