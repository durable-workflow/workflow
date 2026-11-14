<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartedChildWorkflow;

/** @internal Confirmed admission comes from paired native history, never a queued request. */
final class ChildStartHistory
{
    public static function receipt(WorkflowRun $run, int $sequence): ?StartedChildWorkflow
    {
        $scheduled = ChildRunHistory::scheduledEventForSequence($run, $sequence);
        $started = $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::ChildRunStarted
            && ($event->payload['sequence'] ?? null) === $sequence);
        if ($scheduled === null && $started === null) {
            return null;
        }
        if ($scheduled === null || $started === null
            || ($scheduled->payload['child_completion_mode'] ?? null) !== 'started') {
            throw self::mismatch($sequence, 'A confirmed start needs its original schedule and native admission.');
        }
        foreach (['child_workflow_instance_id', 'child_workflow_run_id', 'child_call_id',
            'child_workflow_type'] as $key) {
            if (! is_string($scheduled->payload[$key] ?? null) || $scheduled->payload[$key] === ''
                || ($started->payload[$key] ?? null) !== $scheduled->payload[$key]) {
                throw self::mismatch($sequence, 'The native child admission differs from its owning schedule.');
            }
        }
        if ($scheduled->workflow_run_id !== $run->id || $started->workflow_run_id !== $run->id) {
            throw self::mismatch($sequence, 'Child admission history belongs to another parent.');
        }
        if (($scheduled->payload['child_reservation'] ?? null) !== ($started->payload['child_reservation'] ?? null)) {
            throw self::mismatch($sequence, 'The native child reservation differs between its paired history events.');
        }
        return new StartedChildWorkflow(
            (string) $run->namespace,
            $run->workflow_instance_id,
            $run->id,
            $sequence,
            $scheduled->payload['child_workflow_instance_id'],
            $scheduled->payload['child_workflow_run_id'],
            $scheduled->payload['child_call_id'],
            $scheduled->payload['child_workflow_type']
        );
    }

    public static function assertReceipt(WorkflowRun $run, StartedChildWorkflow $child, int $sequence): void
    {
        $recorded = self::receipt($run, $child->startSequence);
        if ($recorded === null || $recorded->toArray() !== $child->toArray()
            || $child->startSequence >= $sequence) {
            throw self::mismatch($sequence, 'The child receipt is not an earlier admission of this parent execution.');
        }
    }

    public static function cancellationDelivery(
        WorkflowRun $run,
        StartedChildWorkflow $child,
        int $sequence,
    ): ?WorkflowHistoryEvent {
        $event = CooperativeCancellationDelivery::recorded($run);
        if ($event === null || ($event->payload['sequence'] ?? null) !== $sequence) {
            return null;
        }
        $invalid = CooperativeCancellationDelivery::validate(
            $run,
            (string) $run->cancellation_request_command_id,
            $sequence,
            'started_child_await',
            operationSequence: $child->startSequence
        );
        if ($invalid !== null) {
            throw self::mismatch($sequence, $invalid);
        }
        return $event;
    }

    public static function awaitedEvent(
        WorkflowRun $run,
        StartedChildWorkflow $child,
        int $sequence,
    ): ?WorkflowHistoryEvent {
        $event = $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::ChildWorkflowAwaited
            && ($event->payload['sequence'] ?? null) === $sequence);
        if ($event !== null) {
            foreach (self::awaitPayload($child, $sequence) as $key => $value) {
                if (($event->payload[$key] ?? null) !== $value) {
                    throw self::mismatch($sequence, 'The await step belongs to a different child admission.');
                }
            }
        }
        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    public static function awaitPayload(StartedChildWorkflow $child, int $sequence): array
    {
        return [
            'sequence' => $sequence,
            'receipt' => $child->toArray(),
            'child_workflow_instance_id' => $child->childInstanceId,
            'child_workflow_run_id' => $child->childRunId,
            'child_call_id' => $child->childCallId,
            'child_workflow_type' => $child->workflowType,
        ];
    }

    /**
     * Resolve only the recorded chain, never mutable current-run order.
     */
    public static function target(
        WorkflowRun $parent,
        StartedChildWorkflow $child,
        ?WorkflowHistoryEvent $resolution,
        int $sequence,
    ): ?WorkflowRun {
        $starts = $parent->historyEvents->filter(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::ChildRunStarted
            && ($event->payload['sequence'] ?? null) === $child->startSequence)->sortBy('sequence');
        $previous = null;
        $seen = [];
        foreach ($starts as $event) {
            $id = self::recordedRunId($parent, $child, $event, $seen, $sequence);
            /** @var WorkflowRun|null $target */
            $target = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->where('namespace', $child->namespace)
                ->where('workflow_instance_id', $child->childInstanceId)
                ->where('workflow_type', $child->workflowType)
                ->find($id);
            if ($target === null) {
                // A complete parent outcome can outlive the initial child's
                // detail row. It cannot invent a missing descendant chain.
                if ($id === $child->childRunId && $starts->count() === 1 && $resolution !== null) {
                    self::assertResolution($child, $resolution, null, $sequence);
                    return null;
                }
                throw self::mismatch($sequence, 'The admitted child lineage is unavailable.');
            }
            $target->setRelation('historyEvents', $target->historyEvents()->whereIn('event_type', [
                HistoryEventType::WorkflowStarted, HistoryEventType::WorkflowContinuedAsNew,
                HistoryEventType::WorkflowCompleted, HistoryEventType::WorkflowFailed,
                HistoryEventType::WorkflowCancelled, HistoryEventType::WorkflowTerminated,
            ])->get());
            if ($previous !== null) {
                self::assertSuccessor($parent, $child, $previous, $target, $event, $sequence);
            }
            $seen[$id] = true;
            $previous = $target;
        }
        if ($previous === null || $previous->closed_reason === 'continued') {
            throw self::mismatch($sequence, 'The admitted child has no complete recorded continuation chain.');
        }
        self::assertResolution($child, $resolution, $previous, $sequence);
        return $previous;
    }

    public static function assertResolution(
        StartedChildWorkflow $child,
        ?WorkflowHistoryEvent $resolution,
        ?WorkflowRun $target,
        int $sequence,
    ): void {
        if ($target !== null && ($target->namespace !== $child->namespace
            || $target->workflow_instance_id !== $child->childInstanceId
            || $target->workflow_type !== $child->workflowType)) {
            throw self::mismatch($sequence, 'The child target escaped its admitted namespace or instance.');
        }
        if ($resolution !== null && (($resolution->payload['child_workflow_instance_id'] ?? null)
            !== $child->childInstanceId || ($resolution->payload['child_call_id'] ?? null) !== $child->childCallId
            || ($resolution->payload['child_workflow_run_id'] ?? null) !== ($target?->id ?? $child->childRunId)
            || ($resolution->payload['child_workflow_type'] ?? null) !== $child->workflowType)) {
            throw self::mismatch($sequence, 'The terminal outcome belongs to a different child admission.');
        }
    }

    private static function recordedRunId(
        WorkflowRun $parent,
        StartedChildWorkflow $child,
        WorkflowHistoryEvent $event,
        array $seen,
        int $sequence,
    ): string {
        $id = $event->payload['child_workflow_run_id'] ?? null;
        if (! is_string($id) || isset($seen[$id])
            || $event->workflow_run_id !== $parent->id
            || ($event->payload['child_call_id'] ?? null) !== $child->childCallId
            || ($event->payload['child_workflow_instance_id'] ?? null) !== $child->childInstanceId
            || ($event->payload['child_workflow_type'] ?? null) !== $child->workflowType) {
            throw self::mismatch($sequence, 'The child admission chain is not linear or has substituted identity.');
        }
        if ($seen === [] && $id !== $child->childRunId) {
            throw self::mismatch($sequence, 'The child chain does not begin at the admitted run.');
        }
        return $id;
    }

    private static function assertSuccessor(
        WorkflowRun $parent,
        StartedChildWorkflow $child,
        WorkflowRun $predecessor,
        WorkflowRun $successor,
        WorkflowHistoryEvent $parentStart,
        int $sequence,
    ): void {
        $starts = $successor->historyEvents->filter(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::WorkflowStarted);
        $started = $starts->first();
        $payload = $started?->payload ?? [];
        if ($starts->count() !== 1 || ! is_string($payload['workflow_link_id'] ?? null)
            || $payload['workflow_link_id'] === ''
            || ! is_string($parentStart->payload['workflow_link_id'] ?? null)
            || $parentStart->payload['workflow_link_id'] === ''
            || ($payload['workflow_instance_id'] ?? null) !== $child->childInstanceId
            || ($payload['workflow_type'] ?? null) !== $child->workflowType
            || ($payload['workflow_run_id'] ?? null) !== $successor->id
            || ($payload['parent_workflow_instance_id'] ?? null) !== $parent->workflow_instance_id
            || ($payload['parent_workflow_run_id'] ?? null) !== $parent->id
            || ($payload['parent_sequence'] ?? null) !== $child->startSequence
            || ($payload['child_call_id'] ?? null) !== $child->childCallId) {
            throw self::mismatch($sequence, 'The successor start is not bound to the admitted child call.');
        }
        $retry = ($payload['retry_of_child_workflow_run_id'] ?? null) === $predecessor->id
            && $parentStart->payload['workflow_link_id'] === $payload['workflow_link_id']
            && ($payload['continued_from_run_id'] ?? null) === null
            && ($payload['recovery_kind'] ?? null) !== 'redrive'
            && ($parentStart->payload['retry_of_child_workflow_run_id'] ?? null) === $predecessor->id
            && $predecessor->historyEvents->contains(static fn (WorkflowHistoryEvent $event): bool =>
                $event->event_type === HistoryEventType::WorkflowFailed);
        $continued = ($payload['continued_from_run_id'] ?? null) === $predecessor->id
            && ($payload['retry_of_child_workflow_run_id'] ?? null) === null
            && ($payload['recovery_kind'] ?? null) !== 'redrive'
            && $predecessor->historyEvents->contains(static fn (WorkflowHistoryEvent $event): bool =>
                $event->event_type === HistoryEventType::WorkflowContinuedAsNew
                && ($event->payload['continued_to_run_id'] ?? null) === $successor->id
                && ($event->payload['workflow_link_id'] ?? null) === ($payload['workflow_link_id'] ?? null));
        if (! $retry && ! $continued) {
            throw self::mismatch($sequence, 'The successor lacks the exact typed retry or continuation edge.');
        }
    }

    private static function mismatch(int $sequence, string $detail): HistoryEventShapeMismatchException
    {
        return new HistoryEventShapeMismatchException(
            $sequence,
            'confirmed child admission',
            [HistoryEventType::ChildRunStarted->value],
            $detail
        );
    }
}
