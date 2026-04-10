<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class WorkflowStepHistory
{
    public const ACTIVITY = 'activity';
    public const CHILD_WORKFLOW = 'child workflow';
    public const CONDITION_WAIT = 'condition wait';
    public const CONTINUE_AS_NEW = 'continue as new';
    public const NO_TYPED_HISTORY = 'no typed history';
    public const PARALLEL_GROUP = 'parallel all barrier matching current topology';
    public const SIGNAL_WAIT = 'signal wait';
    public const SIDE_EFFECT = 'side effect';
    public const TIMER = 'timer';
    public const VERSION_MARKER = 'version marker';

    public static function assertCompatible(WorkflowRun $run, int $sequence, string $expectedShape): void
    {
        $conflictingEventTypes = self::conflictingEventTypesForSequence($run, $sequence, $expectedShape);

        if ($conflictingEventTypes !== []) {
            throw new HistoryEventShapeMismatchException($sequence, $expectedShape, $conflictingEventTypes);
        }
    }

    public static function assertTypedHistoryRecorded(
        WorkflowRun $run,
        int $sequence,
        string $expectedShape,
    ): void {
        $eventTypes = self::workflowStepEventTypesForSequence($run, $sequence);

        if ($eventTypes === []) {
            throw new HistoryEventShapeMismatchException(
                $sequence,
                $expectedShape,
                [self::NO_TYPED_HISTORY],
            );
        }
    }

    /**
     * @param list<array{
     *     offset: int,
     *     group_path: list<array{
     *         parallel_group_id: string,
     *         parallel_group_kind: string,
     *         parallel_group_base_sequence: int,
     *         parallel_group_size: int,
     *         parallel_group_index: int
     *     }>
     * }> $leafDescriptors
     */
    public static function assertParallelGroupCompatible(
        WorkflowRun $run,
        int $baseSequence,
        array $leafDescriptors,
    ): void {
        $run->loadMissing('historyEvents');

        foreach ($leafDescriptors as $descriptor) {
            $offset = self::intValue($descriptor['offset'] ?? null);
            $expectedPath = self::parallelPath($descriptor['group_path'] ?? null);

            if ($offset === null || $expectedPath === []) {
                continue;
            }

            $sequence = $baseSequence + $offset;
            $recordedPath = ParallelChildGroup::metadataPathForSequence($run, $sequence);

            if ($recordedPath === []) {
                continue;
            }

            if ($recordedPath === $expectedPath) {
                continue;
            }

            $eventTypes = self::workflowStepEventTypesForSequence($run, $sequence);

            throw new HistoryEventShapeMismatchException(
                $sequence,
                self::PARALLEL_GROUP,
                $eventTypes === [] ? ['ParallelGroupTopology'] : $eventTypes,
            );
        }
    }

    /**
     * @return list<string>
     */
    public static function conflictingEventTypesForSequence(
        WorkflowRun $run,
        int $sequence,
        string $expectedShape,
    ): array {
        $run->loadMissing('historyEvents');
        $eventTypes = [];

        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            if (! self::isWorkflowStepEvent($event)) {
                continue;
            }

            if (self::intValue($event->payload['sequence'] ?? null) !== $sequence) {
                continue;
            }

            if (self::eventMatchesShape($event, $expectedShape)) {
                continue;
            }

            $eventTypes[] = $event->event_type->value;
        }

        return array_values(array_unique($eventTypes));
    }

    private static function eventMatchesShape(WorkflowHistoryEvent $event, string $expectedShape): bool
    {
        return match ($expectedShape) {
            self::ACTIVITY => in_array($event->event_type, [
                HistoryEventType::ActivityScheduled,
                HistoryEventType::ActivityStarted,
                HistoryEventType::ActivityHeartbeatRecorded,
                HistoryEventType::ActivityRetryScheduled,
                HistoryEventType::ActivityCompleted,
                HistoryEventType::ActivityFailed,
            ], true),
            self::CHILD_WORKFLOW => in_array($event->event_type, [
                HistoryEventType::ChildWorkflowScheduled,
                HistoryEventType::ChildRunStarted,
                HistoryEventType::ChildRunCompleted,
                HistoryEventType::ChildRunFailed,
                HistoryEventType::ChildRunCancelled,
                HistoryEventType::ChildRunTerminated,
            ], true),
            self::CONDITION_WAIT => self::isConditionWaitEvent($event),
            self::CONTINUE_AS_NEW => $event->event_type === HistoryEventType::WorkflowContinuedAsNew,
            self::SIGNAL_WAIT => in_array($event->event_type, [
                HistoryEventType::SignalWaitOpened,
                HistoryEventType::SignalApplied,
            ], true),
            self::SIDE_EFFECT => $event->event_type === HistoryEventType::SideEffectRecorded,
            self::TIMER => self::isPureTimerEvent($event),
            self::VERSION_MARKER => $event->event_type === HistoryEventType::VersionMarkerRecorded,
            default => false,
        };
    }

    private static function isWorkflowStepEvent(WorkflowHistoryEvent $event): bool
    {
        return in_array($event->event_type, [
            HistoryEventType::ActivityScheduled,
            HistoryEventType::ActivityStarted,
            HistoryEventType::ActivityHeartbeatRecorded,
            HistoryEventType::ActivityRetryScheduled,
            HistoryEventType::ActivityCompleted,
            HistoryEventType::ActivityFailed,
            HistoryEventType::ChildWorkflowScheduled,
            HistoryEventType::ChildRunStarted,
            HistoryEventType::ChildRunCompleted,
            HistoryEventType::ChildRunFailed,
            HistoryEventType::ChildRunCancelled,
            HistoryEventType::ChildRunTerminated,
            HistoryEventType::WorkflowContinuedAsNew,
            HistoryEventType::ConditionWaitOpened,
            HistoryEventType::ConditionWaitSatisfied,
            HistoryEventType::ConditionWaitTimedOut,
            HistoryEventType::SignalWaitOpened,
            HistoryEventType::SignalApplied,
            HistoryEventType::SideEffectRecorded,
            HistoryEventType::VersionMarkerRecorded,
            HistoryEventType::TimerScheduled,
            HistoryEventType::TimerFired,
            HistoryEventType::TimerCancelled,
        ], true);
    }

    private static function isConditionWaitEvent(WorkflowHistoryEvent $event): bool
    {
        if (in_array($event->event_type, [
            HistoryEventType::ConditionWaitOpened,
            HistoryEventType::ConditionWaitSatisfied,
            HistoryEventType::ConditionWaitTimedOut,
        ], true)) {
            return true;
        }

        return in_array($event->event_type, [
            HistoryEventType::TimerScheduled,
            HistoryEventType::TimerFired,
            HistoryEventType::TimerCancelled,
        ], true)
            && self::stringValue($event->payload['timer_kind'] ?? null) === 'condition_timeout';
    }

    private static function isPureTimerEvent(WorkflowHistoryEvent $event): bool
    {
        return in_array($event->event_type, [
            HistoryEventType::TimerScheduled,
            HistoryEventType::TimerFired,
            HistoryEventType::TimerCancelled,
        ], true)
            && self::stringValue($event->payload['timer_kind'] ?? null) !== 'condition_timeout';
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value)
            ? (int) $value
            : null;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    /**
     * @return list<string>
     */
    private static function workflowStepEventTypesForSequence(WorkflowRun $run, int $sequence): array
    {
        $eventTypes = [];

        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            if (! self::isWorkflowStepEvent($event)) {
                continue;
            }

            if (self::intValue($event->payload['sequence'] ?? null) !== $sequence) {
                continue;
            }

            $eventTypes[] = $event->event_type->value;
        }

        return array_values(array_unique($eventTypes));
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
    private static function parallelPath(mixed $path): array
    {
        if (! is_array($path)) {
            return [];
        }

        return ParallelChildGroup::metadataPathFromPayload([
            'parallel_group_path' => $path,
        ]);
    }
}
