<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

/** Canonical proof for an activity cancellation wait. A fence alone never proves callback exit. */
final class ActivityCancellationCompletion
{
    public static function resolved(WorkflowRun $run, string $executionId): bool
    {
        if (! $run->relationLoaded('historyEvents')) {
            $run->loadMissing('historyEvents');
        }
        $scheduled = $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::ActivityScheduled
            && ($event->payload['activity_execution_id'] ?? null) === $executionId);
        if (! $scheduled instanceof WorkflowHistoryEvent
            || ! is_int($scheduled->payload['sequence'] ?? null) || $scheduled->payload['sequence'] < 1
            || CooperativeCancellationDelivery::context($run) === null) {
            return false;
        }

        $cancelled = $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::ActivityCancelled
            && ($event->payload['activity_execution_id'] ?? null) === $executionId
            && $event->workflow_command_id === $run->cancellation_request_command_id);
        $started = $run->historyEvents->filter(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::ActivityStarted
            && ($event->payload['activity_execution_id'] ?? null) === $executionId)->sortByDesc('sequence')->first();
        if ($started instanceof WorkflowHistoryEvent && is_string($started->payload['activity_attempt_id'] ?? null)
            && $run->historyEvents->contains(static fn (WorkflowHistoryEvent $event): bool =>
                in_array(
                    $event->event_type,
                    [HistoryEventType::ActivityCompleted, HistoryEventType::ActivityFailed],
                    true
                )
                && ($event->payload['activity_execution_id'] ?? null) === $executionId
                && ($event->payload['activity_attempt_id'] ?? null) === $started->payload['activity_attempt_id']
                && ($event->payload['sequence'] ?? null) === ($scheduled->payload['sequence'] ?? null)
                && $event->sequence > $started->sequence
                && ($cancelled === null || $event->sequence < $cancelled->sequence))) {
            return true;
        }

        if (! $cancelled instanceof WorkflowHistoryEvent) {
            return false;
        }
        if ($cancelled->sequence <= $scheduled->sequence
            || ($cancelled->payload['sequence'] ?? null) !== ($scheduled->payload['sequence'] ?? null)
            || ! array_key_exists('activity_attempt_id', $cancelled->payload)
            || ! array_key_exists('activity_attempt', $cancelled->payload)) {
            return false;
        }
        $attemptId = $cancelled->payload['activity_attempt_id'] ?? null;
        if ($attemptId === null) {
            // No Started event and a canonical no-attempt fence prove that atomic
            // cancellation won before callback admission. Missing history cannot.
            return ($cancelled->payload['activity_attempt'] ?? null) === null
                && ! $run->historyEvents->contains(static fn (WorkflowHistoryEvent $event): bool =>
                    $event->event_type === HistoryEventType::ActivityStarted
                    && ($event->payload['activity_execution_id'] ?? null) === $executionId);
        }

        return self::stopReceipt($run, $cancelled) !== null;
    }

    public static function stopReceipt(WorkflowRun $run, WorkflowHistoryEvent $cancelled): ?WorkflowHistoryEvent
    {
        $context = CooperativeCancellationDelivery::context($run);
        $snapshot = $cancelled->payload['activity_attempt'] ?? null;
        $attemptId = $cancelled->payload['activity_attempt_id'] ?? null;
        $executionId = $cancelled->payload['activity_execution_id'] ?? null;
        if ($context === null || ! is_string($cancelled->id) || $cancelled->id === ''
            || $cancelled->event_type !== HistoryEventType::ActivityCancelled
            || $cancelled->workflow_command_id !== $context->requestId
            || ! is_string($attemptId) || $attemptId === ''
            || ! is_string($executionId) || $executionId === ''
            || ! is_array($snapshot) || ($snapshot['id'] ?? null) !== $attemptId
            || ($snapshot['activity_execution_id'] ?? null) !== $executionId
            || ($snapshot['status'] ?? null) !== ActivityAttemptStatus::Cancelled->value
            || ! is_string($snapshot['lease_owner'] ?? null) || $snapshot['lease_owner'] === '') {
            return null;
        }
        $source = ($cancelled->payload['local_activity'] ?? false) === true ? 'workflow_worker' : 'activity_worker';
        return $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::ActivityCancellationAcknowledged
            && $event->sequence > $cancelled->sequence
            && $event->workflow_command_id === $context->requestId
            && ($event->payload['sequence'] ?? null) === ($cancelled->payload['sequence'] ?? null)
            && ($event->payload['activity_execution_id'] ?? null) === $executionId
            && ($event->payload['activity_attempt_id'] ?? null) === $attemptId
            && ($event->payload['lease_owner'] ?? null) === $snapshot['lease_owner']
            && ($event->payload['cancellation_history_event_id'] ?? null) === $cancelled->id
            && ($event->payload['request_id'] ?? null) === $context->requestId
            && ($event->payload['root_request_id'] ?? null) === $context->rootRequestId
            && ($event->payload['cleanup_deadline_at'] ?? null) === $context->deadline()->toISOString()
            && ($event->payload['callback_state'] ?? null) === 'stopped'
            && ($event->payload['evidence_source'] ?? null) === $source);
    }
}
