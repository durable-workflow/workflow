<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonImmutable;
use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\ScopedCancellationContext;

/**
 * @internal An exact history reference and its original direct or descendant snapshot.
 * Reading this value grants no effect authority. Actors recheck their live claim and budget.
 */
final class ScopedCancellationPreparation
{
    /**
     * @param list<array<string, mixed>> $activityMembers
     * @param list<array<string, mixed>> $timerMembers
     * @param list<array<string, mixed>> $waitMembers
     * @param list<array<string, mixed>> $childMembers
     */
    private function __construct(
        public readonly string $workflowRunId,
        public readonly string $historyEventId,
        public readonly int $historySequence,
        public readonly string $preparedScopeId,
        public readonly string $scopeId,
        public readonly string $requestId,
        public readonly string $requestHistoryEventId,
        public readonly ScopedCancellationContext $context,
        public readonly string $authorityDeadlineAt,
        public readonly array $activityMembers,
        public readonly array $timerMembers,
        public readonly array $waitMembers,
        public readonly array $childMembers,
        public readonly CarbonImmutable $recordedAt,
    ) {
    }

    public static function forScope(
        WorkflowRun $run,
        string $scopeId,
        ?string $preparationHistoryEventId = null,
    ): ?self {
        if ($preparationHistoryEventId === null) {
            $event = CancellationScopeDelivery::prepared($run, $scopeId);
            if ($event === null) {
                return null;
            }
        } else {
            $original = $run->historyEvents()
                ->whereKey($preparationHistoryEventId)
                ->where('event_type', HistoryEventType::CancellationScopeDeliveryPrepared)
                ->first();
            $preparedScope = $original?->payload['scope_id'] ?? null;
            if (! is_string($preparedScope) || $preparedScope === '') {
                throw new LogicException('cancellation_scope_preparation_reference_invalid');
            }
            // Use the canonical producer read. An ID cannot bypass its prefix validation.
            $event = CancellationScopeDelivery::prepared($run, $preparedScope);
            if ($event === null || $event->id !== $preparationHistoryEventId) {
                throw new LogicException('cancellation_scope_preparation_reference_invalid');
            }
        }
        $payload = $event->payload;
        $snapshot = $payload;
        if ($payload['scope_id'] !== $scopeId) {
            $members = array_values(array_filter(
                CancellationScopeDescendants::normalizeMembers($payload['descendant_members'] ?? []),
                static fn (array $member): bool => $member['scope_id'] === $scopeId,
            ));
            if (count($members) !== 1) {
                throw new LogicException('cancellation_scope_descendant_not_prepared');
            }
            $snapshot = $members[0];
        }
        $context = ScopedCancellationContext::fromArray($snapshot['cancellation']);
        $request = $run->historyEvents()
            ->where('event_type', HistoryEventType::CancellationScopeRequested)
            ->where('payload->scope_id', $scopeId)
            ->sole();
        if ($context->scopeId !== $scopeId || $context->workflowRunId !== $run->id
            || $context->requestId !== $snapshot['request_id']
            || ($request->payload['request_id'] ?? null) !== $context->requestId
            || $request->sequence >= $event->sequence
            || ($payload['scope_id'] !== $scopeId && $request->id !== $snapshot['request_history_event_id'])) {
            throw new LogicException('cancellation_scope_preparation_reference_invalid');
        }
        $deadline = CarbonImmutable::parse($snapshot['authority_deadline_at']);
        if ($deadline->gt(CarbonImmutable::parse($payload['authority_deadline_at']))
            || $deadline->gt($context->deadline())) {
            throw new LogicException('cancellation_scope_preparation_reference_invalid');
        }
        return new self(
            $run->id,
            $event->id,
            $event->sequence,
            $payload['scope_id'],
            $scopeId,
            $context->requestId,
            $request->id,
            $context,
            $snapshot['authority_deadline_at'],
            CancellationScopeDelivery::normalizeMembers($snapshot['activity_members'] ?? []),
            ScopedTimerCancellation::normalizeMembers($snapshot['timer_members'] ?? []),
            ScopedWaitCancellation::normalizeMembers($snapshot['wait_members'] ?? []),
            ScopedChildCancellation::normalizeMembers($snapshot['child_members'] ?? []),
            CarbonImmutable::parse($event->recorded_at ?? $event->created_at),
        );
    }

    public static function fromEvent(WorkflowRun $run, WorkflowHistoryEvent $event): self
    {
        $scopeId = $event->payload['scope_id'] ?? null;
        if ($event->workflow_run_id !== $run->id || ! is_string($scopeId)) {
            throw new LogicException('cancellation_scope_preparation_reference_invalid');
        }
        return self::forScope($run, $scopeId, $event->id)
            ?? throw new LogicException('cancellation_scope_preparation_reference_invalid');
    }
}
