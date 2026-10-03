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

    /**
     * Preserve the first acknowledged boundary across response loss and replacement.
     * Re-read the authenticated claim and authority under the configured run lock.
     */
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
                $operationSequenceSpan
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
                $existing = self::recorded($locked, $scopeId);
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
                return WorkflowHistoryEvent::record($locked, HistoryEventType::CancellationScopeDelivered, [
                    'schema' => self::SCHEMA,
                    'workflow_run_id' => $locked->id,
                    'scope_id' => $scopeId,
                    'request_id' => $context->requestId,
                    'cancellation' => $context->toArray(),
                    'sequence' => $sequence,
                    'call_kind' => $callKind,
                    'sequence_span' => $sequenceSpan,
                    'operation_sequence' => $operationSequence,
                    'operation_sequence_span' => $operationSequenceSpan,
                    'authority_deadline_at' => $authority['deadline_at'],
                ], $claim);
            }, 3);
        $run->refresh();
        return $event;
    }

    /**
     * Cold replay reads the original boundary and clock, even after authority ends.
     * The deadline snapshot is an observation, never permission to admit effects.
     */
    public static function recorded(WorkflowRun $run, string $scopeId): ?WorkflowHistoryEvent
    {
        $context = CancellationScopeRequests::context($run, $scopeId);
        $events = $run->historyEvents()
            ->where('event_type', HistoryEventType::CancellationScopeDelivered)
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
        if (($payload['schema'] ?? null) !== self::SCHEMA || ($payload['workflow_run_id'] ?? null) !== $run->id
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
        return $event;
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
