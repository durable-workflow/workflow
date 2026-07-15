<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use LogicException;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowChildProjectionRepair;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * Durable, bounded identities for child-resolution projections that must be
 * retried. Repair rows are selected by run and active-task ownership, while
 * their history event is resolved through an exact primary-key lookup instead
 * of hydrating history.
 */
final class ChildProjectionRepairStore
{
    public static function remember(
        WorkflowRun $run,
        WorkflowTask $task,
        WorkflowHistoryEvent $event,
    ): WorkflowChildProjectionRepair {
        if (
            $event->workflow_run_id !== $run->id
            || $task->workflow_run_id !== $run->id
            || ! in_array($event->event_type, ChildRunHistory::resolutionEventTypes(), true)
        ) {
            throw new LogicException('Child projection repair identity must reference one child outcome and run.');
        }

        $eventPayload = is_array($event->payload) ? $event->payload : [];
        $failureId = $eventPayload['failure_id'] ?? null;
        $failureId = is_string($failureId) && trim($failureId) !== ''
            ? trim($failureId)
            : null;

        /** @var WorkflowChildProjectionRepair $repair */
        $repair = self::query()->updateOrCreate(
            [
                'workflow_history_event_id' => $event->id,
            ],
            [
                'workflow_run_id' => $run->id,
                'workflow_task_id' => $task->id,
                'history_sequence' => (int) $event->sequence,
                'failure_id' => $failureId,
            ],
        );

        return $repair;
    }

    /**
     * A successful claim adopts repairs from its own task and from terminal
     * predecessors on the same run. Repairs assigned to another Ready or
     * Leased task retain that active owner.
     *
     * @return array{
     *     repairs: Collection<int, WorkflowChildProjectionRepair>,
     *     events: list<WorkflowHistoryEvent>
     * }
     */
    public static function pendingFor(WorkflowRun $run, WorkflowTask $task): array
    {
        /** @var Collection<int, WorkflowChildProjectionRepair> $repairs */
        $repairs = self::query()
            ->where('workflow_run_id', $run->id)
            ->where(static function (Builder $repairQuery) use ($run, $task): void {
                $repairQuery
                    ->where('workflow_task_id', $task->id)
                    ->orWhereHas('task', static function (Builder $taskQuery) use ($run): void {
                        $taskQuery
                            ->where('workflow_run_id', $run->id)
                            ->whereIn('status', [
                                TaskStatus::Cancelled->value,
                                TaskStatus::Completed->value,
                                TaskStatus::Failed->value,
                            ]);
                    });
            })
            ->orderBy('history_sequence')
            ->lockForUpdate()
            ->get();
        $events = [];

        foreach ($repairs as $repair) {
            /** @var WorkflowHistoryEvent|null $event */
            $event = ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
                ->where('workflow_run_id', $run->id)
                ->whereKey($repair->workflow_history_event_id)
                ->first();

            if (
                ! $event instanceof WorkflowHistoryEvent
                || (int) $event->sequence !== $repair->history_sequence
                || ! in_array($event->event_type, ChildRunHistory::resolutionEventTypes(), true)
            ) {
                throw new LogicException(sprintf(
                    'Child projection repair [%s] has no matching durable history event.',
                    $repair->workflow_history_event_id,
                ));
            }

            $events[] = $event;
        }

        return [
            'repairs' => $repairs,
            'events' => $events,
        ];
    }

    /**
     * @param iterable<WorkflowChildProjectionRepair> $repairs
     */
    public static function acknowledge(iterable $repairs): void
    {
        foreach ($repairs as $repair) {
            $repair->delete();
        }
    }

    /**
     * Resolve the per-event failed-child count marker through exact repair
     * primary keys. Compatibility events from tasks created before the repair
     * table was introduced are intentionally absent from the returned map.
     *
     * @param list<WorkflowHistoryEvent> $events
     * @return array<string, bool>
     */
    public static function failedChildCountApplications(WorkflowRun $run, array $events): array
    {
        $eventIds = array_values(array_unique(array_map(
            static fn (WorkflowHistoryEvent $event): string => (string) $event->getKey(),
            $events,
        )));

        if ($eventIds === []) {
            return [];
        }

        /** @var Collection<int, WorkflowChildProjectionRepair> $repairs */
        $repairs = self::query()
            ->where('workflow_run_id', $run->id)
            ->whereKey($eventIds)
            ->get();
        $applications = [];

        foreach ($repairs as $repair) {
            $applications[(string) $repair->getKey()] = $repair->failed_child_counted_at !== null;
        }

        return $applications;
    }

    /**
     * @param list<string> $eventIds
     */
    public static function markFailedChildrenCounted(WorkflowRun $run, array $eventIds): void
    {
        if ($eventIds === []) {
            return;
        }

        $timestamp = now();

        self::query()
            ->where('workflow_run_id', $run->id)
            ->whereKey(array_values(array_unique($eventIds)))
            ->whereNull('failed_child_counted_at')
            ->update([
                'failed_child_counted_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
    }

    /**
     * Share the per-event application marker with full projection. Only repair
     * rows whose failure identity was present in the authoritative snapshot
     * set are marked, so a stale loaded relation cannot suppress later repair.
     *
     * @param list<string> $failureIds
     */
    public static function markSnapshotFailuresCounted(WorkflowRun $run, array $failureIds): void
    {
        if ($failureIds === []) {
            return;
        }

        $timestamp = now();

        self::query()
            ->where('workflow_run_id', $run->id)
            ->whereIn('failure_id', array_values(array_unique($failureIds)))
            ->whereNull('failed_child_counted_at')
            ->update([
                'failed_child_counted_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
    }

    /**
     * @return Builder<WorkflowChildProjectionRepair>
     */
    private static function query(): Builder
    {
        /** @var Builder<WorkflowChildProjectionRepair> $query */
        $query = ConfiguredV2Models::query('child_projection_repair_model', WorkflowChildProjectionRepair::class);

        return $query;
    }
}
