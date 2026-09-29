<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;

return new class() extends WorkflowMigration {
    private const TABLE = 'workflow_schedule_history_events';

    private const INDEX = 'wf_schedule_history_occurrence_idx';

    public function up(): void
    {
        Schema::table(self::TABLE, static function (Blueprint $table): void {
            $table->string('occurrence_at_utc', 26)
                ->nullable();
            $table->index(['workflow_schedule_id', 'event_type', 'occurrence_at_utc'], self::INDEX);
        });

        DB::table(self::TABLE)
            ->where('event_type', 'ScheduleTriggered')
            ->orderBy('id')
            ->chunkById(500, static function ($events): void {
                foreach ($events as $event) {
                    $payload = json_decode((string) $event->payload, true);
                    $occurrence = is_array($payload) ? ($payload['occurrence_time'] ?? null) : null;

                    if (! is_string($occurrence) || $occurrence === '') {
                        continue;
                    }

                    try {
                        $key = WorkflowScheduleHistoryEvent::utcOccurrenceKey(new DateTimeImmutable($occurrence));
                    } catch (Exception) {
                        continue;
                    }

                    DB::table(self::TABLE)
                        ->where('id', $event->id)
                        ->update([
                            'occurrence_at_utc' => $key,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table(self::TABLE, static function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
            $table->dropColumn('occurrence_at_utc');
        });
    }
};
