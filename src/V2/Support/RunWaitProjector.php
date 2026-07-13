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
     */
    public static function projectWorkflowTaskClaim(
        WorkflowRun $run,
        WorkflowTask $task,
        ?string $waitId,
    ): void {
        $waitModel = self::waitModel();
        $values = [
            'task_backed' => true,
            'task_id' => $task->id,
            'task_type' => $task->task_type->value,
            'task_status' => $task->status->value,
        ];

        if ($waitId !== null && $waitId !== '') {
            $waitModel::query()
                ->where('workflow_run_id', $run->id)
                ->where('wait_id', $waitId)
                ->update($values);
        }

        $waitModel::query()
            ->where('workflow_run_id', $run->id)
            ->where('kind', 'child')
            ->where('task_id', $task->id)
            ->whereIn('status', ['resolved', 'cancelled'])
            ->update($values);

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
        $waitModel::query()
            ->where('workflow_run_id', $run->id)
            ->where('wait_id', $waitId)
            ->update([
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
                'resolved_at' => $event->recorded_at ?? $event->created_at,
                'task_backed' => true,
                'task_id' => $task->id,
                'task_type' => $task->task_type->value,
                'task_status' => $task->status->value,
                'history_authority' => ChildRunHistory::HISTORY_AUTHORITY_TYPED,
                'history_unsupported_reason' => null,
            ]);

        $run->unsetRelation('waits');
    }

    public static function hasPendingChildResolutionForTask(
        WorkflowRun $run,
        WorkflowTask $task,
    ): bool {
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
     * @param list<array<string, mixed>>|null $waits
     * @return list<WorkflowRunWait>
     */
    public static function project(WorkflowRun $run, ?array $waits = null): array
    {
        $waits ??= RunWaitView::forRun($run);
        $waitModel = self::waitModel();
        $seen = [];
        $projected = [];

        foreach (array_values($waits) as $position => $wait) {
            $waitId = self::waitId($wait, $position);
            $projectionId = self::projectionId($run->id, $waitId);
            $payload = self::normalizedPayload($wait);
            $seen[] = $projectionId;

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
                    'position' => $position,
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
                    'payload' => $payload,
                ],
            );

            $projected[] = $row;
        }

        self::historyProjectionMaintenanceRole()
            ->pruneStaleProjectionRowsForRun($waitModel, $run->id, $seen);

        $run->unsetRelation('waits');

        return $projected;
    }

    private static function historyProjectionMaintenanceRole(): HistoryProjectionMaintenanceRole
    {
        /** @var HistoryProjectionMaintenanceRole $role */
        $role = App::make(HistoryProjectionMaintenanceRole::class);

        return $role;
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
            ->orderBy('position')
            ->orderBy('wait_id')
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
        return array_map(
            static fn (array $entry): array => self::canonicalizeValue(self::normalizedPayload($entry)),
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
