<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Str;
use LogicException;
use Workflow\V2\CancellationContext;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

/** A policy-owned root on a closed parent, without changing its terminal outcome. */
final class ParentCloseCancellation
{
    public const CLEANUP_TIMEOUT_SECONDS = 600;

    /**
     * Caller holds the parent run lock for the entire enforcement transaction.
     */
    public static function ensureOrigin(WorkflowRun $parent, string $reason): void
    {
        // An accepted cooperative request always owns the original budget.
        // Never replace a legacy request whose rich context is unavailable.
        if (is_string($parent->cancellation_request_command_id) || self::context($parent) !== null) {
            return;
        }

        $closed = self::closure($parent);
        if ($closed === null) {
            throw new LogicException('cancellation_parent_closure_unavailable');
        }
        $requestId = (string) Str::ulid();
        $requestedAt = $closed->recorded_at->toImmutable();
        $context = CancellationContext::fromArray([
            'schema' => 'durable-workflow.cancellation-context/v1',
            'request_id' => $requestId,
            'root_request_id' => $requestId,
            'root_workflow_instance_id' => $parent->workflow_instance_id,
            'root_workflow_run_id' => $parent->id,
            'parent_request_id' => null,
            'reason' => $reason,
            'requester' => [
                'type' => 'workflow',
                'id' => $parent->workflow_instance_id,
                'label' => 'Parent-close policy',
            ],
            'source' => 'parent_close_policy',
            'requested_at' => $requestedAt->toISOString(),
            'cleanup_deadline_at' => $requestedAt->addSeconds(self::CLEANUP_TIMEOUT_SECONDS)->toISOString(),
            'lineage' => [[
                'request_id' => $requestId,
                'workflow_instance_id' => $parent->workflow_instance_id,
                'workflow_run_id' => $parent->id,
            ]],
        ]);
        WorkflowHistoryEvent::record($parent, HistoryEventType::ParentCloseCancellationRequested, [
            'parent_terminal_history_event_id' => $closed->id,
            'parent_terminal_event_type' => $closed->event_type->value,
            'cancellation' => $context->toArray(),
        ]);
    }

    public static function context(WorkflowRun $parent): ?CancellationContext
    {
        /** @var WorkflowHistoryEvent|null $origin */
        $origin = $parent->historyEvents()
            ->where('event_type', HistoryEventType::ParentCloseCancellationRequested->value)
            ->orderBy('sequence')
            ->first();
        if ($origin === null) {
            return null;
        }
        $snapshot = $origin->payload['cancellation'] ?? null;
        if (! is_array($snapshot)) {
            throw new LogicException('cancellation_parent_context_mismatch');
        }
        $context = CancellationContext::fromArray($snapshot);
        $closed = self::closure($parent);
        if ($closed === null || $origin->sequence <= $closed->sequence
            || ($origin->payload['parent_terminal_history_event_id'] ?? null) !== $closed->id
            || ($origin->payload['parent_terminal_event_type'] ?? null) !== $closed->event_type->value
            || $context->rootWorkflowRunId !== $parent->id
            || $context->rootWorkflowInstanceId !== $parent->workflow_instance_id
            || $context->requestId !== $context->rootRequestId
            || count($context->lineage) !== 1
            || $context->source !== 'parent_close_policy'
            || ! $context->requestedAt()
                ->equalTo($closed->recorded_at)
            || ! $context->deadline()
                ->equalTo($context->requestedAt()->addSeconds(self::CLEANUP_TIMEOUT_SECONDS))) {
            throw new LogicException('cancellation_parent_context_mismatch');
        }

        return $context;
    }

    private static function closure(WorkflowRun $parent): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $parent->historyEvents()
            ->whereIn('event_type', [
                HistoryEventType::WorkflowCompleted->value,
                HistoryEventType::WorkflowFailed->value,
                HistoryEventType::WorkflowCancelled->value,
                HistoryEventType::WorkflowTerminated->value,
                HistoryEventType::WorkflowTimedOut->value,
            ])->orderByDesc('sequence')
            ->first();

        return $event;
    }
}
