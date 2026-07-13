<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Workflow\V2\Contracts\HistoryProjectionMaintenanceRole;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;

final class RunLineageProjector
{
    /**
     * @param list<array<string, mixed>>|null $parents
     * @param list<array<string, mixed>>|null $continuedWorkflows
     * @return list<WorkflowRunLineageEntry>
     */
    public static function project(
        WorkflowRun $run,
        ?array $parents = null,
        ?array $continuedWorkflows = null,
    ): array {
        $parents ??= RunLineageView::parentsForRun($run);
        $continuedWorkflows ??= RunLineageView::continuedWorkflowsForRun($run);

        $lineageModel = self::lineageModel();
        $seen = [];
        $projected = [];

        foreach (array_values($parents) as $position => $entry) {
            $projected[] = self::projectEntry($lineageModel, $run, $entry, 'parent', $position, $seen);
        }

        foreach (array_values($continuedWorkflows) as $position => $entry) {
            $projected[] = self::projectEntry($lineageModel, $run, $entry, 'child', $position, $seen);
        }

        self::historyProjectionMaintenanceRole()
            ->pruneStaleProjectionRowsForRun($lineageModel, $run->id, $seen);

        $run->unsetRelation('lineageEntries');

        return array_values(array_filter($projected));
    }

    /**
     * Incrementally apply one terminal child outcome to its projected lineage
     * row. The resolution event snapshots every mutable outcome field, while
     * the existing row retains scheduling metadata such as position, link
     * time, and parent-close policy. This keeps claim-time catch-up bounded to
     * one lineage row without rebuilding the run's growing child relations.
     */
    public static function projectChildResolutionEvent(
        WorkflowRun $run,
        WorkflowHistoryEvent $event,
    ): WorkflowRunLineageEntry {
        if (
            $event->workflow_run_id !== $run->id
            || ! in_array($event->event_type, ChildRunHistory::resolutionEventTypes(), true)
        ) {
            throw new \LogicException('Lineage event must be a child resolution on the projected workflow run.');
        }

        $eventPayload = is_array($event->payload) ? $event->payload : [];
        $sequence = self::intValue($eventPayload['sequence'] ?? null);
        $childCallId = self::stringValue($eventPayload['child_call_id'] ?? null)
            ?? self::stringValue($eventPayload['workflow_link_id'] ?? null);
        $childRunId = self::stringValue($eventPayload['child_workflow_run_id'] ?? null);
        $lineageModel = self::lineageModel();
        $existingQuery = $lineageModel::query()
            ->where('workflow_run_id', $run->id)
            ->where('direction', 'child');

        if ($childCallId !== null) {
            $existingQuery->where(static function ($identity) use ($childCallId): void {
                $identity->where('lineage_id', $childCallId)
                    ->orWhere('child_call_id', $childCallId);
            });
        } elseif ($sequence !== null) {
            $existingQuery->where('sequence', $sequence);
        } elseif ($childRunId !== null) {
            $existingQuery->where('related_workflow_run_id', $childRunId);
        } else {
            throw new \LogicException('Child-resolution lineage projection requires a bounded child locator.');
        }

        /** @var WorkflowRunLineageEntry|null $existing */
        $existing = $existingQuery->limit(1)->first();
        $existingPayload = $existing?->toLineagePayload() ?? [];
        $lineageId = $childCallId
            ?? self::stringValue($existingPayload['id'] ?? null)
            ?? sprintf('child_workflow:%s:%s', $sequence ?? 'unknown', $childRunId ?? 'unknown');
        $status = self::stringValue($eventPayload['child_status'] ?? null)
            ?? match ($event->event_type) {
                HistoryEventType::ChildRunCompleted => RunStatus::Completed->value,
                HistoryEventType::ChildRunFailed => RunStatus::Failed->value,
                HistoryEventType::ChildRunCancelled => RunStatus::Cancelled->value,
                HistoryEventType::ChildRunTerminated => RunStatus::Terminated->value,
                default => throw new \LogicException('Unsupported child-resolution lineage event.'),
            };
        $statusBucket = RunStatus::tryFrom($status)?->statusBucket()->value
            ?? self::stringValue($existingPayload['status_bucket'] ?? null);
        $closedReason = self::stringValue($eventPayload['closed_reason'] ?? null) ?? $status;
        $childInstanceId = self::stringValue($eventPayload['child_workflow_instance_id'] ?? null)
            ?? self::stringValue($existingPayload['workflow_instance_id'] ?? null);
        $childRunId ??= self::stringValue($existingPayload['workflow_run_id'] ?? null);
        $entry = array_merge($existingPayload, array_filter([
            'id' => $lineageId,
            'link_type' => 'child_workflow',
            'child_call_id' => $childCallId
                ?? self::stringValue($existingPayload['child_call_id'] ?? null),
            'sequence' => $sequence ?? self::intValue($existingPayload['sequence'] ?? null),
            'is_primary_parent' => $existing === null
                ? true
                : (bool) $existing->is_primary_parent,
            'child_workflow_id' => $childInstanceId,
            'child_workflow_run_id' => $childRunId,
            'workflow_instance_id' => $childInstanceId,
            'workflow_run_id' => $childRunId,
            'run_number' => self::intValue($eventPayload['child_run_number'] ?? null)
                ?? self::intValue($existingPayload['run_number'] ?? null),
            'workflow_type' => self::stringValue($eventPayload['child_workflow_type'] ?? null)
                ?? self::stringValue($existingPayload['workflow_type'] ?? null),
            'class' => self::stringValue($eventPayload['child_workflow_class'] ?? null)
                ?? self::stringValue($existingPayload['class'] ?? null),
            'status' => $status,
            'status_bucket' => $statusBucket,
            'closed_reason' => $closedReason,
            'created_at' => $existingPayload['created_at']
                ?? $event->recorded_at
                ?? $event->created_at,
            'history_authority' => ChildRunHistory::HISTORY_AUTHORITY_TYPED,
            'diagnostic_only' => false,
        ], static fn (mixed $value): bool => $value !== null));
        $position = $existing?->position
            ?? $lineageModel::query()
                ->where('workflow_run_id', $run->id)
                ->where('direction', 'child')
                ->count();
        $seen = [];
        $projected = self::projectEntry(
            $lineageModel,
            $run,
            $entry,
            'child',
            (int) $position,
            $seen,
        );

        if (! $projected instanceof WorkflowRunLineageEntry) {
            throw new \LogicException('Child-resolution lineage projection requires a lineage identity.');
        }

        $run->unsetRelation('lineageEntries');

        return $projected;
    }

    private static function historyProjectionMaintenanceRole(): HistoryProjectionMaintenanceRole
    {
        /** @var HistoryProjectionMaintenanceRole $role */
        $role = App::make(HistoryProjectionMaintenanceRole::class);

        return $role;
    }

    /**
     * @return array{
     *     source: string,
     *     parents: list<array<string, mixed>>,
     *     continued_workflows: list<array<string, mixed>>
     * }
     */
    public static function snapshotForRun(WorkflowRun $run): array
    {
        $projected = self::projectedRows($run);
        $parents = RunLineageView::parentsForRun($run);
        $continuedWorkflows = RunLineageView::continuedWorkflowsForRun($run);

        if ($projected->isEmpty() && $parents === [] && $continuedWorkflows === []) {
            return [
                'source' => 'workflow_run_lineage_entries',
                'parents' => [],
                'continued_workflows' => [],
            ];
        }

        if ($projected->isNotEmpty() && self::projectionCoversSnapshot($projected, $parents, $continuedWorkflows)) {
            return [
                'source' => 'workflow_run_lineage_entries',
                ...self::payloadsFromProjected($projected),
            ];
        }

        $reprojected = self::project($run, $parents, $continuedWorkflows);

        return [
            'source' => 'workflow_run_lineage_entries_rebuilt',
            ...self::payloadsFromProjected(collect($reprojected)),
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
        $parents = RunLineageView::parentsForRun($run);
        $continuedWorkflows = RunLineageView::continuedWorkflowsForRun($run);
        $hasProjection = $projected->isNotEmpty();
        $hasCanonical = $parents !== [] || $continuedWorkflows !== [];

        return [
            'has_projection' => $hasProjection,
            'has_canonical' => $hasCanonical,
            'missing' => $hasCanonical && ! $hasProjection,
            'stale' => $hasProjection && ! self::projectionCoversSnapshot($projected, $parents, $continuedWorkflows),
        ];
    }

    /**
     * @param class-string<WorkflowRunLineageEntry> $lineageModel
     * @param array<string, mixed> $entry
     * @param array<int, string> $seen
     */
    private static function projectEntry(
        string $lineageModel,
        WorkflowRun $run,
        array $entry,
        string $direction,
        int $position,
        array &$seen,
    ): ?WorkflowRunLineageEntry {
        $lineageId = self::stringValue($entry['id'] ?? null);

        if ($lineageId === null) {
            return null;
        }

        $projectionId = self::projectionId($run->id, $direction, $lineageId);
        $seen[] = $projectionId;

        $instanceId = self::stringValue($entry['workflow_instance_id'] ?? null);
        $runId = self::stringValue($entry['workflow_run_id'] ?? null);

        /** @var WorkflowRunLineageEntry $row */
        $row = IdempotentProjectionUpsert::upsert(
            $lineageModel,
            [
                'id' => $projectionId,
            ],
            [
                'workflow_run_id' => $run->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'direction' => $direction,
                'lineage_id' => $lineageId,
                'position' => $position,
                'link_type' => self::stringValue($entry['link_type'] ?? null) ?? 'unknown',
                'child_call_id' => self::stringValue($entry['child_call_id'] ?? null),
                'sequence' => self::intValue($entry['sequence'] ?? null),
                'is_primary_parent' => (bool) ($entry['is_primary_parent'] ?? false),
                'related_workflow_instance_id' => $instanceId,
                'related_workflow_run_id' => $runId,
                'related_run_number' => self::intValue($entry['run_number'] ?? null),
                'related_workflow_type' => self::stringValue($entry['workflow_type'] ?? null),
                'related_workflow_class' => self::stringValue($entry['class'] ?? null),
                'status' => self::stringValue($entry['status'] ?? null),
                'status_bucket' => self::stringValue($entry['status_bucket'] ?? null),
                'closed_reason' => self::stringValue($entry['closed_reason'] ?? null),
                'linked_at' => self::timestamp($entry['created_at'] ?? null),
                'payload' => self::normalizedPayload($entry),
            ],
        );

        return $row;
    }

    /**
     * @return class-string<WorkflowRunLineageEntry>
     */
    private static function lineageModel(): string
    {
        /** @var class-string<WorkflowRunLineageEntry> $model */
        $model = config('workflows.v2.run_lineage_entry_model', WorkflowRunLineageEntry::class);

        return $model;
    }

    /**
     * @return EloquentCollection<int, WorkflowRunLineageEntry>
     */
    private static function projectedRows(WorkflowRun $run): EloquentCollection
    {
        if ($run->relationLoaded('lineageEntries')) {
            /** @var EloquentCollection<int, WorkflowRunLineageEntry> $entries */
            $entries = $run->lineageEntries;

            return $entries;
        }

        $lineageModel = self::lineageModel();

        /** @var EloquentCollection<int, WorkflowRunLineageEntry> $entries */
        $entries = $lineageModel::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('direction')
            ->orderBy('position')
            ->orderBy('lineage_id')
            ->get();

        return $entries;
    }

    /**
     * @param Collection<int, WorkflowRunLineageEntry> $entries
     * @return array{
     *     parents: list<array<string, mixed>>,
     *     continued_workflows: list<array<string, mixed>>
     * }
     */
    private static function payloadsFromProjected(Collection $entries): array
    {
        return [
            'parents' => $entries
                ->filter(static fn (WorkflowRunLineageEntry $entry): bool => $entry->direction === 'parent')
                ->sortBy('position')
                ->map(static fn (WorkflowRunLineageEntry $entry): array => $entry->toLineagePayload())
                ->values()
                ->all(),
            'continued_workflows' => $entries
                ->filter(static fn (WorkflowRunLineageEntry $entry): bool => $entry->direction === 'child')
                ->sortBy('position')
                ->map(static fn (WorkflowRunLineageEntry $entry): array => $entry->toLineagePayload())
                ->values()
                ->all(),
        ];
    }

    /**
     * @param EloquentCollection<int, WorkflowRunLineageEntry> $entries
     * @param list<array<string, mixed>> $parents
     * @param list<array<string, mixed>> $continuedWorkflows
     */
    private static function projectionCoversSnapshot(
        EloquentCollection $entries,
        array $parents,
        array $continuedWorkflows,
    ): bool {
        $projected = self::payloadsFromProjected($entries);

        return self::canonicalEntries($projected['parents']) === self::canonicalEntries($parents)
            && self::canonicalEntries($projected['continued_workflows']) === self::canonicalEntries(
                $continuedWorkflows
            );
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private static function canonicalEntries(array $entries): array
    {
        return array_map(static function (array $entry): array {
            $normalized = self::normalizedPayload($entry);

            unset($normalized['created_at']);

            return self::canonicalizeValue($normalized);
        }, $entries);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function normalizedPayload(array $payload): array
    {
        return array_map(static fn (mixed $value): mixed => self::normalizeValue($value), $payload);
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

    private static function projectionId(string $runId, string $direction, string $lineageId): string
    {
        return hash('sha256', $runId . '|' . $direction . '|' . $lineageId);
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
