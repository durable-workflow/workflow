<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\ScopedCancellationContext;

/**
 * @internal Authenticate preparation and dispatch the original scoped operations.
 * Each actor owns its locks; no outer transaction spans preparation and dispatch.
 */
final class PortableCancellationScopeDelivery
{
    /**
     * @return array<string, mixed>
     */
    public static function mutate(
        bool $preparing,
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        string $scopeId,
        string $requestId,
        int $sequence,
        string $callKind,
        int $sequenceSpan,
        ?int $operationSequence,
        int $operationSequenceSpan,
        string $protocolVersion,
        bool $renewWorkflowLease = true,
    ): array {
        $response = [
            'prepared' => false,
            'delivered' => false,
            'claim_released' => false,
            'task_id' => $taskId,
            'created_task_ids' => [],
            'reason' => null,
        ];
        $refused = static fn (string $reason): array => [
            ...$response,
            'reason' => $reason,
        ];
        if (! WorkerProtocolVersion::supportsCancellationScopeMembership($protocolVersion)) {
            return $refused('cancellation_scope_requires_protocol_1_20');
        }
        if ($leaseOwner === '' || $workflowTaskAttempt < 1 || $scopeId === '' || $requestId === '') {
            return $refused('invalid_cancellation_scope_delivery');
        }
        /** @var WorkflowTask|null $claim */
        $claim = ConfiguredV2Models::query('task_model', WorkflowTask::class)->find($taskId);
        if ($claim === null) {
            return $refused('task_not_found');
        }
        /** @var WorkflowRun|null $run */
        $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)->find($claim->workflow_run_id);
        if ($run === null) {
            return $refused('run_not_found');
        }
        if ($claim->namespace !== $run->namespace || $claim->task_type !== TaskType::Workflow
            || $claim->status !== TaskStatus::Leased || $claim->lease_owner !== $leaseOwner
            || $claim->attempt_count !== $workflowTaskAttempt || $claim->lease_expires_at === null
            || now()
                ->gte($claim->lease_expires_at)) {
            return $refused('cancellation_scope_workflow_claim_mismatch');
        }
        if ($run->getConnection()->transactionLevel() !== 0) {
            return $refused('cancellation_scope_preparation_requires_own_transaction');
        }
        try {
            $preparation = $preparing ? null : CancellationScopeDelivery::prepared($run, $scopeId);
            if (! $preparing && $preparation === null) {
                return $refused('cancellation_scope_delivery_not_prepared');
            }
            // Replay validates the exact original boundary before any effects.
            // Its run/task locks are released before an operation actor starts.
            $event = CancellationScopeDelivery::prepare(
                $run,
                $claim,
                $scopeId,
                $requestId,
                $sequence,
                $callKind,
                $protocolVersion,
                $sequenceSpan,
                $operationSequence,
                $operationSequenceSpan,
                $renewWorkflowLease,
            );
            $response = [...$response, ...self::frame($run, $event->payload, $event->id)];
            if (! $preparing) {
                $run->unsetRelation('historyEvents');
                self::assertOperationAddresses($run);
                $references = [ScopedCancellationPreparation::fromEvent($run, $event)];
                foreach ($event->payload['descendant_members'] as $descendant) {
                    $references[] = ScopedCancellationPreparation::forScope($run, $descendant['scope_id'], $event->id)
                        ?? throw new LogicException('cancellation_scope_descendant_not_prepared');
                }
                // Check the whole subtree before dispatching even the parent's
                // first actor. An older prepared boundary retains its effects.
                foreach ($references as $reference) {
                    if (ScopedCancellationReconciliation::pending($run, $reference)) {
                        return [
                            ...$response,
                            'reason' => 'cancellation_scope_descendant_delivery_pending',
                        ];
                    }
                }
                $response['activity_cancellations'] = [];
                $response['timer_cancellations'] = [];
                $response['wait_cancellations'] = [];
                $response['child_cancellations'] = [];
                foreach ($references as $reference) {
                    $completed = ScopedCancellationReconciliation::completed($run, $reference);
                    if ($completed !== null) {
                        foreach ($completed->receipts($run) as $field => $receipts) {
                            array_push($response[$field], ...$receipts);
                        }
                        continue;
                    }
                    foreach ($reference->waitMembers as $member) {
                        $receipt = ScopedWaitCancellation::fence(
                            $run,
                            $claim,
                            $member['wait_id'],
                            $reference->scopeId,
                            $reference->requestId,
                            $protocolVersion,
                            $reference->historyEventId,
                        );
                        unset($receipt['timer_cancellation']);
                        $response['wait_cancellations'][] = $receipt;
                    }
                    foreach ($reference->timerMembers as $member) {
                        $response['timer_cancellations'][] = ScopedTimerCancellation::fence(
                            $run,
                            $claim,
                            $member['timer_id'],
                            $reference->scopeId,
                            $reference->requestId,
                            $protocolVersion,
                            $reference->historyEventId,
                        );
                    }
                    foreach ($reference->activityMembers as $member) {
                        $receipt = ScopedActivityCancellation::fence(
                            $run,
                            $claim,
                            $member['activity_execution_id'],
                            $reference->scopeId,
                            $reference->requestId,
                            $protocolVersion,
                            $reference->historyEventId,
                        );
                        // Database JSON key order must not change a retried receipt.
                        if (isset($receipt['cancellation_scope'])) {
                            $snapshot = $receipt['cancellation_scope'];
                            $receipt['cancellation_scope'] = [
                                'schema' => $snapshot['schema'],
                                'workflow_run_id' => $snapshot['workflow_run_id'],
                                'scope_id' => $snapshot['scope_id'],
                                'request_id' => $snapshot['request_id'],
                                'request_history_event_id' => $snapshot['request_history_event_id'],
                                'cancellation' => ScopedCancellationContext::fromArray(
                                    $snapshot['cancellation']
                                )->toArray(),
                                'authority_deadline_at' => $snapshot['authority_deadline_at'],
                            ];
                        }
                        $response['activity_cancellations'][] = [
                            'sequence' => $member['sequence'],
                            'activity_execution_id' => $member['activity_execution_id'],
                            ...$receipt,
                        ];
                    }
                    foreach ($reference->childMembers as $member) {
                        $response['child_cancellations'][] = ScopedChildCancellationDelivery::request(
                            $run,
                            $claim,
                            $member['child_call_id'],
                            $reference->scopeId,
                            $reference->requestId,
                            $protocolVersion,
                            $reference->historyEventId,
                        );
                    }
                }
                // Recording remains a barrier over every original member's policy.
                $event = CancellationScopeDelivery::record(
                    $run,
                    $claim,
                    $scopeId,
                    $requestId,
                    $sequence,
                    $callKind,
                    $protocolVersion,
                    $sequenceSpan,
                    $operationSequence,
                    $operationSequenceSpan,
                );
            }
            return [
                ...$response,
                ...self::frame(
                    $run,
                    $event->payload,
                    $preparing ? $event->id : $event->payload['preparation_history_event_id']
                ),
                'delivered' => ! $preparing,
                'history_event_id' => $event->id,
                'reason' => null,
            ];
        } catch (LogicException $error) {
            $reason = $error->getMessage();
            if (! str_starts_with($reason, 'cancellation_scope_')
                && ! str_starts_with($reason, 'cancellation_delivery_')
                && ! in_array(
                    $reason,
                    ['invalid_cancellation_scope_delivery', 'invalid_cancellation_delivery',
                        'activity_cancellation_owned_by_another_request'],
                    true
                )) {
                throw $error;
            }
            return [
                ...$response,
                'reason' => $reason,
            ];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function frame(WorkflowRun $run, array $payload, string $preparationId): array
    {
        return [
            'prepared' => true,
            'workflow_run_id' => $run->id,
            'preparation_history_event_id' => $preparationId,
            'scope_id' => $payload['scope_id'],
            'request_id' => $payload['request_id'],
            'cancellation' => ScopedCancellationContext::fromArray($payload['cancellation'])->toArray(),
            'sequence' => $payload['sequence'],
            'call_kind' => $payload['call_kind'],
            'sequence_span' => $payload['sequence_span'],
            'operation_sequence' => $payload['operation_sequence'],
            'operation_sequence_span' => $payload['operation_sequence_span'],
            'authority_deadline_at' => $payload['authority_deadline_at'],
            ...(array_key_exists('activity_members', $payload) ? [
                'activity_members' => array_map(static fn (array $member): array => [
                    'sequence' => $member['sequence'],
                    'activity_execution_id' => $member['activity_execution_id'],
                    'descriptor_hash' => $member['descriptor_hash'],
                ], $payload['activity_members']),
            ] : []),
            ...(array_key_exists('timer_members', $payload) ? [
                'timer_members' => ScopedTimerCancellation::normalizeMembers($payload['timer_members']),
            ] : []),
            ...(array_key_exists('wait_members', $payload) ? [
                'wait_members' => ScopedWaitCancellation::normalizeMembers($payload['wait_members']),
            ] : []),
            ...(array_key_exists('child_members', $payload) ? [
                'child_members' => ScopedChildCancellation::normalizeMembers($payload['child_members']),
            ] : []),
        ];
    }

    private static function assertOperationAddresses(WorkflowRun $run): void
    {
        $scopes = CancellationScopeHistory::forRun($run);
        foreach ($run->historyEvents as $event) {
            $descriptor = match ($event->event_type) {
                HistoryEventType::ActivityScheduled => $event->payload['activity'] ?? [],
                HistoryEventType::TimerScheduled => $event->payload['timer'] ?? [],
                HistoryEventType::ChildWorkflowScheduled => $event->payload['child_workflow'] ?? [],
                HistoryEventType::ConditionWaitOpened, HistoryEventType::SignalWaitOpened => [],
                default => null,
            };
            if ($descriptor === null) {
                continue;
            }
            if (! is_array($descriptor)) {
                throw new LogicException('cancellation_scope_delivery_history_invalid');
            }
            $address = $event->payload['cancellation_scope_id'] ?? $descriptor['cancellation_scope_id']
                ?? CancellationScopeHistory::ROOT_SCOPE_ID;
            if (! is_string($address)
                || ($address !== CancellationScopeHistory::ROOT_SCOPE_ID && ! isset($scopes[$address]))
                || (array_key_exists('cancellation_scope_id', $descriptor)
                    && $descriptor['cancellation_scope_id'] !== $address)) {
                throw new LogicException('cancellation_scope_delivery_history_invalid');
            }
        }
    }
}
