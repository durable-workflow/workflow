<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\ScopedCancellationContext;

/** @internal Read completed descendants through their original delivery proof, without actor authority. */
final class ScopedCancellationReconciliation
{
    private function __construct(
        private readonly ScopedCancellationPreparation $preparation,
        private readonly WorkflowHistoryEvent $delivery,
    ) {
    }

    public static function completed(
        WorkflowRun $run,
        ScopedCancellationPreparation $current,
        ?int $beforeHistorySequence = null,
    ): ?self {
        return self::completedFrom($run, self::earlier($run, $current), $beforeHistorySequence);
    }

    public static function pending(
        WorkflowRun $run,
        ScopedCancellationPreparation $current,
        ?int $beforeHistorySequence = null,
    ): bool {
        $candidates = self::earlier($run, $current);
        return $candidates !== [] && self::completedFrom($run, $candidates, $beforeHistorySequence) === null;
    }

    public static function assertDispatchable(WorkflowRun $run, ScopedCancellationPreparation $current): void
    {
        if (self::pending($run, $current)) {
            throw new LogicException('cancellation_scope_descendant_delivery_pending');
        }
    }

    /**
     * Preserve the existing response arrays using only the original marker's prefix.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function receipts(WorkflowRun $run): array
    {
        if ($run->id !== $this->preparation->workflowRunId) {
            throw new LogicException('cancellation_scope_preparation_reference_invalid');
        }
        $run->loadMissing('historyEvents');
        $history = $run->historyEvents;
        $run->setRelation('historyEvents', $history->filter(
            fn (WorkflowHistoryEvent $event): bool => $event->sequence < $this->delivery->sequence
        ));
        try {
            $result = [
                'activity_cancellations' => [],
                'timer_cancellations' => [],
                'wait_cancellations' => [],
                'child_cancellations' => [],
            ];
            foreach ($this->preparation->timerMembers as $member) {
                $terminal = ScopedTimerCancellation::terminal($run, $member['timer_id'], $member['sequence']);
                if ($terminal === null) {
                    throw new LogicException('cancellation_scope_timer_fence_not_established');
                }
                $cancelled = $terminal->event_type === HistoryEventType::TimerCancelled;
                $result['timer_cancellations'][] = [
                    'fenced' => $cancelled,
                    'history_event_id' => $cancelled ? $terminal->id : null,
                    'timer_id' => $member['timer_id'],
                    'sequence' => $member['sequence'],
                ];
            }
            foreach ($this->preparation->waitMembers as $member) {
                $terminal = ScopedWaitCancellation::terminal($run, $member);
                if ($terminal === null) {
                    throw new LogicException('cancellation_scope_wait_fence_not_established');
                }
                $result['wait_cancellations'][] = [
                    'kind' => $member['kind'],
                    'wait_id' => $member['wait_id'],
                    'sequence' => $member['sequence'],
                    'cancelled' => in_array($terminal->event_type, [HistoryEventType::SignalWaitCancelled,
                        HistoryEventType::ConditionWaitCancelled], true),
                    'history_event_id' => $terminal->id,
                ];
            }
            foreach ($this->preparation->activityMembers as $member) {
                $scheduled = $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
                    $event->event_type === HistoryEventType::ActivityScheduled
                    && ($event->payload['activity_execution_id'] ?? null) === $member['activity_execution_id']);
                $cancelled = $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
                    $event->event_type === HistoryEventType::ActivityCancelled
                    && ($event->payload['activity_execution_id'] ?? null) === $member['activity_execution_id']);
                $receipt = [
                    'fenced' => false,
                    'abandoned' => ($scheduled?->payload['activity']['cancellation_policy'] ?? null)
                        === CancellationPolicy::Abandon->value,
                    'waiting_for_stop' => false,
                    'history_event_id' => null,
                ];
                if ($cancelled !== null) {
                    $snapshot = $cancelled->payload['cancellation_scope'];
                    $context = $this->preparation->context;
                    $receipt = [
                        'fenced' => true,
                        'abandoned' => false,
                        'history_event_id' => $cancelled->id,
                        'request_id' => $context->requestId,
                        'root_request_id' => $context->rootContext->rootRequestId,
                        'scope_id' => $context->scopeId,
                        'cleanup_deadline_at' => $context->deadline()
                            ->toISOString(),
                        'cancellation_scope' => [
                            'schema' => $snapshot['schema'],
                            'workflow_run_id' => $snapshot['workflow_run_id'],
                            'scope_id' => $snapshot['scope_id'],
                            'request_id' => $snapshot['request_id'],
                            'request_history_event_id' => $snapshot['request_history_event_id'],
                            'cancellation' => ScopedCancellationContext::fromArray(
                                $snapshot['cancellation']
                            )->toArray(),
                            'authority_deadline_at' => $snapshot['authority_deadline_at'],
                        ],
                        'waiting_for_stop' => ($scheduled?->payload['activity']['cancellation_policy'] ?? null)
                            === CancellationPolicy::WaitCancellationCompleted->value
                            && ! ActivityCancellationCompletion::resolved(
                                $run,
                                $member['activity_execution_id'],
                                $context
                            ),
                    ];
                }
                $result['activity_cancellations'][] = [
                    'sequence' => $member['sequence'],
                    'activity_execution_id' => $member['activity_execution_id'],
                    ...$receipt,
                ];
            }
            foreach ($this->preparation->childMembers as $member) {
                $matches = $run->historyEvents->filter(static fn (WorkflowHistoryEvent $event): bool =>
                    ($event->payload['sequence'] ?? null) === $member['sequence'] && $event->workflow_command_id === null);
                $receipt = $matches->firstWhere('event_type', HistoryEventType::ChildCancellationRequested);
                $resolved = $matches->firstWhere('event_type', HistoryEventType::ChildCancellationResolved);
                if ($receipt === null) {
                    throw new LogicException('cancellation_scope_child_delivery_not_established');
                }
                $result['child_cancellations'][] = [
                    'child_call_id' => $member['child_call_id'],
                    'child_workflow_run_id' => $member['child_workflow_run_id'],
                    'history_event_id' => $receipt->id,
                    'resolution_history_event_id' => $resolved?->id,
                    'request_id' => $receipt->payload['child_request_id'],
                    'policy' => $member['cancellation_policy'],
                    'ready' => $member['cancellation_policy'] !== CancellationPolicy::WaitCancellationCompleted->value
                        || $resolved !== null,
                ];
            }
            return $result;
        } finally {
            $run->setRelation('historyEvents', $history);
        }
    }

    /**
     * @param list<array{scope_id: string, reference: ScopedCancellationPreparation}> $candidates
     */
    private static function completedFrom(
        WorkflowRun $run,
        array $candidates,
        ?int $beforeHistorySequence,
    ): ?self {
        foreach ($candidates as $candidate) {
            $event = $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
                $event->event_type === HistoryEventType::CancellationScopeDelivered
                && ($event->payload['scope_id'] ?? null) === $candidate['scope_id']);
            if ($event === null || ($beforeHistorySequence !== null && $event->sequence >= $beforeHistorySequence)) {
                continue;
            }
            // Delivery may finish after ancestor preparation, but must precede
            // the consuming marker. Strictly earlier preparations keep reads finite.
            $delivery = CancellationScopeDelivery::recorded($run, $candidate['scope_id']);
            if ($delivery === null || $delivery->id !== $event->id) {
                throw new LogicException('cancellation_scope_delivery_history_invalid');
            }
            return new self($candidate['reference'], $delivery);
        }
        return null;
    }

    /**
     * @return list<array{scope_id: string, reference: ScopedCancellationPreparation}>
     */
    private static function earlier(WorkflowRun $run, ScopedCancellationPreparation $current): array
    {
        if ($current->workflowRunId !== $run->id) {
            throw new LogicException('cancellation_scope_preparation_reference_invalid');
        }
        $run->loadMissing('historyEvents');
        $candidates = [];
        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if ($event->event_type !== HistoryEventType::CancellationScopeDeliveryPrepared
                || $event->sequence >= $current->historySequence) {
                continue;
            }
            $scopeId = $event->payload['scope_id'] ?? null;
            if (! is_string($scopeId)) {
                throw new LogicException('cancellation_scope_preparation_history_invalid');
            }
            if ($scopeId !== $current->scopeId && ! collect($event->payload['descendant_members'] ?? [])
                ->contains('scope_id', $current->scopeId)) {
                continue;
            }
            $prepared = CancellationScopeDelivery::prepared($run, $scopeId);
            if ($prepared === null || $prepared->id !== $event->id) {
                throw new LogicException('cancellation_scope_preparation_history_invalid');
            }
            $original = ScopedCancellationPreparation::forScope($run, $current->scopeId, $prepared->id);
            if ($original === null || $original->requestHistoryEventId !== $current->requestHistoryEventId
                || $original->context->toArray() !== $current->context->toArray()
                || $original->activityMembers !== $current->activityMembers
                || $original->timerMembers !== $current->timerMembers
                || $original->waitMembers !== $current->waitMembers
                || $original->childMembers !== $current->childMembers) {
                throw new LogicException('cancellation_scope_descendant_delivery_membership_mismatch');
            }
            // Captured ceilings can differ. Neither a later preparation nor a
            // completed proof transfers or renews the original actor authority.
            $candidates[] = [
                'scope_id' => $scopeId,
                'reference' => $original,
            ];
        }
        return $candidates;
    }
}
