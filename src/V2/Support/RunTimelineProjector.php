<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTimelineEntry;

final class RunTimelineProjector
{
    /**
     * @param list<array<string, mixed>>|null $entries
     * @return list<WorkflowTimelineEntry>
     */
    public static function project(WorkflowRun $run, ?array $entries = null): array
    {
        $entries ??= HistoryTimeline::fromHistory($run);
        $entryModel = self::entryModel();
        $seen = [];
        $projected = [];

        foreach (array_values($entries) as $entry) {
            $historyEventId = self::stringValue($entry['id'] ?? null);

            if ($historyEventId === null) {
                continue;
            }

            $projectionId = self::projectionId($run->id, $historyEventId);
            $seen[] = $projectionId;

            /** @var WorkflowTimelineEntry $row */
            $row = $entryModel::query()->updateOrCreate(
                [
                    'id' => $projectionId,
                ],
                [
                    'workflow_run_id' => $run->id,
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'history_event_id' => $historyEventId,
                    'sequence' => self::intValue($entry['sequence'] ?? null) ?? 0,
                    'type' => self::stringValue($entry['type'] ?? null) ?? 'Unknown',
                    'kind' => self::stringValue($entry['kind'] ?? null) ?? 'workflow',
                    'entry_kind' => self::stringValue($entry['entry_kind'] ?? null) ?? 'point',
                    'source_kind' => self::stringValue($entry['source_kind'] ?? null),
                    'source_id' => self::stringValue($entry['source_id'] ?? null),
                    'summary' => self::stringValue($entry['summary'] ?? null),
                    'recorded_at' => self::timestamp($entry['recorded_at'] ?? null),
                    'command_id' => self::stringValue($entry['command_id'] ?? null),
                    'command_sequence' => self::intValue($entry['command_sequence'] ?? null),
                    'task_id' => self::stringValue($entry['task_id'] ?? null),
                    'activity_execution_id' => self::stringValue($entry['activity_execution_id'] ?? null),
                    'timer_id' => self::stringValue($entry['timer_id'] ?? null),
                    'failure_id' => self::stringValue($entry['failure_id'] ?? null),
                    'payload' => self::normalizedPayload($entry),
                ],
            );

            $projected[] = $row;
        }

        StaleProjectionCleanup::forRun($entryModel, $run->id, $seen);

        $run->unsetRelation('timelineEntries');

        return $projected;
    }

    /**
     * @return array{source: string, timeline: list<array<string, mixed>>}
     */
    public static function snapshotForRun(WorkflowRun $run): array
    {
        $projected = self::projectedRows($run);
        $canonicalTimeline = HistoryTimeline::fromHistory($run);

        if ($projected->isEmpty() && $canonicalTimeline === []) {
            return [
                'source' => 'workflow_run_timeline_entries',
                'timeline' => [],
            ];
        }

        if ($projected->isNotEmpty() && self::projectionMatchesHistory($projected, $canonicalTimeline)) {
            return [
                'source' => 'workflow_run_timeline_entries',
                'timeline' => $projected
                    ->map(static fn (WorkflowTimelineEntry $entry): array => $entry->toTimelinePayload())
                    ->values()
                    ->all(),
            ];
        }

        $reprojected = self::project($run, $canonicalTimeline);

        return [
            'source' => 'workflow_run_timeline_entries_rebuilt',
            'timeline' => collect($reprojected)
                ->map(static fn (WorkflowTimelineEntry $entry): array => $entry->toTimelinePayload())
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{
     *     has_projection: bool,
     *     has_canonical: bool,
     *     missing: bool,
     *     stale: bool
     * }
     */
    public static function driftStatusForRun(WorkflowRun $run): array
    {
        $projected = self::projectedRows($run);
        $canonicalTimeline = HistoryTimeline::fromHistory($run);
        $hasProjection = $projected->isNotEmpty();
        $hasCanonical = $canonicalTimeline !== [];

        return [
            'has_projection' => $hasProjection,
            'has_canonical' => $hasCanonical,
            'missing' => $hasCanonical && ! $hasProjection,
            'stale' => $hasProjection && ! self::projectionMatchesHistory($projected, $canonicalTimeline),
        ];
    }

    /**
     * @return class-string<WorkflowTimelineEntry>
     */
    private static function entryModel(): string
    {
        /** @var class-string<WorkflowTimelineEntry> $model */
        $model = config('workflows.v2.run_timeline_entry_model', WorkflowTimelineEntry::class);

        return $model;
    }

    /**
     * @return EloquentCollection<int, WorkflowTimelineEntry>
     */
    private static function projectedRows(WorkflowRun $run): EloquentCollection
    {
        if ($run->relationLoaded('timelineEntries')) {
            /** @var EloquentCollection<int, WorkflowTimelineEntry> $entries */
            $entries = $run->timelineEntries;

            return $entries;
        }

        $entryModel = self::entryModel();

        /** @var EloquentCollection<int, WorkflowTimelineEntry> $entries */
        $entries = $entryModel::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->orderBy('history_event_id')
            ->get();

        return $entries;
    }

    /**
     * @param EloquentCollection<int, WorkflowTimelineEntry> $entries
     * @param list<array<string, mixed>> $canonical
     */
    private static function projectionMatchesHistory(EloquentCollection $entries, array $canonical): bool
    {
        return self::canonicalEntries(
            $entries
                ->map(static fn (WorkflowTimelineEntry $entry): array => $entry->toTimelinePayload())
                ->values()
                ->all()
        ) === self::canonicalEntries($canonical);
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

    private static function projectionId(string $runId, string $historyEventId): string
    {
        return hash('sha256', $runId . '|' . $historyEventId);
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

    private static function timestamp(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        return is_string($value) && $value !== ''
            ? Carbon::parse($value)
            : null;
    }
}
