<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

/** @internal Cancellation of an original completion-waiting owned Nexus call. */
final class ServiceOperationCancellation
{
    /**
     * @return array<string, mixed>|null
     */
    public static function unsupportedReason(WorkflowRun $run, ?string $incomingRootRequestId = null): ?array
    {
        if (! $run->relationLoaded('historyEvents')) {
            $run->loadMissing('historyEvents');
        }
        foreach ($run->historyEvents as $event) {
            $payload = $event->payload;
            if (! in_array($event->event_type, [
                HistoryEventType::ServiceOperationScheduled,
                HistoryEventType::ServiceCallStarted,
            ], true)
                || ($payload['operation_mode'] ?? null) !== 'sync'
                || ! self::waitsForCompletion($event)) {
                continue;
            }
            $sequence = $payload['sequence'] ?? null;
            if (! is_int($sequence) || $run->historyEvents->contains(
                static fn (WorkflowHistoryEvent $candidate): bool =>
                ($candidate->payload['sequence'] ?? null) === $sequence && in_array($candidate->event_type, [
                    HistoryEventType::ServiceCallCompleted, HistoryEventType::ServiceCallFailed,
                    HistoryEventType::ServiceCallCancelled,
                ], true)
            )) {
                continue;
            }
            $reason = null;
            $call = null;
            try {
                $call = self::callAt($run, $sequence);
                /** @var WorkflowRun|null $target */
                $target = $call === null ? null : ConfiguredV2Models::query('run_model', WorkflowRun::class)
                    ->where('namespace', $call->target_namespace)
                    ->where('workflow_instance_id', $call->linked_workflow_instance_id)
                    ->find($call->linked_workflow_run_id);
                $reason = $call === null ? 'nexus_cancellation_owner_unavailable'
                    : ($target === null && $call->status === ServiceCallStatus::Accepted->value
                        ? null
                        : ($target === null ? 'nexus_cancellation_owner_unavailable'
                            : (ServiceCallCancellationOrigin::validateOwnership($call, $run, $target)
                                ?? ServiceCallCancellationOrigin::validateTargetCreation($call))));
                if ($reason === null && $target instanceof WorkflowRun && ! $target->status->isTerminal()
                    && is_string($target->cancellation_request_command_id)
                    && CooperativeCancellationDelivery::context($target)?->rootRequestId !== $incomingRootRequestId) {
                    $reason = 'nexus_cancellation_target_root_conflict';
                }
            } catch (LogicException $error) {
                if (! str_starts_with($error->getMessage(), 'nexus_cancellation_')) {
                    throw $error;
                }
                $reason = $error->getMessage();
            }
            if ($reason !== null) {
                return [
                    'required_capability' => 'nexus_cooperative_cancellation',
                    'call_kind' => 'service_operation',
                    'sequence' => $sequence,
                    'service_call_id' => $payload['service_call_id'] ?? null,
                    'binding_kind' => $call?->resolved_binding_kind,
                    'unavailable_reason' => $reason,
                ];
            }
        }

        return null;
    }

    public static function prepare(WorkflowRun $caller, WorkflowTask $task, int $sequence): bool
    {
        $context = CooperativeCancellationDelivery::context($caller);
        $call = self::callAt($caller, $sequence);
        if ($call === null) {
            // Cancellation wins before admission. No target is dispatched.
            return false;
        }
        /** @var WorkflowRun|null $target */
        $target = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->where('namespace', $call->target_namespace)
            ->where('workflow_instance_id', $call->linked_workflow_instance_id)
            ->find($call->linked_workflow_run_id);
        if ($target === null
            && $call->status === ServiceCallStatus::Cancelled->value
            && $call->outcome_reason === 'caller_cancellation_requested') {
            return false;
        }
        if ($context === null || $target === null) {
            throw new LogicException('nexus_cancellation_owner_unavailable');
        }
        $invalid = ServiceCallCancellationOrigin::validateOwnership($call, $caller, $target)
            ?? ServiceCallCancellationOrigin::validateTargetCreation($call);
        if ($invalid !== null) {
            throw new LogicException($invalid);
        }
        $terminal = ChildRunHistory::terminalEventForRun($target);
        if ($terminal !== null) {
            LinkedServiceCallCloser::reconcile($call);
            if ($target->status->isTerminal() && in_array($call->status, ['completed', 'failed', 'cancelled'], true)) {
                return false;
            }
        }
        if (! $target->status->isTerminal()) {
            $request = WorkflowStub::loadRun($target->id)->attemptRequestCancellationFromServiceCall($call->id);
            if ($request->rejected()) {
                throw new LogicException('nexus_cancellation_target_request_refused:' . $request->rejectionReason());
            }
            $target->refresh();
        }
        $targetContext = CooperativeCancellationDelivery::context($target);
        if ($targetContext === null || $targetContext->rootRequestId !== $context->rootRequestId
            || $targetContext->deadline()
                ->toISOString() !== $context->deadline()
                ->toISOString()) {
            throw new LogicException('nexus_cancellation_target_context_mismatch');
        }
        $call->refresh();
        if ($target->status->isTerminal() && in_array($call->status, ['completed', 'failed', 'cancelled'], true)) {
            return false;
        }
        $task->payload = [
            ...$task->payload,
            'cancellation_service_wait' => [
                'request_id' => $context->requestId,
                'root_request_id' => $context->rootRequestId,
                'cleanup_deadline_at' => $context->deadline()
                    ->toISOString(),
                'sequence' => $sequence,
                'service_call_id' => $call->id,
                'target_workflow_instance_id' => $target->workflow_instance_id,
                'target_workflow_run_id' => $target->id,
            ],
        ];

        return true;
    }

    public static function validateBoundary(WorkflowRun $run, int $sequence, string $kind): ?string
    {
        if (! $run->relationLoaded('tasks')) {
            if (! $run->exists) {
                return null;
            }
            $run->loadMissing('tasks');
        }
        foreach ($run->tasks as $task) {
            $wait = $task->payload['cancellation_service_wait'] ?? null;
            if ($task->status !== TaskStatus::Completed || ! is_array($wait)
                || ($wait['request_id'] ?? null) !== $run->cancellation_request_command_id) {
                continue;
            }
            $context = CooperativeCancellationDelivery::context($run);
            if ($context === null || ($wait['root_request_id'] ?? null) !== $context->rootRequestId
                || ($wait['cleanup_deadline_at'] ?? null) !== $context->deadline()->toISOString()) {
                return 'cancellation_wait_context_mismatch';
            }
            if ($kind !== 'service_operation' || ($wait['sequence'] ?? null) !== $sequence) {
                return 'cancellation_wait_boundary_mismatch';
            }
            $call = self::callAt($run, $sequence);
            if ($call === null || ($wait['service_call_id'] ?? null) !== $call->id
                || ($wait['target_workflow_instance_id'] ?? null) !== $call->linked_workflow_instance_id
                || ($wait['target_workflow_run_id'] ?? null) !== $call->linked_workflow_run_id) {
                return 'cancellation_wait_target_mismatch';
            }
        }

        return null;
    }

    private static function callAt(WorkflowRun $run, int $sequence): ?WorkflowServiceCall
    {
        if (! $run->relationLoaded('historyEvents')) {
            $run->loadMissing('historyEvents');
        }
        $started = $run->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
            in_array($event->event_type, [
                HistoryEventType::ServiceOperationScheduled,
                HistoryEventType::ServiceCallStarted,
            ], true)
            && ($event->payload['sequence'] ?? null) === $sequence);
        if ($started === null) {
            return null;
        }
        /** @var WorkflowServiceCall|null $call */
        $call = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
            ->where('caller_namespace', $run->namespace)
            ->where('caller_workflow_instance_id', $run->workflow_instance_id)
            ->where('caller_workflow_run_id', $run->id)
            ->find($started->payload['service_call_id'] ?? null);
        if ($call === null) {
            throw new LogicException('nexus_cancellation_original_admission_missing');
        }

        return $call;
    }

    private static function waitsForCompletion(WorkflowHistoryEvent $event): bool
    {
        if ($event->event_type === HistoryEventType::ServiceOperationScheduled) {
            return ($event->payload['workflow_resume_on_admission'] ?? null) === false;
        }

        return ($event->payload['wait_for'] ?? null) === 'completed';
    }
}
