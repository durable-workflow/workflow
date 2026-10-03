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
 * @internal Authenticate preparation and dispatch the original scoped Activities.
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
            // Its run/task locks are released before an Activity actor starts.
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
            );
            $response = [...$response, ...self::frame($run, $event->payload, $event->id)];
            if (! $preparing) {
                $run->unsetRelation('historyEvents');
                $unavailable = self::unavailableOperations($run, $scopeId);
                if ($unavailable !== []) {
                    return [
                        ...$response,
                        'reason' => 'cancellation_scope_operation_delivery_unavailable',
                        'unavailable' => $unavailable,
                    ];
                }
                $response['activity_cancellations'] = [];
                foreach ($event->payload['activity_members'] as $member) {
                    $receipt = ScopedActivityCancellation::fence(
                        $run,
                        $claim,
                        $member['activity_execution_id'],
                        $scopeId,
                        $requestId,
                        $protocolVersion,
                    );
                    $response['activity_cancellations'][] = [
                        'sequence' => $member['sequence'],
                        'activity_execution_id' => $member['activity_execution_id'],
                        ...$receipt,
                    ];
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
        ];
    }

    /**
     * @return list<string>
     */
    private static function unavailableOperations(WorkflowRun $run, string $scopeId): array
    {
        $scopes = CancellationScopeHistory::forRun($run);
        $unavailable = [];
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
            if (! is_string($address) || ! isset($scopes[$address])
                || (array_key_exists('cancellation_scope_id', $descriptor)
                    && $descriptor['cancellation_scope_id'] !== $address)) {
                throw new LogicException('cancellation_scope_delivery_history_invalid');
            }
            $memberScope = $address;
            while ($address !== $scopeId && isset($scopes[$address])
                && ! $scopes[$address]['shield_parent'] && $scopes[$address]['parent_scope_id'] !== null) {
                $address = $scopes[$address]['parent_scope_id'];
            }
            if ($address !== $scopeId) {
                continue;
            }
            $missing = $memberScope !== $scopeId ? 'scoped_descendant_delivery'
                : match ($event->event_type) {
                    HistoryEventType::TimerScheduled => 'scoped_timer_delivery',
                    HistoryEventType::ChildWorkflowScheduled => 'scoped_child_delivery',
                    HistoryEventType::ConditionWaitOpened, HistoryEventType::SignalWaitOpened => 'scoped_wait_delivery',
                    default => null,
                };
            if ($missing !== null) {
                $unavailable[$missing] = true;
            }
        }
        return array_keys($unavailable);
    }
}
