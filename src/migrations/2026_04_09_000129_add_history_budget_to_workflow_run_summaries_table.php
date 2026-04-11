<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->unsignedInteger('history_event_count')
                ->default(0)
                ->after('exception_count');
            $table->unsignedBigInteger('history_size_bytes')
                ->default(0)
                ->after('history_event_count');
            $table->boolean('continue_as_new_recommended')
                ->default(false)
                ->after('history_size_bytes')
                ->index('workflow_run_summaries_continue_as_new_recommended_index');
        });

        DB::table('workflow_run_summaries')
            ->select('id')
            ->orderBy('id')
            ->chunk(100, static function ($summaries): void {
                foreach ($summaries as $summary) {
                    $events = DB::table('workflow_history_events')
                        ->where('workflow_run_id', $summary->id)
                        ->select(['event_type', 'payload'])
                        ->get();

                    DB::table('workflow_run_summaries')
                        ->where('id', $summary->id)
                        ->update([
                            'history_event_count' => $events->count(),
                            'history_size_bytes' => $events->sum(static function ($event): int {
                                return strlen((string) $event->event_type) + strlen((string) $event->payload);
                            }),
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->dropIndex('workflow_run_summaries_continue_as_new_recommended_index');
            $table->dropColumn(['history_event_count', 'history_size_bytes', 'continue_as_new_recommended']);
        });
    }
};
