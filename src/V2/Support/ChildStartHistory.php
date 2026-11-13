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
            !== $child->childInstanceId || ($resolution->payload['child_call_id'] ?? null) !== $child->childCallId)) {
            throw self::mismatch($sequence, 'The terminal outcome belongs to a different child admission.');
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
