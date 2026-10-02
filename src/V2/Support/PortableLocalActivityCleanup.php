<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use InvalidArgumentException;
use Workflow\V2\CancellationContext;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

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
    public static function snapshot(WorkflowRun $run, ?array $proof, int $sequence): ?array
    {
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
        ], $execution->sequence);
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
        $deadline = $execution->activity_options['cancellation_cleanup']['cleanup_deadline_at'] ?? null;

        return is_string($deadline) ? \Illuminate\Support\Carbon::parse($deadline) : null;
    }

    public static function bound(ActivityExecution $execution, ?CarbonInterface $deadline): ?CarbonInterface
    {
        $cleanup = self::deadline($execution);

        return $cleanup !== null && ($deadline === null || $cleanup->lt($deadline)) ? $cleanup : $deadline;
    }
}
