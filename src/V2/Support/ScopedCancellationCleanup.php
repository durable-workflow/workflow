<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonImmutable;
use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

/** @internal Original delivery authority for explicitly shielded scoped timers. */
final class ScopedCancellationCleanup
{
    /** @param array<string, mixed> $command
     * @return array<string, array<string, string>>|null
     */
    public static function metadata(array $command): ?array
    {
        if (! array_key_exists('cancellation_cleanup', $command)) {
            return [];
        }
        $proof = $command['cancellation_cleanup'];
        if (! is_array($proof) || count($proof) !== 3
            || array_diff(array_keys($proof), ['scope_id', 'request_id', 'delivery_history_event_id']) !== []
            || ! is_string($command['cancellation_scope_id'] ?? null)
            || $command['cancellation_scope_id'] === CancellationScopeHistory::ROOT_SCOPE_ID) {
            return null;
        }
        foreach (['scope_id', 'request_id', 'delivery_history_event_id'] as $field) {
            if (! is_string($proof[$field] ?? null) || trim($proof[$field]) === ''
                || strlen($proof[$field]) > 255 || preg_match('//u', $proof[$field]) !== 1) {
                return null;
            }
        }
        if ($proof['scope_id'] === CancellationScopeHistory::ROOT_SCOPE_ID) {
            return null;
        }
        return [
            'cancellation_cleanup' => $proof,
        ];
    }

    /**
     * @param array<string, mixed> $command
     */
    public static function timerRefusal(WorkflowRun $run, array $command, int $sequence): ?string
    {
        if (! isset($command['cancellation_cleanup'])) {
            return null;
        }
        $snapshot = PortableLocalActivityCleanup::snapshot(
            $run,
            $command['cancellation_cleanup'],
            $sequence,
            $command['cancellation_scope_id'],
        );
        if ($snapshot === null) {
            return 'cancellation_scope_cleanup_authority_mismatch';
        }
        $authority = CancellationScopeRequests::authority($run, $command['cancellation_scope_id']);
        $deadline = CarbonImmutable::parse($snapshot['authority_deadline_at']);
        if ($authority['deadline_at'] !== null) {
            $deadline = $deadline->min(CarbonImmutable::parse($authority['deadline_at']));
        }
        if (! $authority['active'] || now()->gte($deadline)) {
            return 'cancellation_scope_cleanup_authority_expired';
        }
        // Preserve the authored delay. Do not turn a too-long wait into an
        // early TimerFired event or let it extend the original cleanup budget.
        return now()->addSeconds($command['delay_seconds'])->gte($deadline)
            ? 'cancellation_scope_cleanup_timer_exceeds_deadline' : null;
    }

    /**
     * A later ancestor preparation must preserve already shielded cleanup.
     */
    public static function isScheduledTimer(WorkflowRun $run, WorkflowHistoryEvent $event): bool
    {
        $stored = $event->payload['cancellation_cleanup'] ?? null;
        if (! array_key_exists('cancellation_cleanup', $event->payload)) {
            return false;
        }
        $sequence = $event->payload['sequence'] ?? null;
        $scope = $event->payload['cancellation_scope_id'] ?? null;
        if ($event->event_type !== HistoryEventType::TimerScheduled || ! is_array($stored)
            || ! is_int($sequence) || ! is_string($scope)) {
            throw new LogicException('cancellation_scope_cleanup_history_invalid');
        }
        $deliverySequence = $run->historyEvents()
            ->whereKey($stored['delivery_history_event_id'] ?? null)
            ->where('event_type', HistoryEventType::CancellationScopeDelivered)->value('sequence');
        if (! is_int($deliverySequence) || $deliverySequence >= $event->sequence) {
            throw new LogicException('cancellation_scope_cleanup_history_invalid');
        }
        $run->loadMissing('historyEvents');
        $history = $run->historyEvents;
        $run->setRelation('historyEvents', $history->filter(
            static fn (WorkflowHistoryEvent $row): bool => $row->sequence < $event->sequence,
        ));
        try {
            $snapshot = PortableLocalActivityCleanup::snapshot($run, [
                'scope_id' => $stored['scope_id'] ?? null,
                'request_id' => $stored['request_id'] ?? null,
                'delivery_history_event_id' => $stored['delivery_history_event_id'] ?? null,
            ], $sequence, $scope);
        } finally {
            $run->setRelation('historyEvents', $history);
        }
        if ($snapshot === null) {
            throw new LogicException('cancellation_scope_cleanup_history_invalid');
        }
        ksort($snapshot);
        ksort($stored);
        if ($snapshot !== $stored) {
            throw new LogicException('cancellation_scope_cleanup_history_invalid');
        }
        $deadline = CarbonImmutable::parse($snapshot['authority_deadline_at']);
        if (! is_string($event->payload['fire_at'] ?? null)
            || CarbonImmutable::parse($event->payload['fire_at'])->gte($deadline)
            || CarbonImmutable::parse($event->recorded_at ?? $event->created_at)->gte($deadline)
            || ($event->payload['timer_kind'] ?? null) !== null) {
            throw new LogicException('cancellation_scope_cleanup_history_invalid');
        }
        return true;
    }
}
