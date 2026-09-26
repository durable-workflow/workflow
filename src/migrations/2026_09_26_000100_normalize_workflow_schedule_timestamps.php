<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Support\UtcScheduleTimestamp;

return new class() extends WorkflowMigration {
    private const COLUMNS = ['next_fire_at', 'last_fired_at', 'paused_at', 'deleted_at', 'last_skipped_at'];

    public function up(): void
    {
        if (! Schema::connection($this->getConnection())->hasTable('workflow_schedules')) {
            return;
        }

        // Old Eloquent datetime casts interpreted these offset-free values in
        // PHP's default timezone. Preserve that observable interpretation.
        // An operator can identify a different original writer timezone via
        // the explicit one-time override before running this migration.
        $legacyTimezone = config('workflows.v2.legacy_schedule_storage_timezone')
            ?: date_default_timezone_get();
        $connection = DB::connection($this->getConnection());

        $connection->transaction(static function () use ($connection, $legacyTimezone): void {
            $connection->table('workflow_schedules')
                ->select(['id', ...self::COLUMNS])
                ->orderBy('id')
                ->chunkById(250, static function ($schedules) use ($connection, $legacyTimezone): void {
                    foreach ($schedules as $schedule) {
                        $updates = [];

                        foreach (self::COLUMNS as $column) {
                            $raw = $schedule->{$column};
                            if ($raw === null) {
                                continue;
                            }

                            $date = Carbon::parse((string) $raw, $legacyTimezone);
                            $updates[$column] = UtcScheduleTimestamp::databaseValue($date);
                        }

                        if ($updates !== []) {
                            $connection->table('workflow_schedules')
                                ->where('id', $schedule->id)
                                ->update($updates);
                        }
                    }
                }, 'id');
        });
    }

    public function down(): void
    {
        // UTC timestamps are the durable representation. Converting back
        // would lose the distinction between the two repeated local times.
    }
};
