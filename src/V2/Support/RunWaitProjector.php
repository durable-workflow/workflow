<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Workflow\V2\Contracts\HistoryProjectionMaintenanceRole;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowTask;

final class RunWaitProjector
{
    /**
     * Apply bounded task metadata to its selected wait and every child outcome
     * already assigned to the same task, without rebuilding the wait snapshot.
     * An activity wait keeps the activity task that executed the attempt.
     */
    public static function projectWorkflowTaskClaim(WorkflowRun $run, WorkflowTask $task, ?string $waitId): void
    {
        $waitModel = self::waitModel();
        $values = [
            'task_backed' => true,
            'task_id' => $task->id,
            'task_type' => $task->task_type->value,
            'task_status' => $task->status->value,
        ];

        if ($waitId !== null && $waitId !== '') {
            $selected = $waitModel::query()
                ->where('workflow_run_id', $run->id)
                ->where('kind', '<>', 'activity')
                ->where('wait_id', $waitId);
            HistoryTransactionMeter::affecting($selected, static fn (): int => $selected->update($values));
        }

        $childWaits = $waitModel::query()
            ->where('workflow_run_id', $run->id)
            ->where('kind', 'child')
            ->where('task_id', $task->id)
            ->whereIn('status', ['resolved', 'cancelled']);
        HistoryTransactionMeter::affecting($childWaits, static fn (): int => $childWaits->update($values));

        $run->unsetRelation('waits');
    }

    /**
     * Apply one child outcome to its unique wait row and associate it with the
     * workflow task that will replay the outcome. No other wait is inspected.
     */
    public static function projectChildResolutionEvent(
        WorkflowRun $run,
        WorkflowTask $task,
        WorkflowHistoryEvent $event,
    ): void {
        if (
            $event->workflow_run_id !== $run->id
            || ! in_array($event->event_type, ChildRunHistory::resolutionEventTypes(), true)
        ) {
            throw new \LogicException('Child-resolution wait event must belong to the projected workflow run.');
        }

        $eventPayload = is_array($event->payload) ? $event->payload : [];
        $sequence = self::intValue($eventPayload['sequence'] ?? null);
        $childCallId = self::stringValue($eventPayload['child_call_id'] ?? null)
            ?? self::stringValue($eventPayload['workflow_link_id'] ?? null);
        $waitId = $childCallId === null
            ? ($sequence === null ? null : sprintf('child:%d', $sequence))
            : sprintf('child:%s', $childCallId);

        if ($waitId === null) {
            throw new \LogicException('Child-resolution wait projection requires a bounded child locator.');
        }

        $sourceStatus = self::stringValue($eventPayload['child_status'] ?? null)
            ?? match ($event->event_type) {
                HistoryEventType::ChildRunCompleted => RunStatus::Completed->value,
                HistoryEventType::ChildRunFailed => RunStatus::Failed->value,
                HistoryEventType::ChildRunCancelled => RunStatus::Cancelled->value,
                HistoryEventType::ChildRunTerminated => RunStatus::Terminated->value,
                default => null,
            };
        $label = self::stringValue($eventPayload['child_workflow_type'] ?? null)
            ?? self::stringValue($eventPayload['child_workflow_class'] ?? null)
            ?? 'child workflow';

        $waitModel = self::waitModel();
        $resolvedAt = $event->recorded_at ?? $event->created_at;
        $resolved = $waitModel::query()
            ->where('workflow_run_id', $run->id)
            ->where('wait_id', $waitId);
        HistoryTransactionMeter::affecting($resolved, static fn (): int => $resolved->update([
            'status' => in_array($sourceStatus, [
                RunStatus::Cancelled->value,
                RunStatus::Terminated->value,
            ], true) ? 'cancelled' : 'resolved',
            'source_status' => $sourceStatus,
            'summary' => match ($sourceStatus) {
                RunStatus::Completed->value => sprintf('Child workflow %s completed.', $label),
                RunStatus::Failed->value => sprintf('Child workflow %s failed.', $label),
                RunStatus::Cancelled->value => sprintf('Child workflow %s cancelled.', $label),
                RunStatus::Terminated->value => sprintf('Child workflow %s terminated.', $label),
                default => sprintf('Child workflow %s resolved.', $label),
            },
            'resolved_at' => $resolvedAt === null ? null : UtcScheduleTimestamp::databaseValue($resolvedAt),
            'task_backed' => true,
            'task_id' => $task->id,
            'task_type' => $task->task_type->value,
            'task_status' => $task->status->value,
            'history_authority' => ChildRunHistory::HISTORY_AUTHORITY_TYPED,
            'history_unsupported_reason' => null,
        ]));

        $run->unsetRelation('waits');
    }

    public static function hasPendingChildResolutionForTask(WorkflowRun $run, WorkflowTask $task): bool
    {
        $waitModel = self::waitModel();

        return $waitModel::query()
            ->where('workflow_run_id', $run->id)
            ->where('kind', 'child')
            ->where('task_id', $task->id)
            ->whereIn('status', ['resolved', 'cancelled'])
            ->whereIn('task_status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->exists();
    }

    /**
     * @param array<string, mixed> $activity
     */
    public static function projectActivityWait(WorkflowRun $run, string $executionId, array $activity): bool
    {
        $waitModel = self::waitModel();
        $waitId = 'activity:' . $executionId;
        $projectionId = self::projectionId($run->id, $waitId);
        /** @var WorkflowRunWait|null $previous */
        $previous = $waitModel::query()->find($projectionId);

        if ($previous === null || $previous->position !== WorkflowRunWait::OPENING_TIME_POSITION
            || $previous->kind !== 'activity'
            || $previous->history_authority !== RunActivityView::HISTORY_AUTHORITY_TYPED) {
            return false;
        }

        self::saveWait(
            $waitModel,
            $run,
            $projectionId,
            $waitId,
            RunWaitView::forActivity($run, $executionId, $activity),
            $previous,
        );
        $run->unsetRelation('waits');

        return true;
    }

    public static function openActivityAfterSequence(WorkflowRun $run, int $sequence, int $last): ?WorkflowRunWait
    {
        $waitModel = self::waitModel();
        return $waitModel::query()->where('workflow_run_id', $run->id)
            ->where('kind', 'activity')
            ->where('status', 'open')
            ->where('sequence', '>', $sequence)
            ->where('sequence', '<=', $last)
            ->where('history_authority', RunActivityView::HISTORY_AUTHORITY_TYPED)
            ->orderBy('sequence')
            ->lockForUpdate()
            ->first();
    }

    public static function projectCompletedChildWait(WorkflowRun $run, WorkflowHistoryEvent $event): bool
    {
        $sequence = self::intValue($event->payload['sequence'] ?? null);
        $childCallId = self::stringValue($event->payload['child_call_id'] ?? null);
        $childRunId = self::stringValue($event->payload['child_workflow_run_id'] ?? null);
        $childInstanceId = self::stringValue($event->payload['child_workflow_instance_id'] ?? null);
        $label = self::stringValue($event->payload['child_workflow_type'] ?? null)
            ?? self::stringValue($event->payload['child_workflow_class'] ?? null);
        $path = ParallelChildGroup::metadataPathFromPayload($event->payload);
        if ($event->event_type !== HistoryEventType::ChildRunCompleted
            || $sequence === null || $childCallId === null || $childRunId === null
            || $childInstanceId === null || $label === null || count($path) !== 1
            || ! str_starts_with($path[0]['parallel_group_id'], 'parallel-children:')) {
            return false;
        }

        $waitModel = self::waitModel();
        $waitId = 'child:' . $childCallId;
        $projectionId = self::projectionId($run->id, $waitId);
        /** @var WorkflowRunWait|null $previous */
        $previous = $waitModel::query()->find($projectionId);
        if ($previous === null || ! self::isOpenChildGroupWait($previous, $path[0]['parallel_group_id'])
            || $previous->kind !== 'child' || $previous->status !== 'open' || $previous->sequence !== $sequence
            || $previous->resume_source_id !== $childRunId || $previous->target_name !== $childInstanceId
            || $previous->target_type !== $label
            || ParallelChildGroup::metadataPathFromPayload($previous->payload) !== $path) {
            return false;
        }

        self::saveWait($waitModel, $run, $projectionId, $waitId, [
            ...$previous->payload,
            'status' => 'resolved',
            'source_status' => RunStatus::Completed->value,
            'summary' => sprintf('Child workflow %s completed.', $label),
            'resolved_at' => $event->recorded_at ?? $event->created_at,
        ], $previous);
        $run->unsetRelation('waits');

        return true;
    }

    public static function openChildWaitAfterCompletion(
        WorkflowRun $run,
        string $currentWaitId,
        WorkflowHistoryEvent $event,
    ): ?WorkflowRunWait {
        $childCallId = self::stringValue($event->payload['child_call_id'] ?? null);
        $sequence = self::intValue($event->payload['sequence'] ?? null);
        $path = ParallelChildGroup::metadataPathFromPayload($event->payload);
        if ($childCallId === null || $sequence === null || count($path) !== 1
            || ! str_starts_with($path[0]['parallel_group_id'], 'parallel-children:')) {
            return null;
        }

        $waitModel = self::waitModel();
        $query = $waitModel::query()->where('workflow_run_id', $run->id)
            ->where('kind', 'child')
            ->where('status', 'open');
        /** @var WorkflowRunWait|null $current */
        $current = (clone $query)->where('wait_id', $currentWaitId)
            ->first();
        if (! self::isOpenChildGroupWait($current, $path[0]['parallel_group_id'])) {
            return null;
        }

        if ($currentWaitId !== 'child:' . $childCallId) {
            return $current;
        }

        /** @var WorkflowRunWait|null $next */
        $end = $path[0]['parallel_group_base_sequence'] + $path[0]['parallel_group_size'] - 1;
        $next = $query->where('sequence', '>', $sequence)
            ->where('sequence', '<=', $end)
            ->orderBy('sequence')
            ->first();

        return self::isOpenChildGroupWait($next, $path[0]['parallel_group_id']) ? $next : null;
    }

    public static function openSiblingChildWait(
        WorkflowRun $run,
        string $groupId,
        int $first,
        int $last,
        int $closed,
    ): ?WorkflowRunWait {
        $waitModel = self::waitModel();
        /** @var WorkflowRunWait|null $wait */
        $wait = $waitModel::query()->where('workflow_run_id', $run->id)
            ->where('kind', 'child')
            ->where('status', 'open')
            ->whereBetween('sequence', [$first, $last])
            ->where('sequence', '<>', $closed)
            ->orderBy('sequence')
            ->first();

        return self::isOpenChildGroupWait($wait, $groupId) ? $wait : null;
    }

    /**
     * @param list<array<string, mixed>>|null $waits
     * @return list<WorkflowRunWait>
     */
    public static function project(WorkflowRun $run, ?array $waits = null, bool $collectRows = true): array
    {
        $waits ??= RunWaitView::forRun($run);
        $waitModel = self::waitModel();
        $seen = [];
        $projected = [];

        foreach (array_chunk(array_values($waits), 100, true) as $page) {
            $projectionIds = [];
            foreach ($page as $position => $wait) {
                $projectionIds[] = self::projectionId($run->id, self::waitId($wait, $position));
            }
            $existing = $waitModel::query()->where('workflow_run_id', $run->id)
                ->whereIn('id', $projectionIds)
                ->get()
                ->keyBy('id');

            foreach ($page as $position => $wait) {
                $waitId = self::waitId($wait, $position);
                $projectionId = self::projectionId($run->id, $waitId);
                $seen[] = $projectionId;
                $row = self::saveWait($waitModel, $run, $projectionId, $waitId, $wait, $existing->get($projectionId));

                if ($collectRows) {
                    $projected[] = $row;
                }
            }
            unset($existing, $page);
        }

        self::historyProjectionMaintenanceRole()
            ->pruneStaleProjectionRowsForRun($waitModel, $run->id, $seen);

        $run->unsetRelation('waits');

        return $projected;
    }

    /**
     * @return array{source: string, waits: list<array<string, mixed>>}
     */
    public static function snapshotForRun(WorkflowRun $run): array
    {
        $projected = self::projectedRows($run);
        $canonicalWaits = RunWaitView::forRun($run);

        if ($projected->isEmpty() && $canonicalWaits === []) {
            return [
                'source' => 'workflow_run_waits',
                'waits' => [],
            ];
        }

        if ($projected->isNotEmpty() && self::projectionMatchesSnapshot($projected, $canonicalWaits)) {
            return [
                'source' => 'workflow_run_waits',
                'waits' => $projected
                    ->map(static fn (WorkflowRunWait $wait): array => $wait->toWaitPayload())
                    ->values()
                    ->all(),
            ];
        }

        $reprojected = self::project($run, $canonicalWaits);

        return [
            'source' => 'workflow_run_waits_rebuilt',
            'waits' => collect($reprojected)
                ->map(static fn (WorkflowRunWait $wait): array => $wait->toWaitPayload())
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
        $canonicalWaits = RunWaitView::forRun($run);
        $hasProjection = $projected->isNotEmpty();
        $hasCanonical = $canonicalWaits !== [];

        return [
            'has_projection' => $hasProjection,
            'has_canonical' => $hasCanonical,
            'missing' => $hasCanonical && ! $hasProjection,
            'stale' => $hasProjection && ! self::projectionMatchesSnapshot($projected, $canonicalWaits),
        ];
    }

    private static function isOpenChildGroupWait(?WorkflowRunWait $wait, string $groupId): bool
    {
        return $wait !== null
            && $wait->position === WorkflowRunWait::OPENING_TIME_POSITION
            && $wait->history_authority === ChildRunHistory::HISTORY_AUTHORITY_TYPED
            && $wait->resume_source_kind === 'child_workflow_run'
            && $wait->task_id === null
            && ($wait->payload['parallel_group_id'] ?? null) === $groupId;
    }

    /**
     * @param class-string<WorkflowRunWait> $waitModel
     * @param array<string, mixed> $wait
     */
    private static function saveWait(
        string $waitModel,
        WorkflowRun $run,
        string $projectionId,
        string $waitId,
        array $wait,
        ?WorkflowRunWait $existing,
    ): WorkflowRunWait {
        /** @var WorkflowRunWait $row */
        $row = IdempotentProjectionUpsert::upsert(
            $waitModel,
            [
                'id' => $projectionId,
            ],
            [
                'workflow_run_id' => $run->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'wait_id' => $waitId,
                // The opening time and wait ID determine order independently of other rows.
                'position' => WorkflowRunWait::OPENING_TIME_POSITION,
                'kind' => self::stringValue($wait['kind'] ?? null) ?? 'unknown',
                'sequence' => self::intValue($wait['sequence'] ?? null),
                'status' => self::stringValue($wait['status'] ?? null) ?? 'unknown',
                'source_status' => self::stringValue($wait['source_status'] ?? null),
                'summary' => self::stringValue($wait['summary'] ?? null),
                'opened_at' => self::timestamp($wait['opened_at'] ?? null),
                'deadline_at' => self::timestamp($wait['deadline_at'] ?? null),
                'resolved_at' => self::timestamp($wait['resolved_at'] ?? null),
                'target_name' => self::stringValue($wait['target_name'] ?? null),
                'target_type' => self::stringValue($wait['target_type'] ?? null),
                'task_backed' => (bool) ($wait['task_backed'] ?? false),
                'external_only' => (bool) ($wait['external_only'] ?? false),
                'resume_source_kind' => self::stringValue($wait['resume_source_kind'] ?? null),
                'resume_source_id' => self::stringValue($wait['resume_source_id'] ?? null),
                'task_id' => self::stringValue($wait['task_id'] ?? null),
                'task_type' => self::stringValue($wait['task_type'] ?? null),
                'task_status' => self::stringValue($wait['task_status'] ?? null),
                'command_id' => self::stringValue($wait['command_id'] ?? null),
                'command_sequence' => self::intValue($wait['command_sequence'] ?? null),
                'command_status' => self::stringValue($wait['command_status'] ?? null),
                'command_outcome' => self::stringValue($wait['command_outcome'] ?? null),
                'history_authority' => self::stringValue($wait['history_authority'] ?? null),
                'history_unsupported_reason' => self::stringValue($wait['history_unsupported_reason'] ?? null),
                'payload' => self::normalizedPayload($wait),
            ],
            $existing,
        );

        return $row;
    }

    private static function historyProjectionMaintenanceRole(): HistoryProjectionMaintenanceRole
    {
        /** @var HistoryProjectionMaintenanceRole $role */
        $role = App::make(HistoryProjectionMaintenanceRole::class);

        return $role;
    }

    /**
     * @return class-string<WorkflowRunWait>
     */
    private static function waitModel(): string
    {
        /** @var class-string<WorkflowRunWait> $model */
        $model = config('workflows.v2.run_wait_model', WorkflowRunWait::class);

        return $model;
    }

    /**
     * @return EloquentCollection<int, WorkflowRunWait>
     */
    private static function projectedRows(WorkflowRun $run): EloquentCollection
    {
        if ($run->relationLoaded('waits')) {
            /** @var EloquentCollection<int, WorkflowRunWait> $waits */
            $waits = $run->waits;

            return $waits;
        }

        $waitModel = self::waitModel();

        /** @var EloquentCollection<int, WorkflowRunWait> $waits */
        $waits = $waitModel::query()
            ->where('workflow_run_id', $run->id)
            ->inDisplayOrder()
            ->get();

        return $waits;
    }

    /**
     * @param EloquentCollection<int, WorkflowRunWait> $projected
     * @param list<array<string, mixed>> $canonical
     */
    private static function projectionMatchesSnapshot(EloquentCollection $projected, array $canonical): bool
    {
        return self::canonicalEntries(
            $projected
                ->map(static fn (WorkflowRunWait $wait): array => $wait->toWaitPayload())
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
        // Projection models materialize absent optional wait fields as null.
        // Durable snapshots may omit them, but both shapes mean "no value".
        return array_map(
            static fn (array $entry): array => self::canonicalizeValue(array_filter(
                self::normalizedPayload($entry),
                static fn (mixed $value): bool => $value !== null,
            )),
            $entries,
        );
    }

    /**
     * @param array<string, mixed> $wait
     * @return array<string, mixed>
     */
    private static function normalizedPayload(array $wait): array
    {
        return array_map(static fn (mixed $value): mixed => self::normalizeValue($value), $wait);
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

    /**
     * @param array<string, mixed> $wait
     */
    private static function waitId(array $wait, int $position): string
    {
        return self::stringValue($wait['id'] ?? null)
            ?? sprintf(
                '%s:%s',
                self::stringValue($wait['kind'] ?? null) ?? 'wait',
                self::intValue($wait['sequence'] ?? null) ?? $position,
            );
    }

    private static function projectionId(string $runId, string $waitId): string
    {
        return hash('sha256', $runId . '|' . $waitId);
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
