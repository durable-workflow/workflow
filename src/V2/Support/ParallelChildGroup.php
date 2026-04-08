<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class ParallelChildGroup
{
    /**
     * @return array{
     *     parallel_group_id: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int
     * }
     */
    public static function itemMetadata(int $baseSequence, int $size, int $index): array
    {
        return [
            'parallel_group_id' => self::groupId($baseSequence, $size),
            'parallel_group_base_sequence' => $baseSequence,
            'parallel_group_size' => $size,
            'parallel_group_index' => $index,
        ];
    }

    /**
     * @return array{
     *     parallel_group_id: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int
     * }|null
     */
    public static function metadataForSequence(WorkflowRun $run, int $sequence): ?array
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => in_array(
                $event->event_type,
                [
                    HistoryEventType::ChildWorkflowScheduled,
                    HistoryEventType::ChildRunStarted,
                    HistoryEventType::ChildRunCompleted,
                    HistoryEventType::ChildRunFailed,
                    HistoryEventType::ChildRunCancelled,
                    HistoryEventType::ChildRunTerminated,
                ],
                true,
            ) && ($event->payload['sequence'] ?? null) === $sequence
                && is_string($event->payload['parallel_group_id'] ?? null)
        );

        if (! $event instanceof WorkflowHistoryEvent || ! is_array($event->payload)) {
            return null;
        }

        return self::metadataFromPayload($event->payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     parallel_group_id: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int
     * }|null
     */
    public static function metadataFromPayload(array $payload): ?array
    {
        $groupId = is_string($payload['parallel_group_id'] ?? null)
            ? $payload['parallel_group_id']
            : null;
        $baseSequence = is_int($payload['parallel_group_base_sequence'] ?? null)
            ? $payload['parallel_group_base_sequence']
            : null;
        $size = is_int($payload['parallel_group_size'] ?? null)
            ? $payload['parallel_group_size']
            : null;
        $index = is_int($payload['parallel_group_index'] ?? null)
            ? $payload['parallel_group_index']
            : null;

        if ($groupId === null || $baseSequence === null || $size === null || $index === null || $size < 1) {
            return null;
        }

        return [
            'parallel_group_id' => $groupId,
            'parallel_group_base_sequence' => $baseSequence,
            'parallel_group_size' => $size,
            'parallel_group_index' => $index,
        ];
    }

    /**
     * @param array{
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int
     * } $metadata
     * @return list<int>
     */
    public static function sequences(array $metadata): array
    {
        return range(
            $metadata['parallel_group_base_sequence'],
            $metadata['parallel_group_base_sequence'] + $metadata['parallel_group_size'] - 1,
        );
    }

    /**
     * @param array{
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int
     * } $metadata
     */
    public static function shouldWakeParentOnChildClosure(
        WorkflowRun $parentRun,
        array $metadata,
        RunStatus $closedChildStatus,
    ): bool {
        if ($closedChildStatus !== RunStatus::Completed) {
            return true;
        }

        foreach (self::sequences($metadata) as $sequence) {
            $childRun = ChildRunHistory::childRunForSequence($parentRun, $sequence);
            $childStatus = ChildRunHistory::resolvedStatus(
                ChildRunHistory::resolutionEventForSequence($parentRun, $sequence),
                $childRun,
            );

            if (! $childStatus instanceof RunStatus) {
                return false;
            }

            if (in_array($childStatus, [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting], true)) {
                return false;
            }

            if ($childStatus !== RunStatus::Completed) {
                return true;
            }
        }

        return true;
    }

    private static function groupId(int $baseSequence, int $size): string
    {
        return sprintf('parallel-children:%d:%d', $baseSequence, $size);
    }
}
