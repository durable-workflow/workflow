<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class RunHistoryPage
{
    /**
     * Read retained events without rebuilding projections, decoding application
     * values or fetching external payloads. Carry throughSequence to subsequent
     * pages to keep a growing history inside the original observation boundary.
     *
     * @return array<string, mixed>
     */
    public static function forRun(
        WorkflowRun $run,
        int $limit = 200,
        int $afterSequence = 0,
        ?int $throughSequence = null,
    ): array {
        if ($limit < 1 || $limit > 1000 || $afterSequence < 0
            || ($throughSequence !== null && $throughSequence < 0)) {
            throw new InvalidArgumentException(
                'History pages require a limit from 1 to 1000 and nonnegative sequences.'
            );
        }

        $query = $run->historyEvents()
            ->reorder();
        $throughSequence ??= (int) (clone $query)->max('sequence');
        $rows = $query
            ->where('sequence', '>', $afterSequence)
            ->where('sequence', '<=', $throughSequence)
            ->orderBy('sequence')
            ->limit($limit + 1)
            ->get();
        $hasMore = $rows->count() > $limit;
        $events = $rows->take($limit)
            ->map(static fn (WorkflowHistoryEvent $event): array => [
                'id' => $event->id,
                'sequence' => (int) $event->sequence,
                'event_type' => $event->event_type->value,
                'payload' => $event->payload,
                'recorded_at' => $event->recorded_at?->toIso8601String(),
                'workflow_task_id' => $event->workflow_task_id,
                'workflow_command_id' => $event->workflow_command_id,
            ])
            ->values()
            ->all();
        $lastSequence = $events === [] ? null : $events[array_key_last($events)]['sequence'];

        return [
            'instance_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'namespace' => $run->namespace,
            'source' => 'workflow_history_events',
            'events' => $events,
            'returned_count' => count($events),
            'limit' => $limit,
            'after_sequence' => $afterSequence,
            'through_sequence' => $throughSequence,
            'history_window_from_start' => $afterSequence === 0,
            'first_sequence' => $events[0]['sequence'] ?? null,
            'last_sequence' => $lastSequence,
            'has_more' => $hasMore,
            'next_sequence' => $hasMore ? $lastSequence : null,
            'details_state' => $run->details_pruned_at === null ? 'retained' : 'pruned',
        ];
    }
}
