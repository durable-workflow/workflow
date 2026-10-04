<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\ScopedCancellationContext;

/** @internal Original unshielded subtree inventory, recorded before operation actors acquire locks. */
final class CancellationScopeDescendants
{
    /**
     * The caller holds the original preparation's run/claim locks and commits all requests with that preparation.
     */
    public static function request(WorkflowRun $run, string $scopeId): void
    {
        if ($run->getConnection()->transactionLevel() === 0) {
            throw new LogicException('cancellation_scope_descendants_require_preparation_transaction');
        }
        foreach (self::scopes($run, $scopeId) as $scope) {
            CancellationScopeRequests::request(
                $run,
                $scope['scope_id'],
                CancellationScopeHistory::MINIMUM_PROTOCOL_VERSION,
                parentScopeId: $scope['parent_scope_id'],
            );
        }
        $run->unsetRelation('historyEvents');
    }

    /**
     * Read only the caller's history prefix. Later scope openings or requests cannot change this inventory.
     *
     * @return list<array<string, mixed>>
     */
    public static function members(WorkflowRun $run, string $scopeId, string $authorityDeadline): array
    {
        $run->loadMissing('historyEvents');
        $members = [];
        foreach (self::scopes($run, $scopeId) as $scope) {
            $request = self::accepted($run, $scope['scope_id']);
            $context = CancellationScopeRequests::context($run, $scope['scope_id']);
            $parent = CancellationScopeRequests::context($run, $scope['parent_scope_id']);
            if ($request === null || $context === null || $parent === null) {
                throw new LogicException('cancellation_scope_descendant_request_history_invalid');
            }
            $propagation = self::propagation($run, $scope, $request, $context, $parent);
            $deadline = CarbonImmutable::parse($authorityDeadline);
            foreach (CancellationScopeHistory::ancestry($run, $scope['scope_id']) as $ancestor) {
                $accepted = self::accepted($run, $ancestor);
                if ($accepted !== null) {
                    $ceiling = ScopedCancellationContext::fromArray($accepted->payload['cancellation'])->deadline();
                    if ($ceiling->lt($deadline)) {
                        $deadline = $ceiling;
                    }
                }
            }
            $members[] = [
                'scope_id' => $scope['scope_id'],
                'parent_scope_id' => $scope['parent_scope_id'],
                'scope_history_event_id' => $scope['history_event_id'],
                'request_history_event_id' => $request->id,
                'request_id' => $context->requestId,
                'propagation_history_event_id' => $propagation->id,
                'cancellation' => $context->toArray(),
                'authority_deadline_at' => $deadline->toISOString(),
                'activity_members' => CancellationScopeDelivery::activityMembers($run, $scope['scope_id']),
                'timer_members' => ScopedTimerCancellation::members($run, $scope['scope_id']),
                'wait_members' => ScopedWaitCancellation::members($run, $scope['scope_id']),
                'child_members' => ScopedChildCancellation::members($run, $scope['scope_id']),
            ];
        }
        return self::normalizeMembers($members);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function normalizeMembers(mixed $members): array
    {
        if (! is_array($members) || ! array_is_list($members)) {
            throw new LogicException('cancellation_scope_preparation_history_invalid');
        }
        $normalized = [];
        $keys = ['scope_id', 'parent_scope_id', 'scope_history_event_id', 'request_history_event_id',
            'request_id', 'propagation_history_event_id', 'authority_deadline_at'];
        foreach ($members as $member) {
            if (! is_array($member) || count($member) !== 12 || ! is_array($member['cancellation'] ?? null)) {
                throw new LogicException('cancellation_scope_preparation_history_invalid');
            }
            $value = [];
            foreach ($keys as $key) {
                if (! is_string($member[$key] ?? null) || $member[$key] === '') {
                    throw new LogicException('cancellation_scope_preparation_history_invalid');
                }
                $value[$key] = $member[$key];
            }
            try {
                $context = ScopedCancellationContext::fromArray($member['cancellation']);
            } catch (InvalidArgumentException $error) {
                throw new LogicException('cancellation_scope_preparation_history_invalid', previous: $error);
            }
            $normalized[] = [
                ...$value,
                'cancellation' => $context->toArray(),
                'activity_members' => CancellationScopeDelivery::normalizeMembers($member['activity_members'] ?? null),
                'timer_members' => ScopedTimerCancellation::normalizeMembers($member['timer_members'] ?? null),
                'wait_members' => ScopedWaitCancellation::normalizeMembers($member['wait_members'] ?? null),
                'child_members' => ScopedChildCancellation::normalizeMembers($member['child_members'] ?? null),
            ];
        }
        return $normalized;
    }

    /**
     * A parent marker cannot skip unresolved original descendants. Operation dispatch remains separate.
     *
     * @param list<array<string, mixed>> $members
     */
    public static function assertReady(
        WorkflowRun $run,
        array $members,
        ?int $beforeHistorySequence = null,
        ?string $preparationHistoryEventId = null,
    ): void {
        foreach (self::normalizeMembers($members) as $member) {
            if ($member['activity_members'] === [] && $member['timer_members'] === []
                && $member['wait_members'] === [] && $member['child_members'] === []) {
                continue;
            }
            $preparation = ScopedCancellationPreparation::forScope(
                $run,
                $member['scope_id'],
                $preparationHistoryEventId
            );
            if ($preparation === null) {
                throw new LogicException('cancellation_scope_descendant_delivery_not_prepared');
            }
            foreach (['activity_members', 'timer_members', 'wait_members', 'child_members'] as $field) {
                $normalized = match ($field) {
                    'activity_members' => $preparation->activityMembers,
                    'timer_members' => $preparation->timerMembers,
                    'wait_members' => $preparation->waitMembers,
                    'child_members' => $preparation->childMembers,
                };
                if ($normalized !== $member[$field] || $preparation->requestId !== $member['request_id']
                    || $preparation->context->toArray() !== $member['cancellation']
                    || $preparation->authorityDeadlineAt !== $member['authority_deadline_at']) {
                    throw new LogicException('cancellation_scope_descendant_delivery_membership_mismatch');
                }
            }
            $context = ScopedCancellationContext::fromArray($member['cancellation']);
            foreach ($member['activity_members'] as $activity) {
                ScopedActivityDeliveryPolicy::assertReady(
                    $run,
                    $context,
                    $activity['sequence'],
                    1,
                    $beforeHistorySequence
                );
            }
            ScopedTimerCancellation::assertReady($run, $preparation, $beforeHistorySequence);
            ScopedWaitCancellation::assertReady($run, $preparation, $beforeHistorySequence);
            ScopedChildCancellation::assertReady($preparation, $run, $beforeHistorySequence);
        }
    }

    /**
     * @return list<array{scope_id: string, parent_scope_id: string, shield_parent: bool, sequence: int, history_event_id: string}>
     */
    private static function scopes(WorkflowRun $run, string $scopeId): array
    {
        $run->loadMissing('historyEvents');
        $prefix = $run->historyEvents->keyBy('id');
        $scopes = CancellationScopeHistory::forRun($run);
        if (! isset($scopes[$scopeId])) {
            throw new LogicException('cancellation_scope_not_recorded');
        }
        $included = [
            $scopeId => true,
        ];
        $descendants = [];
        foreach ($scopes as $id => $scope) {
            if ($id === $scopeId || ! $prefix->has($scope['history_event_id'])
                || $scope['shield_parent'] || ! isset($included[$scope['parent_scope_id']])) {
                continue;
            }
            $included[$id] = true;
            $descendants[] = $scope;
        }
        return $descendants;
    }

    private static function accepted(WorkflowRun $run, string $scopeId): ?WorkflowHistoryEvent
    {
        $events = $run->historyEvents->filter(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::CancellationScopeRequested
            && ($event->payload['scope_id'] ?? null) === $scopeId);
        if ($events->count() > 1) {
            throw new LogicException('cancellation_scope_descendant_request_history_invalid');
        }
        return $events->first();
    }

    /**
     * @param array{scope_id: string, parent_scope_id: string, shield_parent: bool, sequence: int, history_event_id: string} $scope
     */
    private static function propagation(
        WorkflowRun $run,
        array $scope,
        WorkflowHistoryEvent $request,
        ScopedCancellationContext $accepted,
        ScopedCancellationContext $parent,
    ): WorkflowHistoryEvent {
        if ($accepted->rootContext->rootRequestId === $parent->rootContext->rootRequestId) {
            if (($request->payload['parent_scope_id'] ?? null) !== $scope['parent_scope_id']) {
                throw new LogicException('cancellation_scope_descendant_request_history_invalid');
            }
            return $request;
        }
        foreach ($run->historyEvents as $event) {
            $payload = $event->payload;
            if ($event->event_type !== HistoryEventType::CancellationScopeRequestConflicted
                || ($payload['scope_id'] ?? null) !== $scope['scope_id']
                || ($payload['parent_scope_id'] ?? null) !== $scope['parent_scope_id']) {
                continue;
            }
            $incoming = ScopedCancellationContext::fromArray($payload['incoming_cancellation']);
            $priorAccepted = ScopedCancellationContext::fromArray($payload['accepted_cancellation']);
            if ($incoming->rootContext->rootRequestId === $parent->rootContext->rootRequestId
                && $priorAccepted->toArray() === $accepted->toArray()
                && $incoming->toArray() === $parent->forDescendant(
                    $incoming->requestId,
                    $run->workflow_instance_id,
                    $run->id,
                    $scope['scope_id'],
                )->toArray()) {
                return $event;
            }
        }
        throw new LogicException('cancellation_scope_descendant_request_history_invalid');
    }
}
