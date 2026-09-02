<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

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
    public static function groupEntry(
        int $baseSequence,
        int $size,
        int $index,
        string $kind = 'child',
        string $mode = 'all',
        int|string|null $selectionMemberKey = null,
        ?int $selectionMemberIndex = null,
        ?int $selectionMemberBaseSequence = null,
        ?int $selectionMemberSize = null,
        ?string $selectionMemberKind = null,
    ): array {
        return array_filter([
            'parallel_group_id' => self::groupId($kind, $baseSequence, $size, $mode),
            'parallel_group_kind' => $kind,
            'parallel_group_mode' => $mode === 'select' ? $mode : null,
            'parallel_group_base_sequence' => $baseSequence,
            'parallel_group_size' => $size,
            'parallel_group_index' => $index,
            'selection_member_key' => $selectionMemberKey,
            'selection_member_index' => $selectionMemberIndex,
            'selection_member_base_sequence' => $selectionMemberBaseSequence,
            'selection_member_size' => $selectionMemberSize,
            'selection_member_kind' => $selectionMemberKind,
        ], static fn (mixed $value): bool => $value !== null);
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
                    HistoryEventType::TimerScheduled,
                    HistoryEventType::TimerFired,
                    HistoryEventType::TimerCancelled,
                    HistoryEventType::ConditionWaitOpened,
                    HistoryEventType::ConditionWaitSatisfied,
                    HistoryEventType::ConditionWaitTimedOut,
                    HistoryEventType::SignalWaitOpened,
                    HistoryEventType::SignalApplied,
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
        RunStatus $closedChildStatus,
        bool $lockHistoryForUpdate = false,
    ): bool {
        return self::shouldWakeParentOnClosure(
            $parentRun,
            self::normalizedPath($metadata),
            'child',
            $closedChildStatus,
            $lockHistoryForUpdate,
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

    public static function shouldWakeParentOnTimerClosure(
        WorkflowRun $parentRun,
        array $metadata,
        TimerStatus $closedTimerStatus
    ): bool {
        return self::shouldWakeParentOnClosure(
            $parentRun,
            self::normalizedPath($metadata),
            'timer',
            $closedTimerStatus,
        );
    }

    public static function selectionResolution(WorkflowRun $run, string $groupId): ?WorkflowHistoryEvent
    {
        self::refreshPersistedHistory($run);

        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::SelectionResolved
                && ($event->payload['selection_group_id'] ?? null) === $groupId
        );

        return $event;
    }

    public static function cancellationForHandle(
        WorkflowRun $run,
        DurableOperationHandle $handle,
    ): ?WorkflowHistoryEvent {
        if ($run->exists) {
            /** @var WorkflowHistoryEvent|null $event */
            $event = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::SelectionOperationCancelled->value)
                ->get()
                ->first(static fn (WorkflowHistoryEvent $candidate): bool =>
                    ($candidate->payload['selection_group_id'] ?? null) === $handle->selectionGroupId
                    && ($candidate->payload['member_base_sequence'] ?? null) === $handle->baseSequence);
        } else {
            /** @var WorkflowHistoryEvent|null $event */
            $event = $run->historyEvents->first(static fn (WorkflowHistoryEvent $candidate): bool =>
                $candidate->event_type === HistoryEventType::SelectionOperationCancelled
                && ($candidate->payload['selection_group_id'] ?? null) === $handle->selectionGroupId
                && ($candidate->payload['member_base_sequence'] ?? null) === $handle->baseSequence);
        }

        if (! $event instanceof WorkflowHistoryEvent) {
            return null;
        }

        $expected = [
            'selection_group_id' => $handle->selectionGroupId,
            'member_key' => $handle->key,
            'member_index' => $handle->index,
            'member_base_sequence' => $handle->baseSequence,
            'member_size' => $handle->size,
            'operation_kind' => $handle->kind,
            'operation_identity' => $handle->identity,
        ];

        foreach ($expected as $field => $value) {
            if (($event->payload[$field] ?? null) === $value) {
                continue;
            }

            throw new HistoryEventShapeMismatchException(
                $handle->baseSequence,
                'SelectionOperationCancelled matching the authored selection member',
                [HistoryEventType::SelectionOperationCancelled->value],
                sprintf('Cancellation field [%s] does not match the durable member identity.', $field),
            );
        }

        return $event;
    }

    /**
     * @return array<int, string>
     */
    public static function durableOperationIdentities(WorkflowRun $run): array
    {
        $identities = [];
        $priorities = [];

        foreach ($run->historyEvents as $event) {
            $sequence = $event->payload['sequence'] ?? null;
            if (! is_int($sequence)) {
                continue;
            }

            $descriptor = match ($event->event_type) {
                HistoryEventType::ActivityScheduled => ['activity_execution_id', 10],
                HistoryEventType::ChildWorkflowScheduled => ['child_workflow_run_id', 10],
                HistoryEventType::TimerScheduled => ['timer_id', 1],
                HistoryEventType::SignalWaitOpened => ['signal_wait_id', 20],
                HistoryEventType::ConditionWaitOpened => ['condition_wait_id', 20],
                default => null,
            };
            if ($descriptor === null) {
                continue;
            }

            [$field, $priority] = $descriptor;
            $identity = self::stringValue($event->payload[$field] ?? null);
            if ($identity !== null && $priority > ($priorities[$sequence] ?? -1)) {
                $identities[$sequence] = $identity;
                $priorities[$sequence] = $priority;
            }
        }

        return $identities;
    }

    /**
     * @return array<string, mixed>
     */
    public static function validatedSelectionResolution(
        WorkflowRun $run,
        SelectCall $call,
        int $baseSequence,
        WorkflowHistoryEvent $marker,
    ): array {
        $payload = is_array($marker->payload) ? $marker->payload : [];
        $groupSize = $call->leafCount();
        $expectedGroupId = sprintf('select-calls:%d:%d', $baseSequence, $groupSize);

        foreach ([
            'selection_group_id' => $expectedGroupId,
            'selection_group_base_sequence' => $baseSequence,
            'selection_group_size' => $groupSize,
        ] as $field => $expected) {
            if (($payload[$field] ?? null) !== $expected) {
                self::throwSelectionMismatch($baseSequence, sprintf(
                    'Winner field [%s] does not match the authored selection group.',
                    $field,
                ));
            }
        }

        $memberIndex = self::intValue($payload['member_index'] ?? null);
        if ($memberIndex === null || ! array_key_exists($memberIndex, $call->calls)) {
            self::throwSelectionMismatch($baseSequence, 'Winner member_index does not name an authored member.');
        }

        $cursor = 0;
        foreach ($call->calls as $index => $memberCall) {
            $memberSize = $memberCall instanceof AllCall ? $memberCall->leafCount() : 1;
            if ($index !== $memberIndex) {
                $cursor += $memberSize;

                continue;
            }

            $memberBase = $baseSequence + $cursor;
            $memberKind = match (true) {
                $memberCall instanceof AllCall => 'group',
                $memberCall instanceof ActivityCall => 'activity',
                $memberCall instanceof ChildWorkflowCall => 'child',
                $memberCall instanceof TimerCall => 'timer',
                $memberCall instanceof SignalCall => 'signal',
                default => 'condition',
            };
            $identity = self::operationIdentityForMember($run, $memberKind, $memberBase, $memberSize, null);
            foreach ([
                'member_key' => $call->keys[$index],
                'member_base_sequence' => $memberBase,
                'member_size' => $memberSize,
                'operation_kind' => $memberKind,
                'operation_identity' => $identity,
            ] as $field => $expected) {
                if (($payload[$field] ?? null) !== $expected) {
                    self::throwSelectionMismatch($memberBase, sprintf(
                        'Winner field [%s] does not match the authored durable member.',
                        $field,
                    ));
                }
            }

            $outcome = self::stringValue($payload['outcome'] ?? null);
            if (! in_array($outcome, ['completed', 'failed'], true)) {
                self::throwSelectionMismatch($memberBase, 'Winner outcome must be completed or failed.');
            }

            $resolutionId = self::stringValue($payload['resolution_event_id'] ?? null);
            $resolutionType = self::stringValue($payload['resolution_event_type'] ?? null);
            /** @var WorkflowHistoryEvent|null $resolution */
            $resolution = $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
                $event->id === $resolutionId);
            if (! $resolution instanceof WorkflowHistoryEvent
                || $resolution->event_type->value !== $resolutionType
                || ! is_int($resolution->payload['sequence'] ?? null)
                || $resolution->payload['sequence'] < $memberBase
                || $resolution->payload['sequence'] >= $memberBase + $memberSize) {
                self::throwSelectionMismatch(
                    $memberBase,
                    'Winner resolution_event_id/type does not identify a terminal event for the authored member.',
                );
            }

            $expectedResolution = self::resolutionEventForMember($run, $memberBase, $memberSize, $outcome);
            if (! $expectedResolution instanceof WorkflowHistoryEvent || $expectedResolution->id !== $resolution->id) {
                self::throwSelectionMismatch(
                    $memberBase,
                    'Winner outcome is not bound to the event that made the authored member terminal.',
                );
            }

            if ($outcome === 'completed' && $memberCall instanceof AllCall && ! self::groupCompletedSuccessfully(
                $run,
                [
                    'parallel_group_base_sequence' => $memberBase,
                    'parallel_group_size' => $memberSize,
                ],
                false,
            )) {
                self::throwSelectionMismatch(
                    $memberBase,
                    'Completed nested selection winner does not have a fully completed durable barrier.',
                );
            }

            return [
                ...$payload,
                '_resolution_sequence' => $resolution->payload['sequence'],
            ];
        }

        self::throwSelectionMismatch($baseSequence, 'Winner member is outside the authored selection group.');
    }

    /**
     * Commit a selectable signal or condition resolution observed while the
     * parent workflow task holds the run lock.
     *
     * @param list<array<string, mixed>> $metadataPath
     */
    public static function claimSelectionWinner(
        WorkflowRun $run,
        array $metadataPath,
        string $operationKind,
        WorkflowHistoryEvent $resolutionEvent,
        string $outcome = 'completed',
    ): bool {
        $eligible = true;
        $nestedMember = false;

        foreach (array_reverse(self::normalizedPath($metadataPath)) as $metadata) {
            if (self::groupMode($metadata) === 'select') {
                if (! $eligible) {
                    return false;
                }

                $memberBase = self::intValue($metadata['selection_member_base_sequence'] ?? null)
                    ?? ($metadata['parallel_group_base_sequence'] + $metadata['parallel_group_index']);
                $memberSize = self::intValue($metadata['selection_member_size'] ?? null) ?? 1;
                $memberKind = self::validatedSelectionMemberKind(
                    $metadata,
                    $memberBase,
                    $nestedMember ? 'group' : $operationKind,
                );
                $canonicalResolution = $memberKind === 'group'
                    ? self::resolutionEventForMember($run, $memberBase, $memberSize, $outcome)
                    : $resolutionEvent;

                return self::persistSelectionWinner(
                    $run,
                    $metadata,
                    $memberKind,
                    $canonicalResolution,
                    $outcome,
                    false,
                );
            }

            if ($outcome === 'completed'
                && ! self::groupCompletedSuccessfully($run, $metadata, false)) {
                $eligible = false;
            }
            $nestedMember = true;
        }

        return false;
    }

    public static function selectionMemberIsTerminal(
        WorkflowRun $run,
        int $baseSequence,
        int $size,
        string $kind,
    ): bool {
        self::refreshPersistedHistory($run);

        if (self::resolutionEventForMember($run, $baseSequence, $size, 'failed') instanceof WorkflowHistoryEvent) {
            return true;
        }

        if ($kind !== 'group') {
            return self::resolutionEventForMember(
                $run,
                $baseSequence,
                $size,
                'completed',
            ) instanceof WorkflowHistoryEvent;
        }

        return self::groupCompletedSuccessfully($run, [
            'parallel_group_base_sequence' => $baseSequence,
            'parallel_group_size' => $size,
        ], false);
    }

    public static function memberFailureResolution(
        WorkflowRun $run,
        int $baseSequence,
        int $size,
    ): ?WorkflowHistoryEvent {
        if (! $run->relationLoaded('historyEvents')) {
            $run->load('historyEvents');
        }

        return self::resolutionEventForMember($run, $baseSequence, $size, 'failed');
    }

    private static function refreshPersistedHistory(WorkflowRun $run): void
    {
        if (! $run->exists) {
            return;
        }

        $run->unsetRelation('historyEvents');
        $run->load('historyEvents');
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
        ActivityStatus|RunStatus|TimerStatus $closedStatus,
        bool $lockHistoryForUpdate = false,
    ): bool {
        $successful = ! (
            ($closedKind === 'activity' && $closedStatus !== ActivityStatus::Completed)
            || ($closedKind === 'child' && $closedStatus !== RunStatus::Completed)
            || ($closedKind === 'timer' && $closedStatus !== TimerStatus::Fired)
        );

        $eligible = true;
        $nestedMember = false;

        foreach (array_reverse($metadataPath) as $metadata) {
            if (self::groupMode($metadata) === 'select') {
                if (! $eligible) {
                    return false;
                }

                return self::selectionWinnerIs(
                    $parentRun,
                    $metadata,
                    $closedKind,
                    $closedStatus,
                    $nestedMember ? 'group' : $closedKind,
                    $lockHistoryForUpdate,
                );
            }

            if ($successful && ! self::groupCompletedSuccessfully($parentRun, $metadata, $lockHistoryForUpdate)) {
                $eligible = false;
            }
            $nestedMember = true;
        }

        return $eligible || ! $successful;
    }

    /**
     * Atomically persist the first eligible member of a selection group.
     * Callers already hold the parent-run lock; the history recorder takes
     * the same lock so every completion path observes one committed winner.
     *
     * @param array<string, mixed> $metadata
     */
    private static function selectionWinnerIs(
        WorkflowRun $parentRun,
        array $metadata,
        string $closedKind,
        ActivityStatus|RunStatus|TimerStatus $closedStatus,
        string $expectedMemberKind,
        bool $lockHistoryForUpdate,
    ): bool {
        $memberBase = self::intValue($metadata['selection_member_base_sequence'] ?? null)
            ?? ($metadata['parallel_group_base_sequence'] + $metadata['parallel_group_index']);
        $memberSize = self::intValue($metadata['selection_member_size'] ?? null) ?? 1;
        $outcome = self::successfulStatus($closedKind, $closedStatus) ? 'completed' : 'failed';
        $memberKind = self::validatedSelectionMemberKind($metadata, $memberBase, $expectedMemberKind);
        $resolutionEvent = self::resolutionEventForMember($parentRun, $memberBase, $memberSize, $outcome);

        $winner = self::persistSelectionWinner(
            $parentRun,
            $metadata,
            $memberKind,
            $resolutionEvent,
            $outcome,
            $lockHistoryForUpdate,
        );

        if ($winner) {
            return true;
        }

        return $parentRun->status === RunStatus::Waiting
            && ! WorkflowTask::query()
                ->where('workflow_run_id', $parentRun->id)
                ->where('task_type', TaskType::Workflow->value)
                ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
                ->exists();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private static function persistSelectionWinner(
        WorkflowRun $parentRun,
        array $metadata,
        string $operationKind,
        ?WorkflowHistoryEvent $resolutionEvent,
        string $outcome,
        bool $lockHistoryForUpdate,
    ): bool {
        $parentRun->unsetRelation('historyEvents');
        $events = $parentRun->historyEvents();

        if ($lockHistoryForUpdate) {
            $events->lockForUpdate();
        }

        $parentRun->setRelation('historyEvents', $events->get());
        $groupId = $metadata['parallel_group_id'];
        $memberBase = self::intValue($metadata['selection_member_base_sequence'] ?? null)
            ?? ($metadata['parallel_group_base_sequence'] + $metadata['parallel_group_index']);
        $memberSize = self::intValue($metadata['selection_member_size'] ?? null) ?? 1;
        $resolutionEvent ??= self::resolutionEventForMember($parentRun, $memberBase, $memberSize, $outcome);
        $existing = $parentRun->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::SelectionResolved
                && ($event->payload['selection_group_id'] ?? null) === $groupId
        );

        if ($existing instanceof WorkflowHistoryEvent) {
            return false;
        }

        $identity = self::operationIdentityForMember(
            $parentRun,
            $operationKind,
            $memberBase,
            $memberSize,
            $resolutionEvent,
        );
        $event = WorkflowHistoryEvent::record($parentRun, HistoryEventType::SelectionResolved, array_filter([
            'selection_group_id' => $groupId,
            'selection_group_base_sequence' => $metadata['parallel_group_base_sequence'],
            'selection_group_size' => $metadata['parallel_group_size'],
            'member_key' => $metadata['selection_member_key'] ?? $metadata['parallel_group_index'],
            'member_index' => $metadata['selection_member_index'] ?? $metadata['parallel_group_index'],
            'member_base_sequence' => $memberBase,
            'member_size' => $memberSize,
            'operation_kind' => $operationKind,
            'operation_identity' => $identity,
            'outcome' => $outcome,
            'resolution_event_id' => $resolutionEvent?->id,
            'resolution_event_type' => $resolutionEvent?->event_type->value,
        ], static fn (mixed $value): bool => $value !== null));

        $parentRun->historyEvents->push($event);

        return true;
    }

    /**
     * @param array{
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int
     * } $metadata
     */
    private static function groupCompletedSuccessfully(
        WorkflowRun $parentRun,
        array $metadata,
        bool $lockHistoryForUpdate,
    ): bool {
        if ($metadata['parallel_group_size'] < 1) {
            return true;
        }

        $parentRun->unsetRelation('historyEvents');
        $parentRun->unsetRelation('activityExecutions');
        $parentRun->unsetRelation('childLinks');
        $parentRun->unsetRelation('timers');

        if ($lockHistoryForUpdate) {
            // Observe the resolution event committed by the previous holder of
            // the parent lock even when this transaction has an older snapshot.
            $parentRun->setRelation('historyEvents', $parentRun->historyEvents() ->lockForUpdate() ->get());
        }

        $activitiesBySequence = collect(RunActivityView::activitiesForRun($parentRun))
            ->filter(static fn (array $activity): bool => is_int($activity['sequence'] ?? null))
            ->keyBy(static fn (array $activity): string => (string) $activity['sequence']);
        $timersBySequence = collect(RunTimerView::timersForRun($parentRun))
            ->filter(static fn (array $timer): bool => is_int($timer['sequence'] ?? null))
            ->keyBy(static fn (array $timer): string => (string) $timer['sequence']);

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

            $waitKind = self::waitKindForSequence($parentRun, $sequence);

            if ($waitKind !== null) {
                if (! self::waitCompletedSuccessfully($parentRun, $sequence, $waitKind)) {
                    return false;
                }

                continue;
            }

            $timer = $timersBySequence->get((string) $sequence);

            if (is_array($timer)) {
                if (($timer['status'] ?? null) !== TimerStatus::Fired->value) {
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
        $mode = self::stringValue($payload['parallel_group_mode'] ?? null)
            ?? self::modeFromGroupId($groupId);
        $baseSequence = is_int($payload['parallel_group_base_sequence'] ?? null)
            ? $payload['parallel_group_base_sequence']
            : null;
        $size = is_int($payload['parallel_group_size'] ?? null)
            ? $payload['parallel_group_size']
            : null;
        $index = is_int($payload['parallel_group_index'] ?? null)
            ? $payload['parallel_group_index']
            : null;
        $selectionMemberKey = $payload['selection_member_key'] ?? null;

        if ($groupId === null || $kind === null || $baseSequence === null || $size === null || $index === null || $size < 1) {
            return null;
        }

        if ($mode === 'select' && ! self::validSelectionKey($selectionMemberKey)) {
            return null;
        }

        return array_filter([
            'parallel_group_id' => $groupId,
            'parallel_group_kind' => $kind,
            'parallel_group_mode' => $mode === 'select' ? $mode : null,
            'parallel_group_base_sequence' => $baseSequence,
            'parallel_group_size' => $size,
            'parallel_group_index' => $index,
            'selection_member_key' => $mode === 'select' ? $selectionMemberKey : null,
            'selection_member_index' => self::intValue($payload['selection_member_index'] ?? null),
            'selection_member_base_sequence' => self::intValue($payload['selection_member_base_sequence'] ?? null),
            'selection_member_size' => self::intValue($payload['selection_member_size'] ?? null),
            'selection_member_kind' => self::stringValue($payload['selection_member_kind'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
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

    private static function groupId(string $kind, int $baseSequence, int $size, string $mode = 'all'): string
    {
        if ($mode === 'select') {
            return sprintf('select-calls:%d:%d', $baseSequence, $size);
        }

        $prefix = match ($kind) {
            'activity' => 'parallel-activities',
            'mixed' => 'parallel-calls',
            'timer' => 'parallel-timers',
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
            str_starts_with($groupId, 'parallel-timers:') => 'timer',
            str_starts_with($groupId, 'parallel-children:') => 'child',
            str_starts_with($groupId, 'select-calls:') => 'mixed',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private static function groupMode(array $metadata): string
    {
        return self::stringValue($metadata['parallel_group_mode'] ?? null)
            ?? self::modeFromGroupId(self::stringValue($metadata['parallel_group_id'] ?? null))
            ?? 'all';
    }

    private static function modeFromGroupId(?string $groupId): ?string
    {
        return $groupId !== null && str_starts_with($groupId, 'select-calls:') ? 'select' : null;
    }

    private static function successfulStatus(string $kind, ActivityStatus|RunStatus|TimerStatus $status): bool
    {
        return ($kind === 'activity' && $status === ActivityStatus::Completed)
            || ($kind === 'child' && $status === RunStatus::Completed)
            || ($kind === 'timer' && $status === TimerStatus::Fired);
    }

    private static function resolutionEventForMember(
        WorkflowRun $run,
        int $baseSequence,
        int $size,
        string $outcome,
    ): ?WorkflowHistoryEvent {
        $types = $outcome === 'failed'
            ? [
                HistoryEventType::ActivityFailed,
                HistoryEventType::ActivityCancelled,
                HistoryEventType::ActivityTimedOut,
                HistoryEventType::ChildRunFailed,
                HistoryEventType::ChildRunCancelled,
                HistoryEventType::ChildRunTerminated,
            ]
            : [
                HistoryEventType::ActivityCompleted,
                HistoryEventType::ChildRunCompleted,
                HistoryEventType::TimerFired,
                HistoryEventType::SignalApplied,
                HistoryEventType::ConditionWaitSatisfied,
                HistoryEventType::ConditionWaitTimedOut,
            ];

        $events = $run->historyEvents
            ->filter(static fn (WorkflowHistoryEvent $event): bool => in_array($event->event_type, $types, true)
                && is_int($event->payload['sequence'] ?? null)
                && $event->payload['sequence'] >= $baseSequence
                && $event->payload['sequence'] < $baseSequence + $size)
            ->sortBy(static fn (WorkflowHistoryEvent $event): int => $event->sequence);

        return $outcome === 'failed' ? $events->first() : $events->last();
    }

    private static function operationIdentityForMember(
        WorkflowRun $run,
        string $kind,
        int $baseSequence,
        int $size,
        ?WorkflowHistoryEvent $event,
    ): string {
        if ($kind === 'group') {
            return sprintf('group:%d:%d', $baseSequence, $size);
        }

        $field = match ($kind) {
            'activity' => 'activity_execution_id',
            'child' => 'child_workflow_run_id',
            'signal' => 'signal_wait_id',
            'condition' => 'condition_wait_id',
            default => 'timer_id',
        };

        /** @var WorkflowHistoryEvent|null $opening */
        $opening = $run->historyEvents
            ->filter(static fn (WorkflowHistoryEvent $candidate): bool =>
                ($candidate->payload['sequence'] ?? null) === $baseSequence)
            ->sortByDesc(static fn (WorkflowHistoryEvent $candidate): int => match ($candidate->event_type) {
                HistoryEventType::SignalWaitOpened, HistoryEventType::ConditionWaitOpened => 20,
                HistoryEventType::ActivityScheduled, HistoryEventType::ChildWorkflowScheduled => 10,
                HistoryEventType::TimerScheduled => 1,
                default => 0,
            })
            ->first(static fn (WorkflowHistoryEvent $candidate): bool => in_array(
                $candidate->event_type,
                [
                    HistoryEventType::ActivityScheduled,
                    HistoryEventType::ChildWorkflowScheduled,
                    HistoryEventType::TimerScheduled,
                    HistoryEventType::SignalWaitOpened,
                    HistoryEventType::ConditionWaitOpened,
                ],
                true,
            ));

        $identity = self::stringValue($opening?->payload[$field] ?? null)
            ?? self::stringValue($event?->payload[$field] ?? null);
        if ($identity === null) {
            self::throwSelectionMismatch(
                $baseSequence,
                sprintf('The selected %s member has no durable scheduled or open operation identity.', $kind),
            );
        }

        return $identity;
    }

    private static function waitKindForSequence(WorkflowRun $run, int $sequence): ?string
    {
        foreach ($run->historyEvents as $event) {
            if (($event->payload['sequence'] ?? null) !== $sequence) {
                continue;
            }

            if ($event->event_type === HistoryEventType::SignalWaitOpened) {
                return 'signal';
            }

            if ($event->event_type === HistoryEventType::ConditionWaitOpened) {
                return 'condition';
            }
        }

        return null;
    }

    private static function waitCompletedSuccessfully(WorkflowRun $run, int $sequence, string $kind): bool
    {
        $terminalTypes = $kind === 'signal'
            ? [HistoryEventType::SignalApplied, HistoryEventType::TimerFired]
            : [HistoryEventType::ConditionWaitSatisfied, HistoryEventType::ConditionWaitTimedOut];

        return $run->historyEvents->contains(static fn (WorkflowHistoryEvent $event): bool =>
            ($event->payload['sequence'] ?? null) === $sequence
            && in_array($event->event_type, $terminalTypes, true));
    }

    private static function intValue(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function validSelectionKey(mixed $value): bool
    {
        return (is_string($value) && $value !== '') || (is_int($value) && $value >= 0);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private static function validatedSelectionMemberKind(
        array $metadata,
        int $memberBase,
        string $expectedKind,
    ): string {
        $memberKind = self::stringValue($metadata['selection_member_kind'] ?? null);
        if ($memberKind !== $expectedKind) {
            self::throwSelectionMismatch(
                $memberBase,
                'Selection path field [selection_member_kind] is missing or does not match the authored member.',
            );
        }

        return $memberKind;
    }

    private static function throwSelectionMismatch(int $sequence, string $detail): never
    {
        throw new HistoryEventShapeMismatchException(
            $sequence,
            'SelectionResolved matching the authored durable member and terminal event',
            [HistoryEventType::SelectionResolved->value],
            $detail,
        );
    }
}
