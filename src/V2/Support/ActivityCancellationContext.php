<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;
use LogicException;
use Workflow\V2\CancellationContext;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\ScopedCancellationContext;

/** @internal Reads the original cancellation fact, never a new effect authority. */
final class ActivityCancellationContext
{
    public const SCOPE_SCHEMA = 'durable-workflow.activity-scope-cancellation/v1';

    public static function forEvent(
        WorkflowRun $run,
        WorkflowHistoryEvent $event
    ): CancellationContext|ScopedCancellationContext|null {
        if ($event->workflow_run_id !== $run->id || $event->event_type !== HistoryEventType::ActivityCancelled) {
            return null;
        }
        if (! array_key_exists('cancellation_scope', $event->payload)) {
            $context = CooperativeCancellationDelivery::context($run);
            return $context !== null && $event->workflow_command_id === $context->requestId ? $context : null;
        }
        $context = self::forScopeSnapshot(
            $run,
            $event->payload['cancellation_scope'],
            $event->payload['activity_execution_id'] ?? null,
            $event->payload['sequence'] ?? null,
            $event->sequence,
        );
        return $context !== null && $event->workflow_command_id === $context->requestId ? $context : null;
    }

    /**
     * Validate canonical membership and the accepted immutable snapshot, including on cold reads.
     */
    public static function forScopeSnapshot(
        WorkflowRun $run,
        mixed $snapshot,
        mixed $executionId,
        mixed $sequence,
        ?int $fenceHistorySequence = null,
    ): ?ScopedCancellationContext {
        if (! is_array($snapshot) || ($snapshot['schema'] ?? null) !== self::SCOPE_SCHEMA
            || ($snapshot['workflow_run_id'] ?? null) !== $run->id
            || ! is_string($snapshot['scope_id'] ?? null) || ! is_array($snapshot['cancellation'] ?? null)
            || ! is_string(
                $snapshot['request_history_event_id'] ?? null
            ) || $snapshot['request_history_event_id'] === ''
            || ! is_string($executionId) || $executionId === '' || ! is_int($sequence) || $sequence < 1) {
            return null;
        }
        try {
            $context = CancellationScopeRequests::context($run, $snapshot['scope_id']);
            $encoded = ScopedCancellationContext::fromArray($snapshot['cancellation']);
            if ($context === null || $encoded->toArray() !== $context->toArray()
                || ($snapshot['request_id'] ?? null) !== $context->requestId) {
                return null;
            }
            $request = $run->historyEvents()
                ->whereKey($snapshot['request_history_event_id'])
                ->where('event_type', HistoryEventType::CancellationScopeRequested)->first();
            $scope = CancellationScopeHistory::forRun($run)[$context->scopeId];
            if ($request === null || ($request->payload['scope_id'] ?? null) !== $context->scopeId
                || ($request->payload['request_id'] ?? null) !== $context->requestId
                || ($scope['shield_parent'] && ($request->payload['parent_scope_id'] ?? null) !== null)
                || ($fenceHistorySequence !== null && $request->sequence >= $fenceHistorySequence)
                || ! CancellationScopeHistory::isRecordedBefore($run, $context->scopeId, $sequence)) {
                return null;
            }
            $scheduled = $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityScheduled)
                ->where('payload->activity_execution_id', $executionId)
                ->get();
            if ($scheduled->count() !== 1) {
                return null;
            }
            $event = $scheduled->sole();
            $payload = $event->payload;
            $activity = $payload['activity'] ?? null;
            if (($payload['sequence'] ?? null) !== $sequence || ! is_array($activity)
                || ($activity['id'] ?? null) !== $executionId
                || ($activity['cancellation_scope_id'] ?? CancellationScopeHistory::ROOT_SCOPE_ID) !== $context->scopeId
                || (array_key_exists(
                    'cancellation_scope_id',
                    $payload
                ) && $payload['cancellation_scope_id'] !== $context->scopeId)
                || ($fenceHistorySequence !== null && $event->sequence >= $fenceHistorySequence)) {
                return null;
            }
            $deadline = CancellationContext::fromArray([
                ...$context->rootContext->toArray(),
                'cleanup_deadline_at' => $snapshot['authority_deadline_at'] ?? null,
            ])->deadline();
            if ($deadline->greaterThan($context->deadline())) {
                return null;
            }
            return $context;
        } catch (InvalidArgumentException|LogicException) {
            return null;
        }
    }

    public static function rootRequestId(CancellationContext|ScopedCancellationContext $context): string
    {
        return $context instanceof ScopedCancellationContext ? $context->rootContext->rootRequestId : $context->rootRequestId;
    }
}
