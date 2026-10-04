<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

/** Applies the recorded operation policy while the caller holds the parent run lock. */
final class ChildCancellation
{
    public static function policy(WorkflowRun $run, int $sequence): CancellationPolicy
    {
        $scheduled = ChildRunHistory::scheduledEventForSequence($run, $sequence);
        $value = $scheduled?->payload['cancellation_policy'] ?? CancellationPolicy::Abandon->value;

        return is_string($value)
            ? CancellationPolicy::from($value)
            : throw new LogicException('Recorded child cancellation policy is invalid.');
    }

    /**
     * True means wait for canonical child terminal history before delivering to parent code.
     */
    public static function prepare(WorkflowRun $run, WorkflowTask $task, int $sequence): bool
    {
        $policy = self::policy($run, $sequence);
        if ($policy === CancellationPolicy::Abandon) {
            return false;
        }
        $child = ChildRunHistory::childRunForSequence($run, $sequence);
        if (! $child instanceof WorkflowRun) {
            return false;
        }
        $context = CooperativeCancellationDelivery::context($run);
        if ($context === null) {
            throw new LogicException('Child cancellation propagation requires a canonical cancellation context.');
        }
        $run->loadMissing('historyEvents');
        $recorded = $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::ChildCancellationRequested
            && ($event->payload['sequence'] ?? null) === $sequence
            && ($event->payload['child_workflow_run_id'] ?? null) === $child->id
            && $event->workflow_command_id === $run->cancellation_request_command_id);
        if (! $recorded instanceof WorkflowHistoryEvent && ! $child->status->isTerminal()) {
            $request = WorkflowStub::loadRun($child->id)->attemptRequestCancellationFromParent($run->id);
            if ($request->rejected() && ! in_array($request->rejectionReason(), [
                'cancellation_root_conflict', 'run_not_active',
            ], true)) {
                throw new LogicException('Child cooperative cancellation refused: ' . $request->rejectionReason());
            }
            $child->refresh();
            $childContext = CooperativeCancellationDelivery::context($child);
            $event = WorkflowHistoryEvent::record($run, HistoryEventType::ChildCancellationRequested, [
                'sequence' => $sequence,
                'policy' => $policy->value,
                'parent_request_id' => $context->requestId,
                'root_request_id' => $context->rootRequestId,
                'cleanup_deadline_at' => $context->deadline()
                    ->toISOString(),
                'child_workflow_instance_id' => $child->workflow_instance_id,
                'child_workflow_run_id' => $child->id,
                'child_request_id' => $childContext?->requestId,
                'child_root_request_id' => $childContext?->rootRequestId,
                'child_cleanup_deadline_at' => $childContext?->deadline()
                    ->toISOString(),
                'request_outcome' => $request->accepted() ? 'accepted' : 'rejected',
                'rejection_reason' => $request->rejectionReason(),
            ], $task, $run->cancellation_request_command_id);
            $run->historyEvents->push($event);
        }
        $terminal = ChildRunHistory::terminalEventForRun($child);
        if ($terminal !== null && ! $run->historyEvents->contains(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::ChildCancellationResolved
            && ($event->payload['sequence'] ?? null) === $sequence
            && ($event->payload['child_workflow_run_id'] ?? null) === $child->id
            && $event->workflow_command_id === $run->cancellation_request_command_id)) {
            $childContext = CooperativeCancellationDelivery::context($child);
            $event = WorkflowHistoryEvent::record($run, HistoryEventType::ChildCancellationResolved, [
                'sequence' => $sequence,
                'policy' => $policy->value,
                'parent_request_id' => $context->requestId,
                'root_request_id' => $context->rootRequestId,
                'cleanup_deadline_at' => $context->deadline()
                    ->toISOString(),
                'child_workflow_instance_id' => $child->workflow_instance_id,
                'child_workflow_run_id' => $child->id,
                'child_request_id' => $childContext?->requestId,
                'child_root_request_id' => $childContext?->rootRequestId,
                'child_cleanup_deadline_at' => $childContext?->deadline()
                    ->toISOString(),
                'child_status' => ChildRunHistory::resolvedStatus(null, $child)?->value,
                'child_terminal_history_event_id' => $terminal->id,
                'child_terminal_event_type' => $terminal->event_type->value,
                'reason' => $terminal->payload['reason'] ?? null,
            ], $task, $run->cancellation_request_command_id);
            $run->historyEvents->push($event);
        }

        return $policy === CancellationPolicy::WaitCancellationCompleted && $terminal === null;
    }
}
