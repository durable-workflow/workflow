<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonImmutable;
use LogicException;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

/** @internal Source remote-activity lifetime. SDK and local policy admission remain separate gates. */
final class ActivityAbandonment
{
    public static function scheduled(WorkflowRun $run, ActivityExecution $execution): ?WorkflowHistoryEvent
    {
        if (! $run->relationLoaded('historyEvents')) {
            $run->loadMissing('historyEvents');
        }
        return $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::ActivityScheduled
            && ($event->payload['sequence'] ?? null) === $execution->sequence
            && ($event->payload['activity_execution_id'] ?? null) === $execution->id
            && ($event->payload['activity']['id'] ?? null) === $execution->id
            && ($event->payload['activity']['cancellation_policy'] ?? null) === CancellationPolicy::Abandon->value);
    }

    public static function prepare(WorkflowRun $run, ActivityExecution $execution): void
    {
        if (LocalActivityRuntime::isExecution($execution)) {
            throw new LogicException('Local Activity Abandon requires an independent callback lifetime.');
        }
        if (! self::allows($run, $execution)) {
            throw new LogicException(
                'Activity Abandon requires its canonical policy and original finite total deadline.'
            );
        }
    }

    public static function hasTerminalHistory(WorkflowRun $run, WorkflowHistoryEvent $scheduled): bool
    {
        $executionId = $scheduled->payload['activity_execution_id'] ?? null;
        $sequence = $scheduled->payload['sequence'] ?? null;
        if (! is_string($executionId) || ($scheduled->payload['activity']['id'] ?? null) !== $executionId
            || ! is_int($sequence) || $sequence < 1) {
            return false;
        }
        $started = $run->historyEvents->filter(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::ActivityStarted
            && ($event->payload['activity_execution_id'] ?? null) === $executionId)->sortByDesc('sequence')->first();
        return $run->historyEvents->contains(static fn (WorkflowHistoryEvent $event): bool =>
            in_array($event->event_type, [HistoryEventType::ActivityCompleted, HistoryEventType::ActivityFailed,
                HistoryEventType::ActivityCancelled, HistoryEventType::ActivityTimedOut], true)
            && ($event->payload['activity_execution_id'] ?? null) === $executionId
            && ($event->payload['sequence'] ?? null) === $sequence
            && ($started !== null || in_array(
                $event->event_type,
                [HistoryEventType::ActivityCancelled, HistoryEventType::ActivityTimedOut],
                true
            ))
            && $event->sequence > ($started?->sequence ?? $scheduled->sequence)
            && array_key_exists('activity_attempt_id', $event->payload)
            && ($event->payload['activity_attempt_id'] ?? null) === ($started?->payload['activity_attempt_id'] ?? null));
    }

    public static function allows(WorkflowRun $run, ActivityExecution $execution): bool
    {
        if ($run->cancellation_request_command_id === null
            || $execution->workflow_run_id !== $run->id || LocalActivityRuntime::isExecution($execution)) {
            return false;
        }
        $scheduled = self::scheduled($run, $execution);
        $deadline = $scheduled?->payload['activity']['schedule_to_close_deadline_at'] ?? null;
        if (! is_string($deadline) || $deadline === '' || $execution->schedule_to_close_deadline_at === null
            || ! $execution->schedule_to_close_deadline_at->equalTo(CarbonImmutable::parse($deadline))
            || CooperativeCancellationDelivery::context($run) === null
            || $run->historyEvents->contains(static fn (WorkflowHistoryEvent $event): bool =>
                $event->event_type === HistoryEventType::ActivityCancelled
                && ($event->payload['activity_execution_id'] ?? null) === $execution->id)) {
            return false;
        }
        if (! $run->status->isTerminal()) {
            return true;
        }
        // A legacy terminal cancel or termination cannot borrow cooperation's
        // identity to retain authority. The canonical cooperative close must match.
        return $run->status === RunStatus::Cancelled
            && CooperativeCancellationDelivery::recorded($run) !== null
            && $run->historyEvents->contains(static fn (WorkflowHistoryEvent $event): bool =>
                $event->event_type === HistoryEventType::WorkflowCancelled
                && $event->workflow_command_id === $run->cancellation_request_command_id);
    }
}
