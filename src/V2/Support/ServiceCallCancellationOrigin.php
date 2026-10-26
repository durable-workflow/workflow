<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\CancellationContext;
use Workflow\V2\CommandResult;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowServiceCall;

/** @internal Resolve authority from a recorded owned call, never caller-supplied context. */
final class ServiceCallCancellationOrigin
{
    public static function forTarget(string $serviceCallId, WorkflowRun $target): CancellationContext
    {
        /** @var WorkflowServiceCall|null $call */
        $call = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
            ->lockForUpdate()
            ->find($serviceCallId);
        /** @var WorkflowRun|null $caller */
        $caller = $call === null ? null : ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->find($call->caller_workflow_run_id);
        if ($call === null || $caller === null) {
            throw new LogicException('nexus_cancellation_owner_unavailable');
        }
        $failure = self::validateOwnership($call, $caller, $target) ?? self::validateTargetCreation($call);
        if ($failure !== null) {
            throw new LogicException($failure);
        }
        /** @var WorkflowCommand|null $command */
        $command = is_string($caller->cancellation_request_command_id)
            ? WorkflowCommand::query()->find($caller->cancellation_request_command_id) : null;
        $context = $command instanceof WorkflowCommand ? (new CommandResult($command))->cancellationContext() : null;
        $entry = $context === null ? null : $context->lineage[count($context->lineage) - 1];
        if ($command === null || $context === null || $command->status !== CommandStatus::Accepted
            || $command->command_type !== CommandType::RequestCancellation
            || $command->workflow_run_id !== $caller->id
            || $command->workflow_instance_id !== $caller->workflow_instance_id
            || $context->requestId !== $caller->cancellation_request_command_id
            || $entry['workflow_run_id'] !== $caller->id
            || $entry['workflow_instance_id'] !== $caller->workflow_instance_id
            || CooperativeCancellationDelivery::context($caller)?->toArray() !== $context->toArray()) {
            throw new LogicException('nexus_cancellation_context_mismatch');
        }
        if (in_array($target->id, array_column($context->lineage, 'workflow_run_id'), true)) {
            throw new LogicException('nexus_cancellation_lineage_cycle');
        }

        return $context;
    }

    public static function validateOwnership(
        WorkflowServiceCall $call,
        WorkflowRun $caller,
        WorkflowRun $target
    ): ?string {
        if ($call->resolved_binding_kind !== ServiceCallBindingKind::WorkflowRun->value
            || ($call->cancellation_policy['allow_cancel'] ?? null) !== true
            || ($call->cancellation_policy['propagate_to_linked_workflow'] ?? null) !== true) {
            return 'nexus_cancellation_binding_or_policy_not_supported';
        }
        if ($call->caller_namespace !== $caller->namespace
            || $call->caller_workflow_instance_id !== $caller->workflow_instance_id
            || $call->caller_workflow_run_id !== $caller->id
            || $call->target_namespace !== $target->namespace
            || $call->linked_workflow_instance_id !== $target->workflow_instance_id
            || $call->linked_workflow_run_id !== $target->id) {
            return 'nexus_cancellation_owner_target_mismatch';
        }
        if (! $caller->relationLoaded('historyEvents')) {
            $caller->loadMissing('historyEvents');
        }
        $started = $caller->historyEvents->first(static fn (WorkflowHistoryEvent $event): bool =>
            in_array($event->event_type, [
                HistoryEventType::ServiceOperationScheduled,
                HistoryEventType::ServiceCallStarted,
            ], true)
            && $event->workflow_run_id === $caller->id
            && ($event->payload['service_call_id'] ?? null) === $call->id);
        if (! $started instanceof WorkflowHistoryEvent
            || ! is_int($started->payload['sequence'] ?? null)
            || $started->payload['sequence'] < 1
            || ($started->payload['operation_mode'] ?? null) !== 'sync'
            || ! self::waitsForCompletion($started)) {
            return 'nexus_cancellation_original_admission_missing';
        }

        return null;
    }

    public static function validateTargetCreation(WorkflowServiceCall $call): ?string
    {
        $start = $call->metadata['control_plane'] ?? null;
        if (! is_array($start) || ($start['started'] ?? null) !== true
            || ($start['outcome'] ?? null) !== 'started_new'
            || ($start['workflow_instance_id'] ?? null) !== $call->linked_workflow_instance_id
            || ($start['workflow_run_id'] ?? null) !== $call->linked_workflow_run_id) {
            return 'nexus_cancellation_target_not_created_by_call';
        }

        return null;
    }

    private static function waitsForCompletion(WorkflowHistoryEvent $event): bool
    {
        if ($event->event_type === HistoryEventType::ServiceOperationScheduled) {
            return ($event->payload['workflow_resume_on_admission'] ?? null) === false;
        }

        return ($event->payload['wait_for'] ?? null) === 'completed';
    }
}
