<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\ScopedCancellationContext;

/**
 * @internal Canonical exact-address delivery boundary for the unfrozen 1.20 model.
 * Consumers must establish selective operation cancellation before injection.
 * Recording alone does not stop callbacks, release a claim or cancel a run.
 */
final class CancellationScopeDelivery
{
    public const SCHEMA = 'durable-workflow.cancellation-scope-delivery/v1';

    public const PREPARATION_SCHEMA = 'durable-workflow.cancellation-scope-preparation/v2';

    /**
     * Commit preparation before acquiring any Activity attempt/execution locks.
     */
    public static function prepare(
        WorkflowRun $run,
        WorkflowTask $task,
        string $scopeId,
        string $requestId,
        int $sequence,
        string $callKind,
        string $protocolVersion,
        int $sequenceSpan = 1,
        ?int $operationSequence = null,
        int $operationSequenceSpan = 1,
    ): WorkflowHistoryEvent {
        if ($run->getConnection()->transactionLevel() !== 0) {
            throw new LogicException('cancellation_scope_preparation_requires_own_transaction');
        }
        return self::writeBoundary(
            $run,
            $task,
            $scopeId,
            $requestId,
            $sequence,
            $callKind,
            $protocolVersion,
            $sequenceSpan,
            $operationSequence,
            $operationSequenceSpan,
            true
        );
    }

    public static function record(
        WorkflowRun $run,
        WorkflowTask $task,
        string $scopeId,
        string $requestId,
        int $sequence,
        string $callKind,
        string $protocolVersion,
        int $sequenceSpan = 1,
        ?int $operationSequence = null,
        int $operationSequenceSpan = 1,
    ): WorkflowHistoryEvent {
        return self::writeBoundary(
            $run,
            $task,
            $scopeId,
            $requestId,
            $sequence,
            $callKind,
            $protocolVersion,
            $sequenceSpan,
            $operationSequence,
            $operationSequenceSpan,
            false
        );
    }

    /**
     * Cold replay reads the original boundary and clock, even after authority ends.
     * The deadline snapshot is an observation, never permission to admit effects.
     */
    public static function recorded(WorkflowRun $run, string $scopeId): ?WorkflowHistoryEvent
    {
        return self::readBoundary($run, $scopeId, false);
    }

    /**
     * The preparation is replay data, not renewed effect authority.
     */
    public static function prepared(WorkflowRun $run, string $scopeId): ?WorkflowHistoryEvent
    {
        return self::readBoundary($run, $scopeId, true);
    }

    /**
     * Preserve recorded-command replay while denying new work in a prepared scope.
     */
    public static function admissionRefusal(WorkflowRun $run, string $scopeId, int $sequence): ?string
    {
        $scopes = CancellationScopeHistory::forRun($run);
        $address = $scopeId;
        while (isset($scopes[$address])) {
            if ($run->historyEvents()->where('event_type', HistoryEventType::CancellationScopeDeliveryPrepared)
                ->where('payload->scope_id', $address)
                ->exists()) {
                $preparation = self::prepared($run, $address);
                $recorded = $run->historyEvents()
                    ->where('sequence', '<', $preparation->sequence)
                    ->whereIn('event_type', [HistoryEventType::ActivityScheduled, HistoryEventType::TimerScheduled,
                        HistoryEventType::ChildWorkflowScheduled, HistoryEventType::SignalWaitOpened,
                        HistoryEventType::ConditionWaitOpened])
                    ->where('payload->sequence', $sequence)
                    ->get()
                    ->contains(static function (WorkflowHistoryEvent $row) use ($scopeId): bool {
                        $payload = $row->payload;
                        $descriptor = match ($row->event_type) {
                            HistoryEventType::ActivityScheduled => $payload['activity'] ?? [],
                            HistoryEventType::TimerScheduled => $payload['timer'] ?? [],
                            HistoryEventType::ChildWorkflowScheduled => $payload['child_workflow'] ?? [],
                            default => [],
                        };
                        $membership = array_key_exists('cancellation_scope_id', $payload)
                            ? $payload['cancellation_scope_id']
                            : ($descriptor['cancellation_scope_id'] ?? CancellationScopeHistory::ROOT_SCOPE_ID);
                        return $membership === $scopeId
                            && (! array_key_exists('cancellation_scope_id', $descriptor)
                                || $descriptor['cancellation_scope_id'] === $scopeId);
                    });
                return $recorded ? null : 'operation_scope_cancellation_prepared';
            }
            if ($scopes[$address]['shield_parent'] || $scopes[$address]['parent_scope_id'] === null) {
                break;
            }
            $address = $scopes[$address]['parent_scope_id'];
        }
        return null;
    }

    /**
     * Preserve the first acknowledged boundary across response loss and replacement.
     * Re-read the authenticated claim and authority under the configured run lock.
     */
    private static function writeBoundary(
        WorkflowRun $run,
        WorkflowTask $task,
        string $scopeId,
        string $requestId,
        int $sequence,
        string $callKind,
        string $protocolVersion,
        int $sequenceSpan = 1,
        ?int $operationSequence = null,
        int $operationSequenceSpan = 1,
        bool $preparing = false,
    ): WorkflowHistoryEvent {
        if (! WorkerProtocolVersion::supportsCancellationScopeMembership($protocolVersion)) {
            throw new LogicException('cancellation_scope_requires_protocol_1_20');
        }
        $invalid = CooperativeCancellationDelivery::validateBoundarySyntax(
            $sequence,
            $callKind,
            $sequenceSpan,
            $operationSequence,
            $operationSequenceSpan
        );
        if (! $run->exists || ! $task->exists || $invalid !== null) {
            throw new LogicException($invalid ?? 'invalid_cancellation_scope_delivery');
        }

        $event = ConfiguredV2Models::query('run_model', WorkflowRun::class)->getModel()->getConnection()
            ->transaction(static function () use (
                $run,
                $task,
                $scopeId,
                $requestId,
                $sequence,
                $callKind,
                $sequenceSpan,
                $operationSequence,
                $operationSequenceSpan,
                $preparing
            ): WorkflowHistoryEvent {
                /** @var WorkflowRun $locked */
                $locked = ConfiguredV2Models::query('run_model', WorkflowRun::class)->lockForUpdate()->findOrFail(
                    $run->id
                );
                /** @var WorkflowTask|null $claim */
                $claim = ConfiguredV2Models::query('task_model', WorkflowTask::class)->lockForUpdate()->find($task->id);
                if ($claim === null || $claim->workflow_run_id !== $locked->id || $claim->namespace !== $locked->namespace
                    || $claim->task_type !== TaskType::Workflow || $claim->status !== TaskStatus::Leased
                    || $claim->lease_owner === null || $claim->lease_owner === '' || $claim->attempt_count < 1
                    || $claim->lease_owner !== $task->lease_owner || $claim->attempt_count !== $task->attempt_count
                    || $claim->lease_expires_at === null || now()
                        ->gte($claim->lease_expires_at)) {
                    throw new LogicException('cancellation_scope_workflow_claim_mismatch');
                }
                $context = CancellationScopeRequests::context($locked, $scopeId);
                if ($context === null || $requestId !== $context->requestId) {
                    throw new LogicException('cancellation_scope_request_mismatch');
                }
                $authority = CancellationScopeRequests::authority($locked, $scopeId);
                if (! $authority['active'] || $authority['deadline_at'] === null) {
                    throw new LogicException('cancellation_scope_authority_expired');
                }
                $existing = $preparing ? self::prepared($locked, $scopeId) : self::recorded($locked, $scopeId);
                if ($existing !== null) {
                    $payload = $existing->payload;
                    if ($payload['sequence'] !== $sequence || $payload['call_kind'] !== $callKind
                        || $payload['sequence_span'] !== $sequenceSpan
                        || $payload['operation_sequence'] !== $operationSequence
                        || $payload['operation_sequence_span'] !== $operationSequenceSpan) {
                        throw new LogicException('cancellation_scope_delivery_mismatch');
                    }
                    return $existing;
                }
                $request = $locked->historyEvents()
                    ->where('event_type', HistoryEventType::CancellationScopeRequested)
                    ->where('payload->scope_id', $scopeId)
                    ->sole();
                $scope = CancellationScopeHistory::forRun($locked)[$scopeId];
                if ($scope['shield_parent'] && $request->payload['parent_scope_id'] !== null) {
                    throw new LogicException('cancellation_scope_parent_shielded');
                }
                $invalid = CooperativeCancellationDelivery::validateCallBoundary(
                    $locked,
                    $request,
                    $sequence,
                    $callKind,
                    $sequenceSpan,
                    $operationSequence,
                    $operationSequenceSpan
                );
                if ($invalid !== null) {
                    throw new LogicException($invalid);
                }
                $operationStart = $operationSequence ?? $sequence;
                $operationSpan = $operationSequence === null ? $sequenceSpan : $operationSequenceSpan;
                if (! CancellationScopeHistory::isRecordedBefore($locked, $scopeId, $operationStart)
                    || ! self::matchesMembership($locked, $scopeId, $operationStart, $operationSpan)) {
                    throw new LogicException('cancellation_scope_delivery_membership_mismatch');
                }
                $preparation = $preparing ? null : self::prepared($locked, $scopeId);
                if (! $preparing && ($preparation === null
                    || ! self::sameBoundary(
                        $preparation->payload,
                        $sequence,
                        $callKind,
                        $sequenceSpan,
                        $operationSequence,
                        $operationSequenceSpan
                    ))) {
                    throw new LogicException($preparation === null
                        ? 'cancellation_scope_delivery_not_prepared' : 'cancellation_scope_delivery_mismatch');
                }
                if ($preparation !== null && now()->gte(
                    CarbonImmutable::parse($preparation->payload['authority_deadline_at'])
                )) {
                    throw new LogicException('cancellation_scope_authority_expired');
                }
                $members = $preparing ? self::activityMembers(
                    $locked,
                    $scopeId
                ) : $preparation->payload['activity_members'];
                $timerMembers = $preparing ? ScopedTimerCancellation::members($locked, $scopeId)
                    : $preparation->payload['timer_members'];
                if ($preparing && $locked->historyEvents->contains(static fn (WorkflowHistoryEvent $event): bool =>
                    in_array(
                        $event->event_type,
                        [HistoryEventType::ActivityCancelled, HistoryEventType::TimerCancelled],
                        true
                    )
                    && ($event->payload['cancellation_scope']['scope_id'] ?? null) === $scopeId)) {
                    throw new LogicException('cancellation_scope_preparation_after_effect');
                }
                if (! $preparing) {
                    ScopedActivityDeliveryPolicy::assertReady($locked, $context, $operationStart, $operationSpan);
                    foreach ($members as $member) {
                        ScopedActivityDeliveryPolicy::assertReady($locked, $context, $member['sequence'], 1);
                    }
                    ScopedTimerCancellation::assertReady($locked, $preparation);
                }
                return WorkflowHistoryEvent::record($locked, $preparing
                    ? HistoryEventType::CancellationScopeDeliveryPrepared : HistoryEventType::CancellationScopeDelivered, [
                        'schema' => $preparing ? self::PREPARATION_SCHEMA : self::SCHEMA,
                        'workflow_run_id' => $locked->id,
                        'scope_id' => $scopeId,
                        'request_id' => $context->requestId,
                        'cancellation' => $context->toArray(),
                        'sequence' => $sequence,
                        'call_kind' => $callKind,
                        'sequence_span' => $sequenceSpan,
                        'operation_sequence' => $operationSequence,
                        'operation_sequence_span' => $operationSequenceSpan,
                        'authority_deadline_at' => $preparation?->payload['authority_deadline_at'] ?? $authority['deadline_at'],
                        ...($preparing ? [
                            'activity_members' => $members,
                            'timer_members' => $timerMembers,
                        ]
                            : [
                                'preparation_history_event_id' => $preparation->id,
                            ]),
                    ], $claim);
            }, 3);
        $run->refresh();
        return $event;
    }

    private static function readBoundary(WorkflowRun $run, string $scopeId, bool $preparing): ?WorkflowHistoryEvent
    {
        $context = CancellationScopeRequests::context($run, $scopeId);
        $events = $run->historyEvents()
            ->where('event_type', $preparing ? HistoryEventType::CancellationScopeDeliveryPrepared
                : HistoryEventType::CancellationScopeDelivered)
            ->where('payload->scope_id', $scopeId)
            ->get();
        if ($events->isEmpty()) {
            return null;
        }
        if ($events->count() !== 1 || $context === null) {
            throw new LogicException('cancellation_scope_delivery_history_invalid');
        }
        $event = $events->sole();
        $payload = $event->payload;
        if (($payload['schema'] ?? null) !== ($preparing ? self::PREPARATION_SCHEMA : self::SCHEMA)
            || ($payload['workflow_run_id'] ?? null) !== $run->id
            || ($payload['scope_id'] ?? null) !== $scopeId || ($payload['request_id'] ?? null) !== $context->requestId
            || ! is_array($payload['cancellation'] ?? null)
            || ! is_int($payload['sequence'] ?? null) || ! is_string($payload['call_kind'] ?? null)
            || ! is_int($payload['sequence_span'] ?? null) || ! array_key_exists('operation_sequence', $payload)
            || ($payload['operation_sequence'] !== null && ! is_int($payload['operation_sequence']))
            || ! is_int($payload['operation_sequence_span'] ?? null)
            || ! is_string($payload['authority_deadline_at'] ?? null)) {
            throw new LogicException('cancellation_scope_delivery_history_invalid');
        }
        try {
            $snapshot = ScopedCancellationContext::fromArray($payload['cancellation']);
            $deadline = CarbonImmutable::parse($payload['authority_deadline_at']);
        } catch (InvalidArgumentException $error) {
            throw new LogicException('cancellation_scope_delivery_history_invalid', previous: $error);
        }
        $request = $run->historyEvents()
            ->where('event_type', HistoryEventType::CancellationScopeRequested)
            ->where('payload->scope_id', $scopeId)
            ->sole();
        $run->loadMissing('historyEvents');
        $operationStart = $payload['operation_sequence'] ?? $payload['sequence'];
        $operationSpan = $payload['operation_sequence'] === null ? $payload['sequence_span'] : $payload['operation_sequence_span'];
        $recordedKind = CooperativeCancellationDelivery::callKindAt($run, $payload['sequence']);
        if ($snapshot->toArray() !== $context->toArray() || $deadline->toISOString() !== $payload['authority_deadline_at']
            || $deadline->greaterThan($context->deadline()) || $deadline->lessThan($context->requestedAt())
            || $event->sequence <= $request->sequence
            || ! CancellationScopeHistory::isRecordedBefore($run, $scopeId, $operationStart)
            || ! self::matchesMembership($run, $scopeId, $operationStart, $operationSpan)
            || ($recordedKind !== null && ! in_array($payload['call_kind'], ['parallel', 'selection_handle'], true)
                && $payload['call_kind'] !== $recordedKind)
            || CooperativeCancellationDelivery::validateBoundarySyntax(
                $payload['sequence'],
                $payload['call_kind'],
                $payload['sequence_span'],
                $payload['operation_sequence'],
                $payload['operation_sequence_span']
            ) !== null) {
            throw new LogicException('cancellation_scope_delivery_history_invalid');
        }
        try {
            if ($preparing) {
                $history = $run->historyEvents;
                $run->setRelation('historyEvents', $history->filter(
                    static fn (WorkflowHistoryEvent $row): bool => $row->sequence < $event->sequence
                ));
                try {
                    if (self::normalizeMembers($payload['activity_members'] ?? null) !== self::activityMembers(
                        $run,
                        $scopeId
                    )
                        || ScopedTimerCancellation::normalizeMembers($payload['timer_members'] ?? null)
                            !== ScopedTimerCancellation::members($run, $scopeId)
                        || CooperativeCancellationDelivery::validateCallBoundary(
                            $run,
                            $request,
                            $payload['sequence'],
                            $payload['call_kind'],
                            $payload['sequence_span'],
                            $payload['operation_sequence'],
                            $payload['operation_sequence_span']
                        ) !== null
                        || $run->historyEvents->contains(static fn (WorkflowHistoryEvent $row): bool =>
                            in_array(
                                $row->event_type,
                                [HistoryEventType::ActivityCancelled, HistoryEventType::TimerCancelled],
                                true
                            )
                            && ($row->payload['cancellation_scope']['scope_id'] ?? null) === $scopeId)) {
                        throw new LogicException('cancellation_scope_preparation_history_invalid');
                    }
                } finally {
                    $run->setRelation('historyEvents', $history);
                }
            } else {
                $preparation = self::prepared($run, $scopeId);
                if ($preparation === null || $preparation->sequence >= $event->sequence
                    || ($payload['preparation_history_event_id'] ?? null) !== $preparation->id
                    || $payload['authority_deadline_at'] !== $preparation->payload['authority_deadline_at']
                    || ! self::sameBoundary(
                        $preparation->payload,
                        $payload['sequence'],
                        $payload['call_kind'],
                        $payload['sequence_span'],
                        $payload['operation_sequence'],
                        $payload['operation_sequence_span']
                    )) {
                    throw new LogicException('cancellation_scope_preparation_history_invalid');
                }
                ScopedActivityDeliveryPolicy::assertReady(
                    $run,
                    $context,
                    $operationStart,
                    $operationSpan,
                    $event->sequence
                );
                foreach ($preparation->payload['activity_members'] as $member) {
                    ScopedActivityDeliveryPolicy::assertReady($run, $context, $member['sequence'], 1, $event->sequence);
                }
                ScopedTimerCancellation::assertReady($run, $preparation, $event->sequence);
            }
        } catch (LogicException $error) {
            throw new LogicException('cancellation_scope_delivery_history_invalid', previous: $error);
        }
        return $event;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function sameBoundary(
        array $payload,
        int $sequence,
        string $kind,
        int $span,
        ?int $operationSequence,
        int $operationSpan
    ): bool {
        return $payload['sequence'] === $sequence && $payload['call_kind'] === $kind
            && $payload['sequence_span'] === $span && $payload['operation_sequence'] === $operationSequence
            && $payload['operation_sequence_span'] === $operationSpan;
    }

    /**
     * @return list<array{sequence: int, activity_execution_id: string, descriptor_hash: string}>
     */
    private static function activityMembers(WorkflowRun $run, string $scopeId): array
    {
        $run->loadMissing('historyEvents');
        $members = [];
        $ids = [];
        $sequences = [];
        foreach ($run->historyEvents as $event) {
            if ($event->event_type !== HistoryEventType::ActivityScheduled) {
                continue;
            }
            $payload = $event->payload;
            $activity = $payload['activity'] ?? [];
            $nested = $activity['cancellation_scope_id'] ?? CancellationScopeHistory::ROOT_SCOPE_ID;
            $flat = $payload['cancellation_scope_id'] ?? $nested;
            if ($flat !== $scopeId && $nested !== $scopeId) {
                continue;
            }
            if ($flat !== $scopeId || $nested !== $scopeId) {
                throw new LogicException('cancellation_scope_delivery_membership_mismatch');
            }
            $id = $payload['activity_execution_id'] ?? null;
            $sequence = $payload['sequence'] ?? null;
            if (! is_string($id) || $id === '' || ($activity['id'] ?? null) !== $id
                || ! is_int($sequence) || $sequence < 1 || isset($ids[$id]) || isset($sequences[$sequence])) {
                throw new LogicException('cancellation_scope_activity_history_invalid');
            }
            $ids[$id] = true;
            $sequences[$sequence] = true;
            // Hash the cancellation-relevant scalar facts, without copying application payload bytes.
            $members[] = [
                'sequence' => $sequence,
                'activity_execution_id' => $id,
                'descriptor_hash' => hash('sha256', json_encode([
                    $scopeId, $event->id, $activity['cancellation_policy'] ?? 'try_cancel',
                    $payload['local_activity'] ?? false, $payload['execution_mode'] ?? null,
                    $activity['schedule_to_close_deadline_at'] ?? null,
                ], JSON_THROW_ON_ERROR)),
            ];
        }
        return $members;
    }

    /**
     * @return list<array{sequence: int, activity_execution_id: string, descriptor_hash: string}>
     */
    private static function normalizeMembers(mixed $members): array
    {
        if (! is_array($members) || ! array_is_list($members)) {
            throw new LogicException('cancellation_scope_preparation_history_invalid');
        }
        $normalized = [];
        foreach ($members as $member) {
            if (! is_array($member) || count($member) !== 3 || ! is_int($member['sequence'] ?? null)
                || ! is_string($member['activity_execution_id'] ?? null)
                || ! is_string($member['descriptor_hash'] ?? null)) {
                throw new LogicException('cancellation_scope_preparation_history_invalid');
            }
            $normalized[] = [
                'sequence' => $member['sequence'],
                'activity_execution_id' => $member['activity_execution_id'],
                'descriptor_hash' => $member['descriptor_hash'],
            ];
        }
        return $normalized;
    }

    private static function matchesMembership(WorkflowRun $run, string $scopeId, int $start, int $span): bool
    {
        foreach ($run->historyEvents as $event) {
            $payload = $event->payload;
            $sequence = $payload['sequence'] ?? null;
            if (! is_int($sequence) || $sequence < $start || $sequence - $start >= $span
                || ! in_array(
                    $event->event_type,
                    [HistoryEventType::ActivityScheduled, HistoryEventType::TimerScheduled,
                        HistoryEventType::ConditionWaitOpened, HistoryEventType::SignalWaitOpened,
                        HistoryEventType::ChildWorkflowScheduled],
                    true
                )) {
                continue;
            }
            $nested = match ($event->event_type) {
                HistoryEventType::ActivityScheduled => 'activity',
                HistoryEventType::TimerScheduled => 'timer',
                HistoryEventType::ChildWorkflowScheduled => 'child_workflow',
                default => null,
            };
            $descriptor = $nested !== null && is_array($payload[$nested] ?? null) ? $payload[$nested] : [];
            $hasFlat = array_key_exists('cancellation_scope_id', $payload);
            $hasNested = array_key_exists('cancellation_scope_id', $descriptor);
            $membership = $hasFlat ? $payload['cancellation_scope_id']
                : ($hasNested ? $descriptor['cancellation_scope_id'] : CancellationScopeHistory::ROOT_SCOPE_ID);
            if ($membership !== $scopeId || ($hasNested && $descriptor['cancellation_scope_id'] !== $scopeId)) {
                return false;
            }
        }
        return true;
    }
}
