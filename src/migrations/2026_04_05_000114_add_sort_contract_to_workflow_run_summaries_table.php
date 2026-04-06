<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\V2\Support\RunSummarySortKey;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->timestamp('sort_timestamp', 6)
                ->nullable()
                ->after('started_at');
            $table->string('sort_key', 64)
                ->nullable()
                ->after('sort_timestamp');
            $table->index(['sort_timestamp', 'id'], 'workflow_run_summaries_sort_order_index');
            $table->index('sort_key', 'workflow_run_summaries_sort_key_index');
        });

        DB::table('workflow_run_summaries')
            ->select(['id', 'started_at', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunk(100, static function ($rows): void {
                foreach ($rows as $row) {
                    $sortTimestamp = RunSummarySortKey::timestamp(
                        $row->started_at,
                        $row->created_at,
                        $row->updated_at,
                    );

                    if ($sortTimestamp === null) {
                        continue;
                    }

                    DB::table('workflow_run_summaries')
                        ->where('id', $row->id)
                        ->update([
                            'sort_timestamp' => $sortTimestamp,
                            'sort_key' => RunSummarySortKey::key(
                                $row->started_at,
                                $row->created_at,
                                $row->updated_at,
                                $row->id,
                            ),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->dropIndex('workflow_run_summaries_sort_order_index');
            $table->dropIndex('workflow_run_summaries_sort_key_index');
            $table->dropColumn(['sort_timestamp', 'sort_key']);
        });
    }
};
