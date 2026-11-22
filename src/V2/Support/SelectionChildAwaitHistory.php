<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Collection;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/** @internal Records when an embedded selection handle parks on a child. */
final class SelectionChildAwaitHistory
{
    public static function park(
        WorkflowRun $run,
        WorkflowTask $task,
        DurableOperationHandle $handle,
        int $position
    ): void {
        if (! self::hasUnresolvedChild($run, $handle)) {
            return;
        }

        $member = self::member($handle, $position);
        if (! self::matches(self::active($run), $member)) {
            self::record($run, $task, $member, 'waiting');
        }
    }

    public static function resume(
        WorkflowRun $run,
        WorkflowTask $task,
        DurableOperationHandle $handle,
        int $position
    ): void {
        if (! $handle->call instanceof ChildWorkflowCall && ! $handle->call instanceof AllCall) {
            return;
        }

        $member = self::member($handle, $position);
        if (self::matches(self::active($run), $member)) {
            self::record($run, $task, $member, 'resumed');
        }
    }

    public static function active(WorkflowRun $run): ?WorkflowHistoryEvent
    {
        return HistoryEventIndex::remember(
            $run->historyEvents,
            'active-selection-child-await',
            static function (Collection $events): ?WorkflowHistoryEvent {
                $latest = null;
                $lastSignalWait = 0;
                foreach ($events as $event) {
                    if (! $event instanceof WorkflowHistoryEvent) {
                        continue;
                    }
                    if ($event->event_type === HistoryEventType::SignalWaitOpened) {
                        $lastSignalWait = max($lastSignalWait, $event->sequence);
                    }
                    if ($event->event_type === HistoryEventType::ChildWorkflowAwaited
                        && is_array($event->payload)
                        && array_key_exists('selection_handle', $event->payload)
                        && ($latest === null || $event->sequence > $latest->sequence)) {
                        $latest = $event;
                    }
                }

                // A later authored signal wait also proves progress when an
                // older worker resumed the child without a resume record.
                return $latest !== null && $latest->sequence > $lastSignalWait
                    && ($latest->payload['selection_handle']['status'] ?? null) === 'waiting' ? $latest : null;
            },
        );
    }

    /**
     * @param array{sequence: int, resolution_event: ?WorkflowHistoryEvent, parallel_group_path: list<array<string, mixed>>} $child
     */
    public static function awaitsChild(?WorkflowHistoryEvent $active, array $child): bool
    {
        if ($active === null) {
            return false;
        }

        $member = $active->payload['selection_handle'] ?? null;
        if (! is_array($member) || ! is_int($member['member_base_sequence'] ?? null)
            || ! is_int($member['member_size'] ?? null) || ! is_string($member['selection_group_id'] ?? null)) {
            return false;
        }

        $offset = $child['sequence'] - $member['member_base_sequence'];
        $resolution = $child['resolution_event'];
        if ($offset < 0 || $offset >= $member['member_size']
            || ($resolution !== null && $resolution->sequence <= $active->sequence)) {
            return false;
        }

        foreach ($child['parallel_group_path'] as $entry) {
            if (($entry['parallel_group_id'] ?? null) === $member['selection_group_id']
                && ($entry['selection_member_base_sequence'] ?? null) === $member['member_base_sequence']
                && ($entry['selection_member_size'] ?? null) === $member['member_size']) {
                return true;
            }
        }

        return false;
    }

    private static function hasUnresolvedChild(WorkflowRun $run, DurableOperationHandle $handle): bool
    {
        if ($handle->call instanceof ChildWorkflowCall) {
            return ChildRunHistory::resolutionEventForSequence($run, $handle->baseSequence) === null;
        }
        if (! $handle->call instanceof AllCall) {
            return false;
        }

        foreach ($handle->call->leafDescriptors($handle->baseSequence) as $leaf) {
            if ($leaf['call'] instanceof ChildWorkflowCall
                && ChildRunHistory::resolutionEventForSequence(
                    $run,
                    $handle->baseSequence + $leaf['offset']
                ) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int|string>
     */
    private static function member(DurableOperationHandle $handle, int $position): array
    {
        return [
            'selection_group_id' => $handle->selectionGroupId,
            'member_base_sequence' => $handle->baseSequence,
            'member_size' => $handle->size,
            'operation_identity' => $handle->identity,
            'await_position' => $position,
        ];
    }

    /**
     * @param array<string, int|string> $member
     */
    private static function matches(?WorkflowHistoryEvent $active, array $member): bool
    {
        $recorded = $active?->payload['selection_handle'] ?? [];
        foreach ($member as $key => $value) {
            if (($recorded[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, int|string> $member
     */
    private static function record(WorkflowRun $run, WorkflowTask $task, array $member, string $status): void
    {
        // Selection handles reuse their authored member. The record has no step
        // sequence, so it cannot claim the following command's replay slot.
        $event = WorkflowHistoryEvent::record($run, HistoryEventType::ChildWorkflowAwaited, [
            'selection_handle' => [
                ...$member,
                'status' => $status,
            ],
        ], $task);
        $run->historyEvents->push($event);
    }
}
