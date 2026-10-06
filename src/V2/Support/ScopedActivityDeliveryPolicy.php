<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonImmutable;
use LogicException;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\ScopedCancellationContext;

/** @internal A delivery marker cannot substitute for fencing or callback-stop proof. */
final class ScopedActivityDeliveryPolicy
{
    public static function assertReady(
        WorkflowRun $run,
        ScopedCancellationContext $context,
        int $start,
        int $span,
        ?int $beforeHistorySequence = null,
    ): void {
        $run->loadMissing('historyEvents');
        // Cold replay must prove readiness before the original delivery marker.
        // A later stop receipt cannot retroactively make an early marker valid.
        $history = $run->historyEvents;
        if ($beforeHistorySequence !== null) {
            $run->setRelation('historyEvents', $history->filter(
                static fn (WorkflowHistoryEvent $event): bool => $event->sequence < $beforeHistorySequence
            ));
        }
        try {
            foreach ($run->historyEvents as $scheduled) {
                $sequence = $scheduled->payload['sequence'] ?? null;
                if ($scheduled->event_type !== HistoryEventType::ActivityScheduled
                    || ! is_int($sequence) || $sequence < $start || $sequence - $start >= $span) {
                    continue;
                }
                $executionId = $scheduled->payload['activity_execution_id'] ?? null;
                if (! is_string($executionId) || $executionId === ''
                    || ($scheduled->payload['activity']['id'] ?? null) !== $executionId
                    || $run->historyEvents->filter(static fn (WorkflowHistoryEvent $event): bool =>
                        $event->event_type === HistoryEventType::ActivityScheduled
                        && (($event->payload['activity_execution_id'] ?? null) === $executionId
                            || ($event->payload['sequence'] ?? null) === $sequence))->count() !== 1) {
                    throw new LogicException('cancellation_scope_activity_history_invalid');
                }
                $value = $scheduled->payload['activity']['cancellation_policy'] ?? CancellationPolicy::TryCancel->value;
                $policy = is_string($value) ? CancellationPolicy::tryFrom($value) : null;
                if ($policy === null) {
                    throw new LogicException('cancellation_scope_activity_history_invalid');
                }
                $cancelled = $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
                    $event->event_type === HistoryEventType::ActivityCancelled
                    && ($event->payload['activity_execution_id'] ?? null) === $executionId);
                if ($policy === CancellationPolicy::Abandon) {
                    $deadline = $scheduled->payload['activity']['schedule_to_close_deadline_at'] ?? null;
                    if (($scheduled->payload['local_activity'] ?? false) === true
                        || ($scheduled->payload['execution_mode'] ?? null) === 'local'
                        || ! is_string($deadline) || $deadline === '' || $cancelled !== null) {
                        throw new LogicException('cancellation_scope_activity_abandon_not_supported');
                    }
                    try {
                        if (CarbonImmutable::parse($deadline)->toISOString() !== $deadline) {
                            throw new LogicException('cancellation_scope_activity_abandon_not_supported');
                        }
                    } catch (\InvalidArgumentException $error) {
                        throw new LogicException('cancellation_scope_activity_abandon_not_supported', previous: $error);
                    }
                    if ($beforeHistorySequence === null) {
                        $execution = ActivityExecution::query()->find($executionId);
                        if ($execution === null || $execution->workflow_run_id !== $run->id
                            || $execution->sequence !== $sequence || LocalActivityRuntime::isExecution($execution)
                            || $execution->schedule_to_close_deadline_at === null
                            || $execution->schedule_to_close_deadline_at->toISOString() !== $deadline) {
                            throw new LogicException('cancellation_scope_activity_abandon_not_supported');
                        }
                    }
                    continue;
                }
                if ($cancelled === null && ActivityCancellationCompletion::resolved($run, $executionId, $context)) {
                    continue;
                }
                $original = $cancelled === null ? null : ActivityCancellationContext::forEvent($run, $cancelled);
                if (! $original instanceof ScopedCancellationContext || $original->toArray() !== $context->toArray()) {
                    throw new LogicException('cancellation_scope_activity_fence_not_established');
                }
                if ($beforeHistorySequence === null) {
                    // Mutation is serialized by the caller's run lock. Projection
                    // drift must not turn a history-only fence into permission.
                    $execution = ActivityExecution::query()->find($executionId);
                    if ($execution === null || $execution->workflow_run_id !== $run->id
                        || $execution->status !== ActivityStatus::Cancelled
                        || $execution->current_attempt_id !== ($cancelled->payload['activity_attempt_id'] ?? null)) {
                        throw new LogicException('cancellation_scope_activity_fence_not_established');
                    }
                }
                if ($policy === CancellationPolicy::WaitCancellationCompleted
                    && ! ActivityCancellationCompletion::resolved($run, $executionId, $context)) {
                    throw new LogicException('cancellation_scope_activity_stop_not_acknowledged');
                }
            }
        } finally {
            $run->setRelation('historyEvents', $history);
        }
    }
}
