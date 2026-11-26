<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

/**
 * The last workflow step a run's committed history already records.
 *
 * Workflow code whose next step is at or before this sequence ran in an
 * earlier task and is being replayed. Update events are excluded: they record
 * the step an update was applied at, not a step the workflow code reached.
 *
 * @internal
 */
final class WorkflowReplayBoundary
{
    private const EXCLUDED_EVENT_TYPES = [
        HistoryEventType::UpdateAccepted,
        HistoryEventType::UpdateApplied,
        HistoryEventType::UpdateCompleted,
        HistoryEventType::UpdateRejected,
    ];

    public static function committedSequence(WorkflowRun $run): int
    {
        $events = $run->relationLoaded('historyEvents')
            ? $run->historyEvents
            : $run->historyEvents()
                ->get();

        $boundary = 0;

        foreach ($events as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            if (in_array($event->event_type, self::EXCLUDED_EVENT_TYPES, true)) {
                continue;
            }

            $payload = is_array($event->payload) ? $event->payload : [];
            $sequence = $payload['sequence'] ?? null;

            if (is_int($sequence) && $sequence > $boundary) {
                $boundary = $sequence;
            } elseif (is_string($sequence) && ctype_digit($sequence) && (int) $sequence > $boundary) {
                $boundary = (int) $sequence;
            }
        }

        return $boundary;
    }
}
