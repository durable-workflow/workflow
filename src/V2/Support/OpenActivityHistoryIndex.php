<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

/** @internal The run's history writer owns both the index and its coverage. */
final class OpenActivityHistoryIndex
{
    public const THROUGH = 'activity_open_index_through';

    public const SEQUENCE = 'indexed_open_activity_sequence';

    /**
     * Called in the append transaction under the run lock. Only one event per
     * open activity carries the lookup key. Historical event data stays intact.
     */
    public static function prepare(
        WorkflowRun $run,
        WorkflowHistoryEvent $event,
        string|false|null $indexedActivityId,
    ): bool {
        if (! array_key_exists(self::THROUGH, $run->getAttributes())
            || (int) $run->getAttribute(self::THROUGH) !== (int) $event->sequence - 1
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

        $marks = $run->historyEvents()
            ->where('indexed_activity_execution_id', $id)
            ->whereNotNull(self::SEQUENCE)
            ->reorder();
        if (in_array($run->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $connection = $marks->getRelated()
                ->getConnection();
            $marks->forceIndex(WorkflowHistoryEvent::activityIndexName(
                $marks->getRelated()
                    ->getTable(),
                $connection->getTablePrefix(),
            ));
        }
        $current = $marks->lockForUpdate()
            ->limit(2)
            ->get(['id', self::SEQUENCE]);
        if ($current->count() > 1) {
            return false;
        }
        $previous = $current->first();

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
            if ($previous !== null && (int) $previous->getAttribute(self::SEQUENCE) === $sequence) {
                return true;
            }
        }

        if ($previous !== null) {
            // Preserve the workflow command's row budget. A transition inside
            // a metered completion cannot spend an extra row on this index.
            if (HistoryTransactionMeter::current() !== null) {
                return false;
            }
            $run->historyEvents()
                ->whereKey($previous->id)
                ->toBase()
                ->update([
                    self::SEQUENCE => null,
                ]);
        }
        if ($sequence !== null) {
            $event->setAttribute(self::SEQUENCE, $sequence);
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

        $entries = $run->historyEvents()
            ->whereBetween(self::SEQUENCE, [$after, $last])
            ->reorder(self::SEQUENCE)
            ->lockForUpdate()
            ->limit(2)
            ->get(['indexed_activity_execution_id', self::SEQUENCE]);
        $first = $entries->first();
        if ($first === null) {
            return null;
        }

        $sequence = (int) $first->getAttribute(self::SEQUENCE);
        $id = $first->indexed_activity_execution_id;
        if (! is_string($id) || $sequence <= $after
            || ($entries->get(1) !== null && (int) $entries->get(1)->getAttribute(self::SEQUENCE) === $sequence)) {
            return null;
        }

        return [
            'id' => $id,
            'sequence' => $sequence,
        ];
    }
}
