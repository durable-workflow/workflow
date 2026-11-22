<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Query\Builder;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

/** @internal The run's history writer owns both the index and its coverage. */
final class OpenActivityHistoryIndex
{
    public const THROUGH = 'activity_open_index_through';

    public const TABLE = 'workflow_open_activities';

    /**
     * Called in the append transaction under the run lock. The lookup rows
     * follow typed activity history without updating a previously authored event.
     */
    public static function advance(
        WorkflowRun $run,
        WorkflowHistoryEvent $event,
        string|false|null $indexedActivityId,
    ): bool {
        if (! array_key_exists(self::THROUGH, $run->getAttributes())
            || (int) $run->getAttribute(self::THROUGH) !== (int) $event->sequence - 1
            || $event->getTable() !== 'workflow_history_events'
            || $indexedActivityId === false) {
            return false;
        }
        if (! RunActivityView::isActivityEvent($event->event_type)) {
            return true;
        }

        $snapshot = ActivitySnapshot::fromEvent($event);
        $id = $snapshot['id'] ?? null;
        $status = $snapshot['status'] ?? null;
        if (! is_string($id) || $id !== $indexedActivityId || ! is_string($status)) {
            return false;
        }
        if (($snapshot['local_activity'] ?? false) === true) {
            // Inline activity events are outside the remote fan-out lookup.
            // A local member in a remote group leaves its coverage uncertain.
            $path = $snapshot['parallel_group_path'] ?? [];

            return count($path) !== 1 || ! str_starts_with($path[0]['parallel_group_id'], 'parallel-activities:');
        }

        $entry = self::forRun($run)->where('activity_execution_id', $id);
        $previous = (clone $entry)->lockForUpdate()
            ->first(['sequence']);

        $sequence = null;
        if (in_array($status, [ActivityStatus::Pending->value, ActivityStatus::Running->value], true)) {
            $sequence = $snapshot['sequence'] ?? null;
            if ($sequence === null) {
                // A snapshot without a sequence inherits it from the earlier event.
                return $previous !== null;
            }
            if (! is_int($sequence) || $sequence < 0 || $sequence > 2147483647) {
                return false;
            }
            if ($previous !== null && (int) $previous->sequence === $sequence) {
                return true;
            }
        }

        if ($previous === null && $sequence === null) {
            return true;
        }
        // A metered service-mode completion keeps its established write budget.
        // Leave its index incomplete if the transition requires an extra row.
        if (HistoryTransactionMeter::current() !== null) {
            return false;
        }
        if ($sequence === null) {
            $entry->delete();
        } elseif ($previous === null) {
            $run->getConnection()
                ->table(self::TABLE)->insert([
                    'workflow_run_id' => $run->id,
                    'activity_execution_id' => $id,
                    'history_event_id' => $event->id,
                    'sequence' => $sequence,
                ]);
        } else {
            $entry->update([
                'history_event_id' => $event->id,
                'sequence' => $sequence,
            ]);
        }

        return true;
    }

    /**
     * The caller owns the run lock.
     *
     * @return array{id: string, sequence: int}|null
     */
    public static function nextInGroup(WorkflowRun $run, int $after, int $last): ?array
    {
        if (! WorkflowHistoryEvent::activityIndexCovers($run)
            || (int) $run->getAttribute(self::THROUGH) !== (int) $run->last_history_sequence) {
            return null;
        }

        $entries = self::forRun($run)
            ->whereBetween('sequence', [$after, $last])
            ->orderBy('sequence')
            ->lockForUpdate()
            ->limit(2)
            ->get(['activity_execution_id', 'sequence']);
        $first = $entries->first();
        if ($first === null) {
            return null;
        }

        $sequence = (int) $first->sequence;
        $id = $first->activity_execution_id;
        if (! is_string($id) || $sequence <= $after
            || ($entries->get(1) !== null && (int) $entries->get(1)->sequence === $sequence)) {
            return null;
        }

        return [
            'id' => $id,
            'sequence' => $sequence,
        ];
    }

    public static function prune(WorkflowRun $run): void
    {
        if (array_key_exists(self::THROUGH, $run->getAttributes())) {
            self::forRun($run)->delete();
        }
    }

    private static function forRun(WorkflowRun $run): Builder
    {
        return $run->getConnection()
            ->table(self::TABLE)->where('workflow_run_id', $run->id);
    }
}
