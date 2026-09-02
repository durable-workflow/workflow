<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class MessageStreamCursor
{
    /**
     * Reserve the next message sequence for the given instance.
     *
     * The caller must already hold a lock on the instance row
     * (SELECT ... FOR UPDATE) within the current transaction.
     */
    public static function reserveNextSequence(WorkflowInstance $instance): int
    {
        $current = (int) $instance->last_message_sequence;
        $next = $current + 1;

        $instance->forceFill([
            'last_message_sequence' => $next,
        ])->save();

        return $next;
    }

    /**
     * Advance a run's cursor to the given position and record a history event.
     *
     * Returns the recorded history event, or null if the cursor was already
     * at or past the requested position (idempotent).
     */
    public static function advanceCursor(
        WorkflowRun $run,
        int $newPosition,
        ?WorkflowTask $task = null,
        ?string $streamKey = null,
    ): ?WorkflowHistoryEvent {
        $currentPosition = (int) $run->message_cursor_position;

        if ($newPosition <= $currentPosition) {
            return null;
        }

        $run->forceFill([
            'message_cursor_position' => $newPosition,
        ])->save();

        return WorkflowHistoryEvent::record($run, HistoryEventType::MessageCursorAdvanced, [
            'stream_key' => $streamKey ?? self::defaultStreamKey($run),
            'previous_position' => $currentPosition,
            'new_position' => $newPosition,
        ], $task);
    }

    /**
     * Transfer the message cursor from a closing run to a continued run.
     *
     * Used during continue-as-new so the new run picks up where the
     * old run left off in the instance message stream.
     */
    public static function transferCursor(WorkflowRun $closingRun, WorkflowRun $continuedRun): void
    {
        $position = (int) $closingRun->message_cursor_position;

        $continuedRun->forceFill([
            'message_cursor_position' => $position,
        ])->save();
    }

    /**
     * Return the current cursor position for a run.
     */
    public static function positionForRun(WorkflowRun $run): int
    {
        return (int) $run->message_cursor_position;
    }

    /**
     * Check whether there are unconsumed messages for the given run
     * by comparing the run's cursor position against the instance's
     * last message sequence.
     */
    public static function hasUnconsumedMessages(WorkflowRun $run, WorkflowInstance $instance): bool
    {
        return (int) $instance->last_message_sequence > (int) $run->message_cursor_position;
    }

    /**
     * Build the default stream key for a run: `instance:{instance_id}`.
     */
    public static function defaultStreamKey(WorkflowRun $run): string
    {
        return sprintf('instance:%s', $run->workflow_instance_id);
    }
}
