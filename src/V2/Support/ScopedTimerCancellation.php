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

/** @internal Timer actors use the timer-task/run lock prefix of RunTimerTask. */
final class ScopedTimerCancellation
{
    public const SCHEMA = 'durable-workflow.scoped-timer-cancellation/v1';

    /**
     * @return list<array{sequence: int, timer_id: string, descriptor_hash: string}>
     */
    public static function members(WorkflowRun $run, string $scopeId): array
    {
        $run->loadMissing('historyEvents');
        $members = [];
        $ids = [];
        $sequences = [];
        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if ($event->event_type !== HistoryEventType::TimerScheduled) {
                continue;
            }
            $payload = $event->payload;
            $descriptor = $payload['timer'] ?? [];
            if (! is_array($descriptor)) {
                throw new LogicException('cancellation_scope_timer_history_invalid');
            }
            $nested = $descriptor['cancellation_scope_id'] ?? CancellationScopeHistory::ROOT_SCOPE_ID;
            $address = $payload['cancellation_scope_id'] ?? $nested;
            if ($address !== $scopeId && $nested !== $scopeId) {
                continue;
            }
            if ($address !== $scopeId || (array_key_exists('cancellation_scope_id', $descriptor)
                && $nested !== $scopeId)) {
                throw new LogicException('cancellation_scope_delivery_membership_mismatch');
            }
            $id = $payload['timer_id'] ?? null;
            $sequence = $payload['sequence'] ?? null;
            $delay = $payload['delay_seconds'] ?? null;
            $fireAt = $payload['fire_at'] ?? null;
            if (! is_string($id) || $id === '' || ! is_int($sequence) || $sequence < 1
                || ! is_int($delay) || $delay < 0 || ! is_string($fireAt) || $fireAt === ''
                || isset($ids[$id]) || isset($sequences[$sequence])) {
                throw new LogicException('cancellation_scope_timer_history_invalid');
            }
            try {
                if (CarbonImmutable::parse($fireAt)->toISOString() !== $fireAt) {
                    throw new LogicException('cancellation_scope_timer_history_invalid');
                }
            } catch (\InvalidArgumentException $error) {
                throw new LogicException('cancellation_scope_timer_history_invalid', previous: $error);
            }
            $ids[$id] = true;
            $sequences[$sequence] = true;
            $members[] = [
                'sequence' => $sequence,
                'timer_id' => $id,
                'descriptor_hash' => hash('sha256', json_encode([
                    $scopeId, $event->id, $sequence, $id, $delay, $fireAt,
                    $payload['timer_kind'] ?? null, $payload['condition_wait_id'] ?? null,
                    $payload['condition_wait_occurrence_id'] ?? null, $payload['signal_wait_id'] ?? null,
                    ParallelChildGroup::metadataPathFromPayload($payload),
                ], JSON_THROW_ON_ERROR)),
            ];
        }
        return $members;
    }

    /**
     * @return list<array{sequence: int, timer_id: string, descriptor_hash: string}>
     */
    public static function normalizeMembers(mixed $members): array
    {
        if (! is_array($members) || ! array_is_list($members)) {
            throw new LogicException('cancellation_scope_preparation_history_invalid');
        }
        $normalized = [];
        foreach ($members as $member) {
            if (! is_array($member) || count($member) !== 3 || ! is_int($member['sequence'] ?? null)
                || ! is_string($member['timer_id'] ?? null) || ! is_string($member['descriptor_hash'] ?? null)) {
                throw new LogicException('cancellation_scope_preparation_history_invalid');
            }
            $normalized[] = [
                'sequence' => $member['sequence'],
                'timer_id' => $member['timer_id'],
                'descriptor_hash' => $member['descriptor_hash'],
            ];
        }
        return $normalized;
    }

    /**
     * @return array{fenced: bool, history_event_id: string|null, timer_id: string, sequence: int}
     */
    public static function fence(
        WorkflowRun $run,
        WorkflowTask $workflowTask,
        string $timerId,
        string $scopeId,
        string $requestId,
        string $protocolVersion,
    ): array {
        if (! WorkerProtocolVersion::supportsCancellationScopeMembership($protocolVersion)) {
            throw new LogicException('cancellation_scope_requires_protocol_1_20');
        }
        if ($run->getConnection()->transactionLevel() !== 0) {
            throw new LogicException('cancellation_scope_timer_requires_own_transaction');
        }
        $tasks = ConfiguredV2Models::query('task_model', WorkflowTask::class)
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Timer)
            ->where('payload->timer_id', $timerId)
            ->get();
        if ($tasks->count() !== 1) {
            throw new LogicException('cancellation_scope_timer_task_mismatch');
        }
        $timerTaskSnapshot = $tasks->sole();
        $timerTaskId = $timerTaskSnapshot->id;
        return $run->getConnection()
            ->transaction(static function () use (
                $run,
                $workflowTask,
                $timerId,
                $scopeId,
                $requestId,
                $timerTaskId
            ): array {
                /** @var WorkflowTask|null $timerTask */
                $timerTask = ConfiguredV2Models::query('task_model', WorkflowTask::class)->lockForUpdate()->find(
                    $timerTaskId
                );
                /** @var WorkflowRun $locked */
                $locked = ConfiguredV2Models::query('run_model', WorkflowRun::class)->lockForUpdate()->findOrFail(
                    $run->id
                );
                if ($timerTask === null || $timerTask->workflow_run_id !== $locked->id
                    || $timerTask->namespace !== $locked->namespace || $timerTask->task_type !== TaskType::Timer
                    || ($timerTask->payload['timer_id'] ?? null) !== $timerId
                    || $locked->tasks()
                        ->where('task_type', TaskType::Timer)->where('payload->timer_id', $timerId)->count() !== 1) {
                    throw new LogicException('cancellation_scope_timer_task_mismatch');
                }
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
                if ($context === null || $context->requestId !== $requestId) {
                    throw new LogicException('cancellation_scope_request_mismatch');
                }
                $authority = CancellationScopeRequests::authority($locked, $scopeId);
                $preparation = CancellationScopeDelivery::prepared($locked, $scopeId);
                if ($preparation === null) {
                    throw new LogicException('cancellation_scope_delivery_not_prepared');
                }
                if (! $authority['active'] || $authority['deadline_at'] === null
                    || now()
                        ->gte(CarbonImmutable::parse($preparation->payload['authority_deadline_at']))) {
                    throw new LogicException('cancellation_scope_authority_expired');
                }
                $member = collect($preparation->payload['timer_members'])->firstWhere('timer_id', $timerId);
                if (! is_array($member)) {
                    throw new LogicException('cancellation_scope_timer_not_prepared');
                }
                $terminal = self::terminal($locked, $timerId, $member['sequence']);
                /** @var WorkflowTimer|null $timer */
                $timer = ConfiguredV2Models::query('timer_model', WorkflowTimer::class)->lockForUpdate()->find(
                    $timerId
                );
                $timer ??= TimerRecovery::restore($locked, $timerId);
                if ($timer === null || $timer->workflow_run_id !== $locked->id || $timer->sequence !== $member['sequence']) {
                    throw new LogicException('cancellation_scope_timer_projection_mismatch');
                }
                // The final row lock may have waited beyond either authority.
                // Holding the rows does not extend the original claim or budget.
                if (now()->gte($claim->lease_expires_at)) {
                    throw new LogicException('cancellation_scope_workflow_claim_mismatch');
                }
                if (now()->gte(CarbonImmutable::parse($authority['deadline_at']))
                    || now()
                        ->gte(CarbonImmutable::parse($preparation->payload['authority_deadline_at']))) {
                    throw new LogicException('cancellation_scope_authority_expired');
                }
                if ($terminal?->event_type === HistoryEventType::TimerFired) {
                    return [
                        'fenced' => false,
                        'history_event_id' => null,
                        'timer_id' => $timerId,
                        'sequence' => $member['sequence'],
                    ];
                }
                if ($terminal !== null) {
                    self::assertReceipt($locked, $preparation, $terminal);
                } else {
                    if ($timer->status !== TimerStatus::Pending
                        || ! in_array($timerTask->status, [TaskStatus::Ready, TaskStatus::Leased], true)) {
                        throw new LogicException('cancellation_scope_timer_terminal_history_not_recorded');
                    }
                    $request = $locked->historyEvents()
                        ->where('event_type', HistoryEventType::CancellationScopeRequested)
                        ->where('payload->scope_id', $scopeId)
                        ->sole();
                    $terminal = TimerCancellation::record($locked, $timer, $claim, cancellationScope: [
                        'schema' => self::SCHEMA,
                        'workflow_run_id' => $locked->id,
                        'scope_id' => $scopeId,
                        'request_id' => $requestId,
                        'request_history_event_id' => $request->id,
                        'preparation_history_event_id' => $preparation->id,
                        'cancellation' => $context->toArray(),
                        'authority_deadline_at' => $preparation->payload['authority_deadline_at'],
                    ]);
                }
                $timer->forceFill([
                    'status' => TimerStatus::Cancelled,
                    'fired_at' => null,
                ])->save();
                $timerTask->forceFill([
                    'status' => TaskStatus::Cancelled,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                ])->save();
                return [
                    'fenced' => true,
                    'history_event_id' => $terminal->id,
                    'timer_id' => $timerId,
                    'sequence' => $member['sequence'],
                ];
            }, 5);
    }

    public static function assertReady(
        WorkflowRun $run,
        WorkflowHistoryEvent $preparation,
        ?int $beforeHistorySequence = null,
    ): void {
        $run->loadMissing('historyEvents');
        $history = $run->historyEvents;
        if ($beforeHistorySequence !== null) {
            $run->setRelation('historyEvents', $history->filter(
                static fn (WorkflowHistoryEvent $event): bool => $event->sequence < $beforeHistorySequence
            ));
        }
        try {
            foreach ($preparation->payload['timer_members'] as $member) {
                $terminal = self::terminal($run, $member['timer_id'], $member['sequence']);
                if ($terminal === null) {
                    throw new LogicException('cancellation_scope_timer_fence_not_established');
                }
                if ($terminal->event_type === HistoryEventType::TimerFired) {
                    continue;
                }
                self::assertReceipt($run, $preparation, $terminal);
                if ($beforeHistorySequence === null) {
                    $timer = ConfiguredV2Models::query('timer_model', WorkflowTimer::class)->find($member['timer_id']);
                    $tasks = $run->tasks()
                        ->where('task_type', TaskType::Timer)
                        ->where('payload->timer_id', $member['timer_id'])->get();
                    $timerTask = $tasks->count() === 1 ? $tasks->first() : null;
                    if ($timer === null || $timer->workflow_run_id !== $run->id
                        || $timer->sequence !== $member['sequence'] || $timer->status !== TimerStatus::Cancelled
                        || $timerTask === null || $timerTask->namespace !== $run->namespace
                        || $timerTask->status !== TaskStatus::Cancelled || $timerTask->lease_expires_at !== null) {
                        throw new LogicException('cancellation_scope_timer_fence_not_established');
                    }
                }
            }
        } finally {
            $run->setRelation('historyEvents', $history);
        }
    }

    private static function terminal(WorkflowRun $run, string $timerId, int $sequence): ?WorkflowHistoryEvent
    {
        $run->loadMissing('historyEvents');
        $scheduled = $run->historyEvents->filter(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::TimerScheduled && ($event->payload['timer_id'] ?? null) === $timerId);
        $terminals = $run->historyEvents->filter(static fn (WorkflowHistoryEvent $event): bool =>
            in_array($event->event_type, [HistoryEventType::TimerCancelled, HistoryEventType::TimerFired], true)
            && ($event->payload['timer_id'] ?? null) === $timerId);
        if ($scheduled->count() !== 1 || ($scheduled->sole()->payload['sequence'] ?? null) !== $sequence
            || $terminals->count() > 1) {
            throw new LogicException('cancellation_scope_timer_history_invalid');
        }
        $scheduledEvent = $scheduled->sole();
        $terminal = $terminals->first();
        if ($terminal !== null && (($terminal->payload['sequence'] ?? null) !== $sequence
            || $terminal->sequence <= $scheduledEvent->sequence
            || ($terminal->payload['delay_seconds'] ?? null) !== $scheduledEvent->payload['delay_seconds']
            || ($terminal->payload['fire_at'] ?? null) !== $scheduledEvent->payload['fire_at'])) {
            throw new LogicException('cancellation_scope_timer_history_invalid');
        }
        return $terminal;
    }

    private static function assertReceipt(
        WorkflowRun $run,
        WorkflowHistoryEvent $preparation,
        WorkflowHistoryEvent $terminal,
    ): void {
        $snapshot = $terminal->payload['cancellation_scope'] ?? null;
        $payload = $preparation->payload;
        $request = $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::CancellationScopeRequested
            && ($event->payload['scope_id'] ?? null) === $payload['scope_id']);
        if (! is_array($snapshot) || ($snapshot['schema'] ?? null) !== self::SCHEMA
            || ($snapshot['workflow_run_id'] ?? null) !== $run->id
            || ($snapshot['scope_id'] ?? null) !== $payload['scope_id']
            || ($snapshot['request_id'] ?? null) !== $payload['request_id']
            || ($snapshot['request_history_event_id'] ?? null) !== $request?->id
            || ($snapshot['preparation_history_event_id'] ?? null) !== $preparation->id
            || ($snapshot['authority_deadline_at'] ?? null) !== $payload['authority_deadline_at']
            || ! is_array($snapshot['cancellation'] ?? null) || $terminal->sequence <= $preparation->sequence) {
            throw new LogicException('cancellation_scope_timer_fence_not_established');
        }
        try {
            if (ScopedCancellationContext::fromArray($snapshot['cancellation'])->toArray()
                !== ScopedCancellationContext::fromArray($payload['cancellation'])->toArray()) {
                throw new LogicException('cancellation_scope_timer_fence_not_established');
            }
        } catch (\InvalidArgumentException $error) {
            throw new LogicException('cancellation_scope_timer_fence_not_established', previous: $error);
        }
    }
}
