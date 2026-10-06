<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonImmutable;
use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\ScopedCancellationContext;

/** @internal Wait cancellation and its timeout fence share one transaction. */
final class ScopedWaitCancellation
{
    public const SCHEMA = 'durable-workflow.scoped-wait-cancellation/v1';

    /**
     * @return list<array{kind: string, sequence: int, wait_id: string, timer_id: string|null, descriptor_hash: string}>
     */
    public static function members(WorkflowRun $run, string $scopeId): array
    {
        $run->loadMissing('historyEvents');
        $members = [];
        $ids = [];
        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            $kind = match ($event->event_type) {
                HistoryEventType::SignalWaitOpened => 'signal',
                HistoryEventType::ConditionWaitOpened => 'condition',
                default => null,
            };
            if ($kind === null || ($event->payload['cancellation_scope_id'] ?? 'root') !== $scopeId) {
                continue;
            }
            $payload = $event->payload;
            $id = $payload[$kind . '_wait_id'] ?? null;
            $sequence = $payload['sequence'] ?? null;
            if (! is_string($id) || $id === '' || ! is_int($sequence) || $sequence < 1 || isset($ids[$id])) {
                throw new LogicException('cancellation_scope_wait_history_invalid');
            }
            $timers = $run->historyEvents->filter(static fn (WorkflowHistoryEvent $row): bool =>
                $row->event_type === HistoryEventType::TimerScheduled
                && ($row->payload[$kind . '_wait_id'] ?? null) === $id);
            if ($timers->count() > 1) {
                throw new LogicException('cancellation_scope_wait_history_invalid');
            }
            $timer = $timers->first();
            if ($timer !== null && (($timer->payload['timer_kind'] ?? null) !== $kind . '_timeout'
                || ($timer->payload['sequence'] ?? null) !== $sequence || $timer->sequence <= $event->sequence
                || ($timer->payload['cancellation_scope_id'] ?? 'root') !== $scopeId
                || ! is_string($timer->payload['timer_id'] ?? null) || $timer->payload['timer_id'] === '')) {
                throw new LogicException('cancellation_scope_wait_history_invalid');
            }
            $ids[$id] = true;
            $members[] = [
                'kind' => $kind,
                'sequence' => $sequence,
                'wait_id' => $id,
                'timer_id' => $timer?->payload['timer_id'],
                'descriptor_hash' => hash('sha256', json_encode([
                    $scopeId, $event->id, $kind, $sequence, $id, $timer?->id, $timer?->payload['timer_id'],
                    $payload['timeout_seconds'] ?? null, $payload['signal_name'] ?? null,
                    $payload['condition_wait_occurrence_id'] ?? null, $payload['condition_key'] ?? null,
                    $payload['condition_definition_fingerprint'] ?? null,
                    ParallelChildGroup::metadataPathFromPayload($payload),
                ], JSON_THROW_ON_ERROR)),
            ];
        }
        return $members;
    }

    /**
     * @return list<array{kind: string, sequence: int, wait_id: string, timer_id: string|null, descriptor_hash: string}>
     */
    public static function normalizeMembers(mixed $members): array
    {
        if (! is_array($members) || ! array_is_list($members)) {
            throw new LogicException('cancellation_scope_preparation_history_invalid');
        }
        $normalized = [];
        foreach ($members as $member) {
            if (! is_array($member) || count($member) !== 5 || ! in_array(
                $member['kind'] ?? null,
                ['signal', 'condition'],
                true
            )
                || ! is_int($member['sequence'] ?? null) || $member['sequence'] < 1
                || ! is_string($member['wait_id'] ?? null) || $member['wait_id'] === ''
                || ! array_key_exists('timer_id', $member)
                || ($member['timer_id'] !== null && (! is_string($member['timer_id']) || $member['timer_id'] === ''))
                || ! is_string($member['descriptor_hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $member['descriptor_hash']) !== 1) {
                throw new LogicException('cancellation_scope_preparation_history_invalid');
            }
            $normalized[] = [
                'kind' => $member['kind'],
                'sequence' => $member['sequence'],
                'wait_id' => $member['wait_id'],
                'timer_id' => $member['timer_id'],
                'descriptor_hash' => $member['descriptor_hash'],
            ];
        }
        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function forTimer(
        WorkflowHistoryEvent|ScopedCancellationPreparation $preparation,
        string $timerId
    ): ?array {
        $members = $preparation instanceof ScopedCancellationPreparation
            ? $preparation->waitMembers : self::normalizeMembers($preparation->payload['wait_members']);
        return collect($members)->firstWhere('timer_id', $timerId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fence(
        WorkflowRun $run,
        WorkflowTask $workflowTask,
        string $waitId,
        string $scopeId,
        string $requestId,
        string $protocolVersion,
        ?string $preparationHistoryEventId = null,
    ): array {
        if (! WorkerProtocolVersion::supportsCancellationScopeMembership($protocolVersion)) {
            throw new LogicException('cancellation_scope_requires_protocol_1_20');
        }
        if ($run->getConnection()->transactionLevel() !== 0) {
            throw new LogicException('cancellation_scope_wait_requires_own_transaction');
        }
        $original = ScopedCancellationPreparation::forScope($run->fresh(), $scopeId, $preparationHistoryEventId);
        $member = $original === null ? null : collect($original->waitMembers)
            ->firstWhere('wait_id', $waitId);
        if (! is_array($member)) {
            throw new LogicException('cancellation_scope_wait_not_prepared');
        }
        $timerId = $member['timer_id'];
        $timerTasks = $timerId === null ? collect() : $run->tasks()
            ->where('task_type', TaskType::Timer)
            ->where('payload->timer_id', $timerId)
            ->get();
        if ($timerTasks->count() > 1) {
            throw new LogicException('cancellation_scope_timer_task_mismatch');
        }
        $timerTaskId = $timerTasks->first()?->id;
        return $run->getConnection()
            ->transaction(static function () use (
                $run,
                $workflowTask,
                $waitId,
                $scopeId,
                $requestId,
                $timerId,
                $timerTaskId,
                $preparationHistoryEventId,
            ): array {
                // Match RunTimerTask's task/run lock prefix before the hosting claim.
                $timerTask = $timerTaskId === null ? null
                    : ConfiguredV2Models::query('task_model', WorkflowTask::class)->lockForUpdate()->find($timerTaskId);
                /** @var WorkflowRun $locked */
                $locked = ConfiguredV2Models::query('run_model', WorkflowRun::class)->lockForUpdate()->findOrFail(
                    $run->id
                );
                /** @var WorkflowTask|null $claim */
                $claim = ConfiguredV2Models::query('task_model', WorkflowTask::class)->lockForUpdate()->find(
                    $workflowTask->id
                );
                if ($claim === null || $claim->workflow_run_id !== $locked->id || $claim->namespace !== $locked->namespace
                    || $claim->task_type !== TaskType::Workflow || $claim->status !== TaskStatus::Leased
                    || $claim->lease_owner === null || $claim->lease_owner === '' || $claim->attempt_count < 1
                    || $claim->lease_owner !== $workflowTask->lease_owner || $claim->attempt_count !== $workflowTask->attempt_count
                    || $claim->lease_expires_at === null || now()
                        ->gte($claim->lease_expires_at)) {
                    throw new LogicException('cancellation_scope_workflow_claim_mismatch');
                }
                $context = CancellationScopeRequests::context($locked, $scopeId);
                $preparation = ScopedCancellationPreparation::forScope($locked, $scopeId, $preparationHistoryEventId);
                $authority = CancellationScopeRequests::authority($locked, $scopeId);
                if ($context === null || $context->requestId !== $requestId) {
                    throw new LogicException('cancellation_scope_request_mismatch');
                }
                $member = $preparation === null ? null : collect($preparation->waitMembers)
                    ->firstWhere('wait_id', $waitId);
                if (! is_array($member) || $member['timer_id'] !== $timerId) {
                    throw new LogicException('cancellation_scope_wait_not_prepared');
                }
                ScopedCancellationReconciliation::assertDispatchable($locked, $preparation);
                $timer = $timerId === null ? null
                    : ConfiguredV2Models::query('timer_model', WorkflowTimer::class)->lockForUpdate()->find($timerId);
                if ($timerId !== null) {
                    $timer ??= TimerRecovery::restore($locked, $timerId);
                    if ($timer === null || $timer->workflow_run_id !== $locked->id || $timer->sequence !== $member['sequence']) {
                        throw new LogicException('cancellation_scope_timer_projection_mismatch');
                    }
                }
                // Waiting on the last row never renews either original authority.
                if (now()->gte($claim->lease_expires_at)) {
                    throw new LogicException('cancellation_scope_workflow_claim_mismatch');
                }
                if (! $authority['active'] || $authority['deadline_at'] === null
                    || now()
                        ->gte(CarbonImmutable::parse($authority['deadline_at']))
                    || now()
                        ->gte(CarbonImmutable::parse($preparation->authorityDeadlineAt))) {
                    throw new LogicException('cancellation_scope_authority_expired');
                }
                $timerTerminal = $timerId === null ? null : ScopedTimerCancellation::terminal(
                    $locked,
                    $timerId,
                    $member['sequence']
                );
                if ($timerId !== null && ($timerTask === null
                    ? $timerTerminal?->event_type !== HistoryEventType::TimerFired
                    : ($timerTask->workflow_run_id !== $locked->id || $timerTask->namespace !== $locked->namespace
                        || $timerTask->task_type !== TaskType::Timer || ($timerTask->payload['timer_id'] ?? null) !== $timerId
                        || $locked->tasks()
                            ->where('task_type', TaskType::Timer)->where(
                                'payload->timer_id',
                                $timerId
                            )->count() !== 1))) {
                    throw new LogicException('cancellation_scope_timer_task_mismatch');
                }
                $terminal = self::terminal($locked, $member);
                if ($terminal !== null && self::isCancelled($terminal)) {
                    self::assertReceipt($locked, $preparation, $terminal, $member);
                } elseif ($terminal === null) {
                    $opened = $locked->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
                        ($event->payload[$member['kind'] . '_wait_id'] ?? null) === $waitId
                        && $event->event_type === ($member['kind'] === 'signal'
                            ? HistoryEventType::SignalWaitOpened : HistoryEventType::ConditionWaitOpened));
                    $request = $locked->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
                        $event->event_type === HistoryEventType::CancellationScopeRequested
                        && ($event->payload['scope_id'] ?? null) === $scopeId);
                    $cancelledEventType = $member['kind'] === 'signal'
                        ? HistoryEventType::SignalWaitCancelled : HistoryEventType::ConditionWaitCancelled;
                    $terminal = WorkflowHistoryEvent::record($locked, $cancelledEventType, array_filter(
                        [
                            ...array_intersect_key($opened->payload, array_flip([
                                'signal_name', 'signal_wait_id', 'condition_wait_id', 'condition_wait_occurrence_id',
                                'condition_key', 'condition_definition_fingerprint', 'sequence', 'timeout_seconds',
                            ])),
                            'timer_id' => $timerId,
                            'cancelled_at' => now()
                                ->toISOString(),
                            'cancellation_scope' => [
                                'schema' => self::SCHEMA,
                                'workflow_run_id' => $locked->id,
                                'scope_id' => $scopeId,
                                'request_id' => $requestId,
                                'request_history_event_id' => $request->id,
                                'preparation_history_event_id' => $preparation->historyEventId,
                                'cancellation' => $context->toArray(),
                                'authority_deadline_at' => $preparation->authorityDeadlineAt,
                            ],
                            ...ParallelChildGroup::payloadForPath(
                                ParallelChildGroup::metadataPathFromPayload($opened->payload)
                            ),
                        ],
                        static fn (mixed $value): bool => $value !== null
                    ), $claim);
                    $locked->historyEvents->push($terminal);
                }
                $timerReceipt = null;
                if ($timer !== null) {
                    if ($timerTerminal === null) {
                        if ($timer->status !== TimerStatus::Pending
                            || ! in_array($timerTask->status, [TaskStatus::Ready, TaskStatus::Leased], true)) {
                            throw new LogicException('cancellation_scope_timer_terminal_history_not_recorded');
                        }
                        $request = $locked->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
                            $event->event_type === HistoryEventType::CancellationScopeRequested
                            && ($event->payload['scope_id'] ?? null) === $scopeId);
                        $timerTerminal = TimerCancellation::record($locked, $timer, $claim, cancellationScope: [
                            'schema' => ScopedTimerCancellation::SCHEMA,
                            'workflow_run_id' => $locked->id,
                            'scope_id' => $scopeId,
                            'request_id' => $requestId,
                            'request_history_event_id' => $request->id,
                            'preparation_history_event_id' => $preparation->historyEventId,
                            'cancellation' => $context->toArray(),
                            'authority_deadline_at' => $preparation->authorityDeadlineAt,
                        ]);
                    }
                    $cancelled = $timerTerminal->event_type === HistoryEventType::TimerCancelled;
                    if ($cancelled) {
                        $timer->forceFill([
                            'status' => TimerStatus::Cancelled,
                            'fired_at' => null,
                        ])->save();
                        $timerTask->forceFill([
                            'status' => TaskStatus::Cancelled,
                            'lease_owner' => null,
                            'lease_expires_at' => null,
                        ])->save();
                    }
                    $timerReceipt = [
                        'fenced' => $cancelled,
                        'history_event_id' => $cancelled ? $timerTerminal->id : null,
                        'timer_id' => $timerId,
                        'sequence' => $member['sequence'],
                    ];
                }
                return [
                    'kind' => $member['kind'],
                    'wait_id' => $waitId,
                    'sequence' => $member['sequence'],
                    'cancelled' => self::isCancelled($terminal),
                    'history_event_id' => $terminal->id,
                    'timer_cancellation' => $timerReceipt,
                ];
            }, 5);
    }

    public static function assertReady(
        WorkflowRun $run,
        WorkflowHistoryEvent|ScopedCancellationPreparation $preparation,
        ?int $beforeHistorySequence = null
    ): void {
        $preparation = $preparation instanceof WorkflowHistoryEvent
            ? ScopedCancellationPreparation::fromEvent($run, $preparation) : $preparation;
        $run->loadMissing('historyEvents');
        $history = $run->historyEvents;
        if ($beforeHistorySequence !== null) {
            $run->setRelation('historyEvents', $history->filter(static fn (WorkflowHistoryEvent $event): bool =>
                $event->sequence < $beforeHistorySequence));
        }
        try {
            foreach ($preparation->waitMembers as $member) {
                $terminal = self::terminal($run, $member);
                if ($terminal === null) {
                    throw new LogicException('cancellation_scope_wait_fence_not_established');
                }
                if (self::isCancelled($terminal)) {
                    self::assertReceipt($run, $preparation, $terminal, $member);
                }
            }
        } finally {
            $run->setRelation('historyEvents', $history);
        }
    }

    public static function hasTimerWaitProof(
        WorkflowRun $run,
        WorkflowHistoryEvent|ScopedCancellationPreparation $preparation,
        string $timerId,
    ): bool {
        $preparation = $preparation instanceof WorkflowHistoryEvent
            ? ScopedCancellationPreparation::fromEvent($run, $preparation) : $preparation;
        $member = self::forTimer($preparation, $timerId);
        if ($member === null) {
            return false;
        }
        $terminal = self::terminal($run, $member);
        if ($terminal === null) {
            return false;
        }
        if (self::isCancelled($terminal)) {
            self::assertReceipt($run, $preparation, $terminal, $member);
        }
        return true;
    }

    public static function cancelled(WorkflowRun $run, string $kind, string $waitId): bool
    {
        $run->loadMissing('historyEvents');
        return $run->historyEvents->contains(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === ($kind === 'signal' ? HistoryEventType::SignalWaitCancelled : HistoryEventType::ConditionWaitCancelled)
            && ($event->payload[$kind . '_wait_id'] ?? null) === $waitId);
    }

    /**
     * @param array<string, mixed> $member
     */
    public static function terminal(WorkflowRun $run, array $member): ?WorkflowHistoryEvent
    {
        $run->loadMissing('historyEvents');
        $cancelled = $run->historyEvents->filter(static fn (WorkflowHistoryEvent $event): bool =>
            self::isCancelled(
                $event
            ) && ($event->payload[$member['kind'] . '_wait_id'] ?? null) === $member['wait_id']);
        if ($cancelled->count() > 1) {
            throw new LogicException('cancellation_scope_wait_history_invalid');
        }
        if ($cancelled->isNotEmpty() && $run->historyEvents->contains(static fn (WorkflowHistoryEvent $event): bool =>
            (($event->payload[$member['kind'] . '_wait_id'] ?? null) === $member['wait_id']
                && in_array($event->event_type, [HistoryEventType::SignalReceived, HistoryEventType::SignalApplied,
                    HistoryEventType::ConditionWaitSatisfied, HistoryEventType::ConditionWaitTimedOut], true))
            || ($member['timer_id'] !== null && ($event->payload['timer_id'] ?? null) === $member['timer_id']
                && $event->event_type === HistoryEventType::TimerFired))) {
            throw new LogicException('cancellation_scope_wait_history_invalid');
        }
        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            $id = $event->payload[$member['kind'] . '_wait_id'] ?? null;
            if ($id === $member['wait_id'] && in_array($event->event_type, $member['kind'] === 'signal'
                ? [
                    HistoryEventType::SignalReceived,
                    HistoryEventType::SignalApplied,
                    HistoryEventType::SignalWaitCancelled,
                ]
                : [
                    HistoryEventType::ConditionWaitSatisfied,
                    HistoryEventType::ConditionWaitTimedOut,
                    HistoryEventType::ConditionWaitCancelled,
                ], true)) {
                return $event;
            }
            if ($member['timer_id'] !== null && ($event->payload['timer_id'] ?? null) === $member['timer_id']
                && ($event->event_type === HistoryEventType::TimerFired
                    || ($member['kind'] === 'signal' && $event->event_type === HistoryEventType::TimerCancelled))) {
                return $event;
            }
            if ($event->event_type === HistoryEventType::SelectionOperationCancelled
                && is_int($event->payload['member_base_sequence'] ?? null) && is_int(
                    $event->payload['member_size'] ?? null
                )
                && $member['sequence'] >= $event->payload['member_base_sequence']
                && $event->payload['member_size'] > $member['sequence'] - $event->payload['member_base_sequence']) {
                return $event;
            }
        }
        return null;
    }

    private static function isCancelled(WorkflowHistoryEvent $event): bool
    {
        return in_array(
            $event->event_type,
            [HistoryEventType::SignalWaitCancelled, HistoryEventType::ConditionWaitCancelled],
            true
        );
    }

    /**
     * @param array<string, mixed> $member
     */
    private static function assertReceipt(
        WorkflowRun $run,
        ScopedCancellationPreparation $preparation,
        WorkflowHistoryEvent $terminal,
        array $member
    ): void {
        $snapshot = $terminal->payload['cancellation_scope'] ?? null;
        $request = $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::CancellationScopeRequested && ($event->payload['scope_id'] ?? null) === $preparation->scopeId);
        if (! is_array($snapshot) || ($snapshot['schema'] ?? null) !== self::SCHEMA
            || ($snapshot['workflow_run_id'] ?? null) !== $run->id || ($snapshot['scope_id'] ?? null) !== $preparation->scopeId
            || ($snapshot['request_id'] ?? null) !== $preparation->requestId
            || ($snapshot['request_history_event_id'] ?? null) !== $request?->id
            || ($snapshot['preparation_history_event_id'] ?? null) !== $preparation->historyEventId
            || ($snapshot['authority_deadline_at'] ?? null) !== $preparation->authorityDeadlineAt
            || ($terminal->payload['sequence'] ?? null) !== $member['sequence']
            || ($terminal->payload['timer_id'] ?? null) !== $member['timer_id'] || $terminal->sequence <= $preparation->historySequence
            || ! is_array($snapshot['cancellation'] ?? null)) {
            throw new LogicException('cancellation_scope_wait_fence_not_established');
        }
        $opened = $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === ($member['kind'] === 'signal' ? HistoryEventType::SignalWaitOpened : HistoryEventType::ConditionWaitOpened)
            && ($event->payload[$member['kind'] . '_wait_id'] ?? null) === $member['wait_id']);
        foreach ([
            'signal_name',
            'condition_wait_occurrence_id',
            'condition_key',
            'condition_definition_fingerprint',
            'timeout_seconds',
        ] as $field) {
            if (($terminal->payload[$field] ?? null) !== ($opened?->payload[$field] ?? null)) {
                throw new LogicException('cancellation_scope_wait_fence_not_established');
            }
        }
        if (ParallelChildGroup::metadataPathFromPayload(
            $terminal->payload
        ) !== ParallelChildGroup::metadataPathFromPayload($opened->payload)) {
            throw new LogicException('cancellation_scope_wait_fence_not_established');
        }
        try {
            $cancelledAt = $terminal->payload['cancelled_at'] ?? null;
            if (! is_string($cancelledAt) || CarbonImmutable::parse($cancelledAt)->toISOString() !== $cancelledAt
                || CarbonImmutable::parse($cancelledAt)->lt($preparation->recordedAt)
                || CarbonImmutable::parse($cancelledAt)->gte(
                    CarbonImmutable::parse($preparation->authorityDeadlineAt)
                )) {
                throw new LogicException('cancellation_scope_wait_fence_not_established');
            }
            if (ScopedCancellationContext::fromArray($snapshot['cancellation'])->toArray()
                !== $preparation->context->toArray()) {
                throw new LogicException('cancellation_scope_wait_fence_not_established');
            }
        } catch (\InvalidArgumentException $error) {
            throw new LogicException('cancellation_scope_wait_fence_not_established', previous: $error);
        }
    }
}
