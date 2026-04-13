<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class ParallelChildGroup
{
    /**
     * @return array{
     *     parallel_group_id: string,
     *     parallel_group_kind: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int
     * }
     */
    public static function groupEntry(int $baseSequence, int $size, int $index, string $kind = 'child'): array
    {
        return [
            'parallel_group_id' => self::groupId($kind, $baseSequence, $size),
            'parallel_group_kind' => $kind,
            'parallel_group_base_sequence' => $baseSequence,
            'parallel_group_size' => $size,
            'parallel_group_index' => $index,
        ];
    }

    /**
     * @return array{
     *     parallel_group_id: string,
     *     parallel_group_kind: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int,
     *     parallel_group_path: list<array{
     *         parallel_group_id: string,
     *         parallel_group_kind: string,
     *         parallel_group_base_sequence: int,
     *         parallel_group_size: int,
     *         parallel_group_index: int
     *     }>
     * }
     */
    public static function itemMetadata(int $baseSequence, int $size, int $index, string $kind = 'child'): array
    {
        return self::payloadForPath([self::groupEntry($baseSequence, $size, $index, $kind)]);
    }

    /**
     * @return array{
     *     parallel_group_id: string,
     *     parallel_group_kind: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int
     * }|null
     */
    public static function metadataForSequence(WorkflowRun $run, int $sequence): ?array
    {
        $path = self::metadataPathForSequence($run, $sequence);

        if ($path === []) {
            return null;
        }

        return $path[array_key_last($path)];
    }

    /**
     * @return list<array{
     *     parallel_group_id: string,
     *     parallel_group_kind: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int
     * }>
     */
    public static function metadataPathForSequence(WorkflowRun $run, int $sequence): array
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => in_array(
                $event->event_type,
                [
                    HistoryEventType::ActivityScheduled,
                    HistoryEventType::ActivityStarted,
                    HistoryEventType::ActivityRetryScheduled,
                    HistoryEventType::ActivityCompleted,
                    HistoryEventType::ActivityFailed,
                    HistoryEventType::ActivityCancelled,
                    HistoryEventType::ActivityTimedOut,
                    HistoryEventType::ChildWorkflowScheduled,
                    HistoryEventType::ChildRunStarted,
                    HistoryEventType::ChildRunCompleted,
                    HistoryEventType::ChildRunFailed,
                    HistoryEventType::ChildRunCancelled,
                    HistoryEventType::ChildRunTerminated,
                ],
                true,
            ) && ($event->payload['sequence'] ?? null) === $sequence
                && (
                    is_string($event->payload['parallel_group_id'] ?? null)
                    || is_array($event->payload['parallel_group_path'] ?? null)
                )
        );

        if (! $event instanceof WorkflowHistoryEvent || ! is_array($event->payload)) {
            return [];
        }

        return self::metadataPathFromPayload($event->payload);
    }

    /**
     * @param list<array{
     *     parallel_group_id: string,
     *     parallel_group_kind: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int
     * }> $path
     * @return array{
     *     parallel_group_id: string,
     *     parallel_group_kind: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int,
     *     parallel_group_path: list<array{
     *         parallel_group_id: string,
     *         parallel_group_kind: string,
     *         parallel_group_base_sequence: int,
     *         parallel_group_size: int,
     *         parallel_group_index: int
     *     }>
     * }
     */
    public static function payloadForPath(array $path): array
    {
        $path = self::normalizedPath($path);
        $innermost = $path === []
            ? null
            : $path[array_key_last($path)];

        if ($innermost === null) {
            return [];
        }

        return [
            ...$innermost,
            'parallel_group_path' => $path,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     parallel_group_id: string,
     *     parallel_group_kind: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int
     * }|null
     */
    public static function metadataFromPayload(array $payload): ?array
    {
        $path = self::metadataPathFromPayload($payload);

        if ($path === []) {
            return null;
        }

        return $path[array_key_last($path)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{
     *     parallel_group_id: string,
     *     parallel_group_kind: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int
     * }>
     */
    public static function metadataPathFromPayload(array $payload): array
    {
        $path = [];

        foreach (self::arrayValue($payload['parallel_group_path'] ?? null) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $metadata = self::singleMetadataFromPayload($entry);

            if ($metadata !== null) {
                $path[] = $metadata;
            }
        }

        if ($path !== []) {
            return $path;
        }

        $metadata = self::singleMetadataFromPayload($payload);

        return $metadata === null ? [] : [$metadata];
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

    public static function shouldWakeParentOnChildClosure(
        WorkflowRun $parentRun,
        array $metadata,
        RunStatus $closedChildStatus
    ): bool {
        return self::shouldWakeParentOnClosure(
            $parentRun,
            self::normalizedPath($metadata),
            'child',
            $closedChildStatus,
        );
    }

    public static function shouldWakeParentOnActivityClosure(
        WorkflowRun $parentRun,
        array $metadata,
        ActivityStatus $closedActivityStatus
    ): bool {
        return self::shouldWakeParentOnClosure(
            $parentRun,
            self::normalizedPath($metadata),
            'activity',
            $closedActivityStatus,
        );
    }

    /**
     * @param list<array{
     *     parallel_group_id: string,
     *     parallel_group_kind: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int
     * }> $metadataPath
     */
    private static function shouldWakeParentOnClosure(
        WorkflowRun $parentRun,
        array $metadataPath,
        string $closedKind,
        ActivityStatus|RunStatus $closedStatus,
    ): bool {
        if (
            ($closedKind === 'activity' && $closedStatus !== ActivityStatus::Completed)
            || ($closedKind === 'child' && $closedStatus !== RunStatus::Completed)
        ) {
            return true;
        }

        foreach ($metadataPath as $metadata) {
            if (! self::groupCompletedSuccessfully($parentRun, $metadata)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int
     * } $metadata
     */
    private static function groupCompletedSuccessfully(WorkflowRun $parentRun, array $metadata): bool
    {
        if ($metadata['parallel_group_size'] < 1) {
            return true;
        }

        $parentRun->unsetRelation('historyEvents');
        $parentRun->unsetRelation('activityExecutions');
        $parentRun->unsetRelation('childLinks');

        $activitiesBySequence = collect(RunActivityView::activitiesForRun($parentRun))
            ->filter(static fn (array $activity): bool => is_int($activity['sequence'] ?? null))
            ->keyBy(static fn (array $activity): string => (string) $activity['sequence']);

        foreach (self::sequences($metadata) as $sequence) {
            $activity = $activitiesBySequence->get((string) $sequence);

            if (is_array($activity)) {
                $status = is_string($activity['status'] ?? null)
                    ? $activity['status']
                    : null;

                if ($status === null || in_array($status, [
                    ActivityStatus::Pending->value,
                    ActivityStatus::Running->value,
                ], true)) {
                    return false;
                }

                if ($status !== ActivityStatus::Completed->value) {
                    return false;
                }

                continue;
            }

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
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     parallel_group_id: string,
     *     parallel_group_kind: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int
     * }|null
     */
    private static function singleMetadataFromPayload(array $payload): ?array
    {
        $groupId = is_string($payload['parallel_group_id'] ?? null)
            ? $payload['parallel_group_id']
            : null;
        $kind = is_string($payload['parallel_group_kind'] ?? null)
            ? $payload['parallel_group_kind']
            : self::kindFromGroupId($groupId);
        $baseSequence = is_int($payload['parallel_group_base_sequence'] ?? null)
            ? $payload['parallel_group_base_sequence']
            : null;
        $size = is_int($payload['parallel_group_size'] ?? null)
            ? $payload['parallel_group_size']
            : null;
        $index = is_int($payload['parallel_group_index'] ?? null)
            ? $payload['parallel_group_index']
            : null;

        if ($groupId === null || $kind === null || $baseSequence === null || $size === null || $index === null || $size < 1) {
            return null;
        }

        return [
            'parallel_group_id' => $groupId,
            'parallel_group_kind' => $kind,
            'parallel_group_base_sequence' => $baseSequence,
            'parallel_group_size' => $size,
            'parallel_group_index' => $index,
        ];
    }

    /**
     * @return list<mixed>
     */
    private static function arrayValue(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param array{
     *     parallel_group_id?: string,
     *     parallel_group_kind?: string,
     *     parallel_group_base_sequence?: int,
     *     parallel_group_size?: int,
     *     parallel_group_index?: int
     * }|list<array{
     *     parallel_group_id: string,
     *     parallel_group_kind: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int
     * }> $metadata
     * @return list<array{
     *     parallel_group_id: string,
     *     parallel_group_kind: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int
     * }>
     */
    private static function normalizedPath(array $metadata): array
    {
        if ($metadata === []) {
            return [];
        }

        $first = $metadata[array_key_first($metadata)] ?? null;

        if (! is_array($first)) {
            $single = self::singleMetadataFromPayload($metadata);

            return $single === null ? [] : [$single];
        }

        $path = [];

        foreach ($metadata as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $single = self::singleMetadataFromPayload($entry);

            if ($single !== null) {
                $path[] = $single;
            }
        }

        return $path;
    }

    private static function groupId(string $kind, int $baseSequence, int $size): string
    {
        $prefix = match ($kind) {
            'activity' => 'parallel-activities',
            'mixed' => 'parallel-calls',
            default => 'parallel-children',
        };

        return sprintf('%s:%d:%d', $prefix, $baseSequence, $size);
    }

    private static function kindFromGroupId(?string $groupId): ?string
    {
        return match (true) {
            $groupId === null => null,
            str_starts_with($groupId, 'parallel-activities:') => 'activity',
            str_starts_with($groupId, 'parallel-calls:') => 'mixed',
            str_starts_with($groupId, 'parallel-children:') => 'child',
            default => null,
        };
    }
}
