<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use InvalidArgumentException;
use LogicException;
use Workflow\V2\CancellationContext;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\ScopedCancellationContext;

/** @internal Authority for an explicitly shielded portable cleanup call. */
final class PortableLocalActivityCleanup
{
    /**
     * A worker supplies only the request and canonical delivery identities.
     * The runtime supplies the root identity and immutable deadline.
     *
     * @param array<string, mixed>|null $proof
     * @return array<string, mixed>|null
     */
    public static function snapshot(
        WorkflowRun $run,
        ?array $proof,
        int $sequence,
        string $operationScope = CancellationScopeHistory::ROOT_SCOPE_ID,
    ): ?array {
        if ($proof !== null && array_key_exists('scope_id', $proof)) {
            return self::scopedSnapshot($run, $proof, $sequence, $operationScope);
        }
        if ($proof === null || array_diff(array_keys($proof), ['request_id', 'delivery_history_event_id']) !== []
            || ($proof['request_id'] ?? null) !== $run->cancellation_request_command_id
            || ! is_string($proof['delivery_history_event_id'] ?? null)) {
            return null;
        }
        $request = $run->historyEvents()
            ->where('event_type', HistoryEventType::CooperativeCancellationRequested)
            ->where('workflow_command_id', $run->cancellation_request_command_id)
            ->first();
        $delivery = $run->historyEvents()
            ->where('event_type', HistoryEventType::CooperativeCancellationDelivered)
            ->where('workflow_command_id', $run->cancellation_request_command_id)
            ->whereKey($proof['delivery_history_event_id'])->first();
        if (! $request instanceof WorkflowHistoryEvent || ! $delivery instanceof WorkflowHistoryEvent
            || $delivery->sequence <= $request->sequence
            || ($delivery->payload['workflow_command_id'] ?? null) !== $run->cancellation_request_command_id
            || ($delivery->payload['workflow_run_id'] ?? null) !== $run->id
            || ! is_int($delivery->payload['sequence'] ?? null)
            || ($delivery->payload['sequence'] ?? null) !== $run->cancellation_delivery_sequence
            || $run->cancellation_delivered_at === null
            || $delivery->recorded_at->lt($run->cancellation_delivered_at)) {
            return null;
        }
        $span = $delivery->payload['sequence_span'] ?? 1;
        if (! is_int($span) || $span < 1 || $span > PHP_INT_MAX - $delivery->payload['sequence']
            || $sequence < $delivery->payload['sequence'] + $span) {
            return null;
        }
        try {
            $requested = $request->payload['cancellation'] ?? null;
            $delivered = $delivery->payload['cancellation'] ?? null;
            if (! is_array($requested) || ! is_array($delivered)) {
                return null;
            }
            $context = CancellationContext::fromArray($requested);
            $deliveryContext = CancellationContext::fromArray($delivered);
        } catch (InvalidArgumentException) {
            return null;
        }
        if ($context->requestId !== $run->cancellation_request_command_id
            || $context->rootRequestId !== $deliveryContext->rootRequestId
            || $context->requestId !== $deliveryContext->requestId
            || ! $context->requestedAt()
                ->equalTo($deliveryContext->requestedAt())
            || ! $context->deadline()
                ->equalTo($deliveryContext->deadline())
            || $run->cancellation_deadline_at === null
            || ! $context->deadline()
                ->equalTo($run->cancellation_deadline_at)) {
            return null;
        }

        return [
            'request_id' => $context->requestId,
            'root_request_id' => $context->rootRequestId,
            'delivery_history_event_id' => $delivery->id,
            'cleanup_deadline_at' => $context->deadline()
                ->toISOString(),
        ];
    }

    public static function isExecution(
        WorkflowRun $run,
        ActivityExecution $execution,
        WorkflowHistoryEvent $started,
    ): bool {
        $stored = $execution->activity_options['cancellation_cleanup'] ?? null;
        if (! is_array($stored)) {
            return false;
        }
        $snapshot = self::snapshot($run, [
            'request_id' => $stored['request_id'] ?? null,
            'delivery_history_event_id' => $stored['delivery_history_event_id'] ?? null,
            ...(array_key_exists('scope_id', $stored) ? [
                'scope_id' => $stored['scope_id'],
            ] : []),
        ], $execution->sequence, $execution->activity_options['cancellation_scope_id'] ?? CancellationScopeHistory::ROOT_SCOPE_ID);
        $recorded = $started->payload['local_preparation']['cancellation_cleanup'] ?? null;
        if ($snapshot === null || ! is_array($recorded)
            || $started->sequence <= $run->historyEvents()
                ->whereKey($snapshot['delivery_history_event_id'])->value('sequence')) {
            return false;
        }
        // JSON object order differs across supported databases. Preserve exact
        // fields and scalar types while comparing the immutable snapshots.
        ksort($stored);
        ksort($recorded);
        ksort($snapshot);

        return $stored === $snapshot && $recorded === $snapshot;
    }

    public static function deadline(ActivityExecution $execution): ?CarbonInterface
    {
        return self::snapshotDeadline($execution->activity_options['cancellation_cleanup'] ?? null);
    }

    /**
     * A later preparation must not recancel an original, explicitly shielded cleanup call.
     */
    public static function isScopedScheduled(WorkflowRun $run, WorkflowHistoryEvent $event): bool
    {
        $stored = $event->payload['local_preparation']['cancellation_cleanup']
            ?? $event->payload['local_group_admission']['cancellation_cleanup'] ?? null;
        if (! is_array($stored) || ! array_key_exists('scope_id', $stored)) {
            return false;
        }
        $sequence = $event->payload['sequence'] ?? null;
        $scope = $event->payload['activity']['cancellation_scope_id'] ?? null;
        $deliverySequence = $run->historyEvents()
            ->whereKey($stored['delivery_history_event_id'] ?? null)
            ->where('event_type', HistoryEventType::CancellationScopeDelivered)->value('sequence');
        if ($event->event_type !== HistoryEventType::ActivityScheduled
            || ($event->payload['local_activity'] ?? null) !== true
            || ! is_int($sequence) || ! is_string($scope)
            || ! is_int($deliverySequence) || $deliverySequence >= $event->sequence) {
            throw new LogicException('cancellation_scope_cleanup_history_invalid');
        }
        $run->loadMissing('historyEvents');
        $history = $run->historyEvents;
        $run->setRelation('historyEvents', $history->filter(
            static fn (WorkflowHistoryEvent $row): bool => $row->sequence < $event->sequence
        ));
        try {
            $snapshot = self::snapshot($run, [
                'scope_id' => $stored['scope_id'],
                'request_id' => $stored['request_id'] ?? null,
                'delivery_history_event_id' => $stored['delivery_history_event_id'] ?? null,
            ], $sequence, $scope);
        } finally {
            $run->setRelation('historyEvents', $history);
        }
        if ($snapshot === null) {
            throw new LogicException('cancellation_scope_cleanup_history_invalid');
        }
        ksort($stored);
        ksort($snapshot);
        if ($stored !== $snapshot) {
            throw new LogicException('cancellation_scope_cleanup_history_invalid');
        }
        return true;
    }

    public static function isScoped(ActivityExecution $execution): bool
    {
        return isset($execution->activity_options['cancellation_cleanup']['scope_id']);
    }

    /**
     * A validated cleanup snapshot may continue under the run request from
     * which it inherited. A shared root ID alone does not identify that hop.
     * Callers must first validate the snapshot or its recorded execution.
     *
     * @param array<string, mixed>|null $snapshot
     */
    public static function belongsToRunRequest(WorkflowRun $run, ?array $snapshot): bool
    {
        if ($snapshot === null || ! is_string($run->cancellation_request_command_id)) {
            return false;
        }
        try {
            $event = $run->historyEvents()
                ->where('event_type', HistoryEventType::CooperativeCancellationRequested)
                ->where('workflow_command_id', $run->cancellation_request_command_id)
                ->first();
            $context = $event?->payload['cancellation'] ?? null;
            $request = is_array($context) ? CancellationContext::fromArray($context) : null;
            if ($request === null || $request->requestId !== $run->cancellation_request_command_id
                || $run->cancellation_deadline_at === null
                || ! $request->deadline()
                    ->equalTo($run->cancellation_deadline_at)) {
                return false;
            }
            if (! isset($snapshot['scope_id'])) {
                return ($snapshot['request_id'] ?? null) === $request->requestId
                    && ($snapshot['root_request_id'] ?? null) === $request->rootRequestId;
            }
            $preparation = ScopedCancellationPreparation::forScope(
                $run,
                $snapshot['operation_scope_id'],
                $snapshot['preparation_history_event_id'],
            );
            $runRoot = ScopedCancellationContext::fromRunContext($request);
            return $preparation !== null
                && $preparation->context->rootContext->toArray() === $runRoot->rootContext->toArray()
                && array_slice($preparation->context->lineage, 0, count($runRoot->lineage)) === $runRoot->lineage;
        } catch (InvalidArgumentException|LogicException) {
            return false;
        }
    }

    /**
     * @param array<string, mixed>|null $snapshot
     */
    public static function snapshotDeadline(?array $snapshot): ?CarbonInterface
    {
        $deadline = $snapshot['cleanup_deadline_at'] ?? null;
        if (! is_string($deadline)) {
            return null;
        }
        $deadline = \Illuminate\Support\Carbon::parse($deadline);
        $ceiling = $snapshot['authority_deadline_at'] ?? null;

        return is_string($ceiling) ? $deadline->min(\Illuminate\Support\Carbon::parse($ceiling)) : $deadline;
    }

    public static function currentDeadline(WorkflowRun $run, ?array $snapshot): ?CarbonInterface
    {
        $deadline = self::snapshotDeadline($snapshot);
        if ($deadline === null || ! isset($snapshot['scope_id'])) {
            return $deadline;
        }
        $authority = CancellationScopeRequests::authority($run, $snapshot['operation_scope_id']);
        $current = $authority['deadline_at'];

        return $current === null ? $deadline : $deadline->min(\Illuminate\Support\Carbon::parse($current));
    }

    public static function currentAuthorityExpired(WorkflowRun $run, ActivityExecution $execution): bool
    {
        if (! self::isScoped($execution)) {
            return false;
        }
        $deadline = self::currentDeadline($run, $execution->activity_options['cancellation_cleanup']);

        return $deadline !== null && $deadline->lt(self::deadline($execution)) && now()->gte($deadline);
    }

    public static function bound(
        ActivityExecution $execution,
        ?CarbonInterface $deadline,
        ?WorkflowRun $run = null
    ): ?CarbonInterface {
        $cleanup = $run === null ? self::deadline($execution)
            : self::currentDeadline($run, $execution->activity_options['cancellation_cleanup'] ?? null);

        return $cleanup !== null && ($deadline === null || $cleanup->lt($deadline)) ? $cleanup : $deadline;
    }

    /** @param array<string, mixed> $proof
     * @return array<string, mixed>|null
     */
    private static function scopedSnapshot(
        WorkflowRun $run,
        array $proof,
        int $sequence,
        string $operationScope
    ): ?array {
        if (count($proof) !== 3 || array_diff(
            array_keys($proof),
            ['scope_id', 'request_id', 'delivery_history_event_id']
        ) !== []
            || ! is_string($proof['scope_id'] ?? null) || $proof['scope_id'] === CancellationScopeHistory::ROOT_SCOPE_ID
            || ! is_string($proof['request_id'] ?? null) || ! is_string($proof['delivery_history_event_id'] ?? null)) {
            return null;
        }
        try {
            $delivery = CancellationScopeDelivery::recorded($run, $proof['scope_id']);
            if ($delivery === null || $delivery->id !== $proof['delivery_history_event_id']
                || $delivery->payload['request_id'] !== $proof['request_id']) {
                return null;
            }
            $preparation = ScopedCancellationPreparation::forScope(
                $run,
                $operationScope,
                $delivery->payload['preparation_history_event_id'],
            );
            $context = \Workflow\V2\ScopedCancellationContext::fromArray($delivery->payload['cancellation']);
            if ($preparation === null || $preparation->context->rootContext->toArray() !== $context->rootContext->toArray()) {
                return null;
            }
        } catch (InvalidArgumentException|LogicException) {
            return null;
        }
        $span = $delivery->payload['sequence_span'];
        if ($span > PHP_INT_MAX - $delivery->payload['sequence']
            || $sequence < $delivery->payload['sequence'] + $span) {
            return null;
        }

        return [
            'scope_id' => $proof['scope_id'],
            'operation_scope_id' => $operationScope,
            'request_id' => $context->requestId,
            'root_request_id' => $context->rootContext->rootRequestId,
            'delivery_history_event_id' => $delivery->id,
            'preparation_history_event_id' => $preparation->historyEventId,
            'cleanup_deadline_at' => $context->deadline()
                ->toISOString(),
            'authority_deadline_at' => $preparation->authorityDeadlineAt,
        ];
    }
}
