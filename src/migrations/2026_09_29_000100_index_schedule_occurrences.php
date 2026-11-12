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

    /**
     * The column is added and committed first. Rows are then filled in
     * batches that each commit on their own, and the index is built last
     * without blocking writes. Every step skips work that is already done,
     * so an interrupted run can simply be run again.
     */
    public $withinTransaction = false;

    public int $backfillBatchSize = 500;

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'occurrence_at_utc')) {
            $this->withSchemaLockBudget(static function (): void {
                Schema::table(self::TABLE, static function (Blueprint $table): void {
                    $table->string('occurrence_at_utc', 26)
                        ->nullable();
                });
            });
        }

        DB::table(self::TABLE)
            ->select(['id', 'payload'])
            ->where('event_type', 'ScheduleTriggered')
            ->whereNull('occurrence_at_utc')
            ->orderBy('id')
            ->chunkById($this->backfillBatchSize, static fn ($events) => DB::transaction(static function () use (
                $events
            ): void {
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
                        ->whereNull('occurrence_at_utc')
                        ->update([
                            'occurrence_at_utc' => $key,
                        ]);
                }
            }));

        $this->createIndexOnline(self::TABLE, self::INDEX, [
            'workflow_schedule_id',
            'event_type',
            'occurrence_at_utc',
        ]);
    }

    public function down(): void
    {
        Schema::table(self::TABLE, static function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
            $table->dropColumn('occurrence_at_utc');
        });
    }
};
