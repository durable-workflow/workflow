<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\CancellationContext;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * Canonical cancellation delivery shared by embedded and portable execution.
 * The caller holds the workflow run lock.
 */
final class CooperativeCancellationDelivery
{
    private const CALL_SHAPES = [
        'activity' => WorkflowStepHistory::ACTIVITY,
        'local_activity' => WorkflowStepHistory::LOCAL_ACTIVITY,
        'timer' => WorkflowStepHistory::TIMER,
        'condition' => WorkflowStepHistory::CONDITION_WAIT,
        'signal' => WorkflowStepHistory::SIGNAL_WAIT,
        'child' => WorkflowStepHistory::CHILD_WORKFLOW,
    ];

    private const RESOLUTION_TYPES = [
        HistoryEventType::ActivityCompleted,
        HistoryEventType::ActivityFailed,
        HistoryEventType::ActivityCancelled,
        HistoryEventType::ActivityTimedOut,
        HistoryEventType::TimerFired,
        HistoryEventType::TimerCancelled,
        HistoryEventType::ConditionWaitSatisfied,
        HistoryEventType::ConditionWaitTimedOut,
        HistoryEventType::SignalApplied,
        HistoryEventType::ChildRunCompleted,
        HistoryEventType::ChildRunFailed,
        HistoryEventType::ChildRunCancelled,
        HistoryEventType::ChildRunTerminated,
        HistoryEventType::SelectionResolved,
        HistoryEventType::SelectionOperationCancelled,
    ];

    public static function validate(
        WorkflowRun $run,
        string $requestId,
        int $sequence,
        string $callKind,
        int $sequenceSpan = 1,
        ?int $operationSequence = null,
        int $operationSequenceSpan = 1,
    ): ?string {
        if ($requestId === '' || $run->cancellation_request_command_id !== $requestId) {
            return 'cancellation_request_mismatch';
        }

        $limit = StructuralLimits::commandBatchSizeLimit();
        if ($sequence < 1 || $sequenceSpan < 1 || $operationSequenceSpan < 1
            || $sequence > PHP_INT_MAX - $sequenceSpan
            || ($limit > 0 && $sequenceSpan > $limit)
            || ($limit > 0 && $operationSequenceSpan > $limit)
            || (! isset(self::CALL_SHAPES[$callKind]) && ! in_array($callKind, ['parallel', 'selection_handle'], true))
            || ($callKind !== 'parallel' && $sequenceSpan !== 1)
            || ($callKind === 'selection_handle') !== ($operationSequence !== null)
            || ($callKind !== 'selection_handle' && $operationSequenceSpan !== 1)) {
            return 'invalid_cancellation_delivery';
        }

        if (! $run->relationLoaded('historyEvents')) {
            $run->loadMissing('historyEvents');
        }
        $request = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type
                === HistoryEventType::CooperativeCancellationRequested
                && $event->workflow_command_id === $requestId
                && ($event->payload['workflow_command_id'] ?? null) === $requestId,
        );
        if (! $request instanceof WorkflowHistoryEvent) {
            return 'cancellation_request_history_missing';
        }

        $existing = self::recorded($run);
        if ($existing instanceof WorkflowHistoryEvent) {
            return ($existing->workflow_command_id === $requestId
                && ($existing->payload['sequence'] ?? null) === $sequence
                && ($existing->payload['call_kind'] ?? null) === $callKind
                && ($existing->payload['sequence_span'] ?? 1) === $sequenceSpan
                && ($existing->payload['operation_sequence'] ?? null) === $operationSequence
                && ($existing->payload['operation_sequence_span'] ?? 1) === $operationSequenceSpan
                && $run->cancellation_delivery_sequence === $sequence)
                ? null : 'cancellation_delivery_mismatch';
        }
        if ($run->cancellation_delivery_sequence !== null) {
            return 'cancellation_delivery_history_missing';
        }

        $nextSequence = WorkflowStepHistory::nextDurableCommandSequence($run);
        if ($sequence > $nextSequence) {
            return 'cancellation_delivery_sequence_mismatch';
        }

        if ($callKind === 'selection_handle') {
            if ($sequence !== $nextSequence || $operationSequence === null
                || $operationSequence < 1 || $operationSequence >= $nextSequence
                || $operationSequenceSpan > $nextSequence - $operationSequence) {
                return 'cancellation_delivery_sequence_mismatch';
            }
            $kind = self::callKindAt($run, $operationSequence);
            if ($kind === null) {
                return 'cancellation_delivery_sequence_mismatch';
            }
            if ($operationSequenceSpan > 1) {
                return self::parallelEligibility($run, $operationSequence, $operationSequenceSpan, $request);
            }

            return self::resolvedBeforeRequest($run, $operationSequence, $request)
                ? 'cancellation_delivery_not_eligible' : null;
        }

        if ($callKind === 'parallel') {
            if ($sequence === $nextSequence) {
                return null;
            }
            return self::parallelEligibility($run, $sequence, $sequenceSpan, $request);
        }

        try {
            WorkflowStepHistory::assertCompatible($run, $sequence, self::CALL_SHAPES[$callKind]);
            if ($sequence < $nextSequence) {
                WorkflowStepHistory::assertTypedHistoryRecorded($run, $sequence, self::CALL_SHAPES[$callKind]);
            }
        } catch (HistoryEventShapeMismatchException) {
            return 'cancellation_delivery_shape_mismatch';
        }

        return self::resolvedBeforeRequest($run, $sequence, $request)
            ? 'cancellation_delivery_not_eligible' : null;
    }

    public static function recorded(WorkflowRun $run): ?WorkflowHistoryEvent
    {
        if (! $run->relationLoaded('historyEvents')) {
            $run->loadMissing('historyEvents');
        }

        return $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type
                === HistoryEventType::CooperativeCancellationDelivered,
        );
    }

    public static function context(WorkflowRun $run): ?CancellationContext
    {
        $run->loadMissing('historyEvents');
        $request = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type
                === HistoryEventType::CooperativeCancellationRequested
                && $event->workflow_command_id === $run->cancellation_request_command_id,
        );
        $snapshot = $request?->payload['cancellation'] ?? null;

        return is_array($snapshot) ? CancellationContext::fromArray($snapshot) : null;
    }

    public static function record(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        string $callKind,
        int $sequenceSpan = 1,
        ?int $operationSequence = null,
        int $operationSequenceSpan = 1,
    ): WorkflowHistoryEvent {
        $existing = self::recorded($run);
        if ($existing instanceof WorkflowHistoryEvent) {
            return $existing;
        }

        $deliveredAt = now();
        $run->forceFill([
            'cancellation_delivery_sequence' => $sequence,
            'cancellation_delivered_at' => $deliveredAt,
            'last_progress_at' => $deliveredAt,
        ])->save();
        $payload = [
            'workflow_command_id' => $run->cancellation_request_command_id,
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'call_kind' => $callKind,
        ];
        $context = self::context($run);
        if ($context !== null) {
            $payload['cancellation'] = $context->toArray();
        }
        if ($sequenceSpan !== 1) {
            $payload['sequence_span'] = $sequenceSpan;
        }
        if ($operationSequence !== null) {
            $payload['operation_sequence'] = $operationSequence;
        }
        if ($operationSequenceSpan !== 1) {
            $payload['operation_sequence_span'] = $operationSequenceSpan;
        }
        $event = WorkflowHistoryEvent::record(
            $run,
            HistoryEventType::CooperativeCancellationDelivered,
            $payload,
            $task,
            $run->cancellation_request_command_id,
        );
        $run->historyEvents->push($event);

        return $event;
    }

    public static function callKindAt(WorkflowRun $run, int $sequence): ?string
    {
        if (! $run->relationLoaded('historyEvents')) {
            $run->loadMissing('historyEvents');
        }
        foreach ($run->historyEvents as $event) {
            if (($event->payload['sequence'] ?? null) !== $sequence) {
                continue;
            }
            $kind = match ($event->event_type) {
                HistoryEventType::ActivityScheduled => ($event->payload['local_activity'] ?? false) === true
                    ? 'local_activity' : 'activity',
                HistoryEventType::ConditionWaitOpened => 'condition',
                HistoryEventType::SignalWaitOpened => 'signal',
                HistoryEventType::ChildWorkflowScheduled => 'child',
                HistoryEventType::TimerScheduled => match ($event->payload['timer_kind'] ?? null) {
                    'condition_timeout' => 'condition',
                    'signal_timeout' => 'signal',
                    default => 'timer',
                },
                default => null,
            };
            if ($kind !== null) {
                return $kind;
            }
        }

        return null;
    }

    private static function resolvedBeforeRequest(
        WorkflowRun $run,
        int $sequence,
        WorkflowHistoryEvent $request,
    ): bool {
        return $run->historyEvents->contains(
            static fn (WorkflowHistoryEvent $event): bool => (
                ($event->payload['sequence'] ?? null) === $sequence
                || ($event->event_type === HistoryEventType::SelectionOperationCancelled
                    && is_int($event->payload['member_base_sequence'] ?? null)
                    && is_int($event->payload['member_size'] ?? null)
                    && $event->payload['member_base_sequence'] >= 1
                    && $event->payload['member_base_sequence'] <= $sequence
                    && $event->payload['member_size'] > $sequence - $event->payload['member_base_sequence'])
            )
                && in_array($event->event_type, self::RESOLUTION_TYPES, true)
                && $event->sequence < $request->sequence,
        );
    }

    private static function parallelEligibility(
        WorkflowRun $run,
        int $sequence,
        int $span,
        WorkflowHistoryEvent $request,
    ): ?string {
        $path = ParallelChildGroup::metadataPathForSequence($run, $sequence);
        $groupIndex = null;
        foreach ($path as $index => $candidate) {
            if ($candidate['parallel_group_base_sequence'] === $sequence
                && $candidate['parallel_group_size'] === $span) {
                $groupIndex = $index;
                break;
            }
        }
        if ($groupIndex === null) {
            return 'cancellation_delivery_shape_mismatch';
        }
        $group = $path[$groupIndex];
        $allResolved = true;
        for ($offset = 0; $offset < $span; ++$offset) {
            $memberPath = ParallelChildGroup::metadataPathForSequence($run, $sequence + $offset);
            if (($memberPath[$groupIndex]['parallel_group_id'] ?? null) !== $group['parallel_group_id']
                || ($memberPath[$groupIndex]['parallel_group_index'] ?? null) !== $offset) {
                return 'cancellation_delivery_shape_mismatch';
            }
            $allResolved = $allResolved && self::resolvedBeforeRequest($run, $sequence + $offset, $request);
            if ($run->historyEvents->contains(
                static fn (WorkflowHistoryEvent $event): bool => ($event->payload['sequence'] ?? null)
                    === $sequence + $offset
                    && in_array($event->event_type, [
                        HistoryEventType::ActivityFailed,
                        HistoryEventType::ActivityCancelled,
                        HistoryEventType::ActivityTimedOut,
                        HistoryEventType::ChildRunFailed,
                        HistoryEventType::ChildRunCancelled,
                        HistoryEventType::ChildRunTerminated,
                    ], true)
                    && $event->sequence < $request->sequence,
            )) {
                return 'cancellation_delivery_not_eligible';
            }
        }
        if ($run->historyEvents->contains(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::SelectionResolved
                && ($event->payload['selection_group_id'] ?? null) === ($group['parallel_group_id'] ?? null)
                && $event->sequence < $request->sequence,
        )) {
            return 'cancellation_delivery_not_eligible';
        }

        return $allResolved ? 'cancellation_delivery_not_eligible' : null;
    }
}
