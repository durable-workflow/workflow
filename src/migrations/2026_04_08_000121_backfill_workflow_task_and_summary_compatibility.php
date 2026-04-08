<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration {
    public function up(): void
    {
        DB::table('workflow_tasks')
            ->join('workflow_runs', 'workflow_runs.id', '=', 'workflow_tasks.workflow_run_id')
            ->whereNull('workflow_tasks.compatibility')
            ->whereNotNull('workflow_runs.compatibility')
            ->select(['workflow_tasks.id', 'workflow_runs.compatibility'])
            ->orderBy('workflow_tasks.id')
            ->chunk(100, static function ($tasks): void {
                foreach ($tasks as $task) {
                    DB::table('workflow_tasks')
                        ->where('id', $task->id)
                        ->update([
                            'compatibility' => $task->compatibility,
                        ]);
                }
            });

        DB::table('workflow_run_summaries')
            ->join('workflow_runs', 'workflow_runs.id', '=', 'workflow_run_summaries.id')
            ->whereNull('workflow_run_summaries.compatibility')
            ->whereNotNull('workflow_runs.compatibility')
            ->select(['workflow_run_summaries.id', 'workflow_runs.compatibility'])
            ->orderBy('workflow_run_summaries.id')
            ->chunk(100, static function ($summaries): void {
                foreach ($summaries as $summary) {
                    DB::table('workflow_run_summaries')
                        ->where('id', $summary->id)
                        ->update([
                            'compatibility' => $summary->compatibility,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Backfill-only migration.
    }
};
