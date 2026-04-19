<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunTimerEntry;

final class RunTimerProjector
{
    /**
     * @param list<array<string, mixed>>|null $timers
     * @return list<WorkflowRunTimerEntry>
     */
    public static function project(WorkflowRun $run, ?array $timers = null): array
    {
        $timers ??= RunTimerView::timersForRun($run);
        $entryModel = self::entryModel();
        $seen = [];
        $projected = [];

        foreach (array_values($timers) as $position => $timer) {
            $timerId = self::stringValue($timer['id'] ?? null);

            if ($timerId === null) {
                continue;
            }

            $projectionId = self::projectionId($run->id, $timerId);
            $seen[] = $projectionId;

            /** @var WorkflowRunTimerEntry $row */
            $row = $entryModel::query()->updateOrCreate(
                [
                    'id' => $projectionId,
                ],
                [
                    'workflow_run_id' => $run->id,
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'timer_id' => $timerId,
                    'schema_version' => WorkflowRunTimerEntry::CURRENT_SCHEMA_VERSION,
                    'position' => $position,
                    'sequence' => self::intValue($timer['sequence'] ?? null),
                    'status' => self::stringValue($timer['status'] ?? null) ?? 'unknown',
                    'source_status' => self::stringValue($timer['source_status'] ?? null),
                    'delay_seconds' => self::intValue($timer['delay_seconds'] ?? null),
                    'fire_at' => self::timestamp($timer['fire_at'] ?? null),
                    'fired_at' => self::timestamp($timer['fired_at'] ?? null),
                    'cancelled_at' => self::timestamp($timer['cancelled_at'] ?? null),
                    'timer_kind' => self::stringValue($timer['timer_kind'] ?? null),
                    'condition_wait_id' => self::stringValue($timer['condition_wait_id'] ?? null),
                    'condition_key' => self::stringValue($timer['condition_key'] ?? null),
                    'condition_definition_fingerprint' => self::stringValue(
                        $timer['condition_definition_fingerprint'] ?? null
                    ),
                    'history_authority' => self::stringValue($timer['history_authority'] ?? null),
                    'history_unsupported_reason' => self::stringValue($timer['history_unsupported_reason'] ?? null),
                    'payload' => self::normalizedPayload($timer),
                ],
            );

            $projected[] = $row;
        }

        StaleProjectionCleanup::forRun($entryModel, $run->id, $seen);

        $run->unsetRelation('timerEntries');

        return $projected;
    }

    /**
     * @return array{
     *     source: string,
     *     timers: list<array<string, mixed>>,
     *     rebuild_reasons: list<string>
     * }
     */
    public static function snapshotForRun(WorkflowRun $run): array
    {
        $projected = self::projectedRows($run);
        $canonicalTimers = RunTimerView::timersForRun($run);
        $rebuildReasons = self::rebuildReasons($projected, $canonicalTimers);

        if ($projected->isEmpty() && $canonicalTimers === []) {
            return [
                'source' => 'workflow_run_timer_entries',
                'timers' => [],
                'rebuild_reasons' => [],
            ];
        }

        if ($rebuildReasons === []) {
            return [
                'source' => 'workflow_run_timer_entries',
                'timers' => $projected
                    ->map(static fn (WorkflowRunTimerEntry $entry): array => $entry->toTimerPayload())
                    ->values()
                    ->all(),
                'rebuild_reasons' => [],
            ];
        }

        $reprojected = self::project($run, $canonicalTimers);

        return [
            'source' => 'workflow_run_timer_entries_rebuilt',
            'timers' => collect($reprojected)
                ->map(static fn (WorkflowRunTimerEntry $entry): array => $entry->toTimerPayload())
                ->values()
                ->all(),
            'rebuild_reasons' => $rebuildReasons,
        ];
    }

    /**
     * @return array{
     *     has_projection: bool,
     *     has_canonical: bool,
     *     missing: bool,
     *     stale: bool,
     *     schema_version_mismatch: bool,
     *     reasons: list<string>
     * }
     */
    public static function driftStatusForRun(WorkflowRun $run): array
    {
        $projected = self::projectedRows($run);
        $canonicalTimers = RunTimerView::timersForRun($run);
        $hasProjection = $projected->isNotEmpty();
        $hasCanonical = $canonicalTimers !== [];
        $reasons = self::rebuildReasons($projected, $canonicalTimers);
        $schemaVersionMismatch = in_array('schema_version_mismatch', $reasons, true);

        return [
            'has_projection' => $hasProjection,
            'has_canonical' => $hasCanonical,
            'missing' => $hasCanonical && ! $hasProjection,
            'stale' => $hasProjection && $reasons !== [],
            'schema_version_mismatch' => $schemaVersionMismatch,
            'reasons' => $reasons,
        ];
    }

    /**
     * @return class-string<WorkflowRunTimerEntry>
     */
    private static function entryModel(): string
    {
        /** @var class-string<WorkflowRunTimerEntry> $model */
        $model = config('workflows.v2.run_timer_entry_model', WorkflowRunTimerEntry::class);

        return $model;
    }

    /**
     * @return EloquentCollection<int, WorkflowRunTimerEntry>
     */
    private static function projectedRows(WorkflowRun $run): EloquentCollection
    {
        if ($run->relationLoaded('timerEntries')) {
            /** @var EloquentCollection<int, WorkflowRunTimerEntry> $entries */
            $entries = $run->timerEntries;

            return $entries;
        }

        $entryModel = self::entryModel();

        /** @var EloquentCollection<int, WorkflowRunTimerEntry> $entries */
        $entries = $entryModel::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('position')
            ->orderBy('timer_id')
            ->get();

        return $entries;
    }

    /**
     * @param EloquentCollection<int, WorkflowRunTimerEntry> $projected
     * @param list<array<string, mixed>> $canonical
     */
    private static function projectionMatchesSnapshot(EloquentCollection $projected, array $canonical): bool
    {
        return self::canonicalEntries(
            $projected
                ->map(static fn (WorkflowRunTimerEntry $entry): array => $entry->toTimerPayload())
                ->values()
                ->all()
        ) === self::canonicalEntries($canonical);
    }

    /**
     * @param EloquentCollection<int, WorkflowRunTimerEntry> $projected
     */
    private static function projectedRowsUseCurrentSchema(EloquentCollection $projected): bool
    {
        return $projected->every(static fn (WorkflowRunTimerEntry $entry): bool => $entry->usesCurrentSchema());
    }

    /**
     * @param EloquentCollection<int, WorkflowRunTimerEntry> $projected
     * @param list<array<string, mixed>> $canonical
     * @return list<string>
     */
    private static function rebuildReasons(EloquentCollection $projected, array $canonical): array
    {
        $reasons = [];

        if ($canonical !== [] && $projected->isEmpty()) {
            $reasons[] = 'missing_projection';
        }

        if ($projected->isNotEmpty() && ! self::projectedRowsUseCurrentSchema($projected)) {
            $reasons[] = 'schema_version_mismatch';
        }

        if ($projected->isNotEmpty() && ! self::projectionMatchesSnapshot($projected, $canonical)) {
            $reasons[] = 'stale_projection';
        }

        return $reasons;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private static function canonicalEntries(array $entries): array
    {
        return array_map(
            static fn (array $entry): array => self::canonicalizeValue(self::normalizedPayload($entry)),
            $entries,
        );
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function normalizedPayload(array $entry): array
    {
        return array_map(static fn (mixed $value): mixed => self::normalizeValue($value), $entry);
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $nested): mixed => self::normalizeValue($nested), $value);
        }

        return $value;
    }

    private static function canonicalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $nested): mixed => self::canonicalizeValue($nested), $value);
        }

        ksort($value);

        foreach ($value as $key => $nested) {
            $value[$key] = self::canonicalizeValue($nested);
        }

        return $value;
    }

    private static function projectionId(string $runId, string $timerId): string
    {
        return hash('sha256', $runId . '|' . $timerId);
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value)
            ? (int) $value
            : null;
    }

    private static function timestamp(mixed $value): ?\Carbon\CarbonInterface
    {
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value;
        }

        return is_string($value) && $value !== ''
            ? Carbon::parse($value)
            : null;
    }
}
