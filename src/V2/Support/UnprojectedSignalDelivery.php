<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;

final class UnprojectedSignalDelivery
{
    /**
     * @param array<string, mixed>|null $contract
     */
    public static function allows(WorkflowRun $run, WorkflowSignal $signal, ?array $contract = null): bool
    {
        if (WorkflowExecutionGate::blockedReason($run)
            !== WorkflowExecutionGate::BLOCKED_WORKFLOW_DEFINITION_UNAVAILABLE) {
            return false;
        }
        $contract ??= RunCommandContract::forRun($run);
        if (($contract['source'] ?? null) === RunCommandContract::SOURCE_DURABLE_HISTORY) {
            $declaredSignals = $contract['signals'] ?? [];
            return is_array($declaredSignals) && in_array($signal->signal_name, $declaredSignals, true);
        }

        return self::hasAcceptedReceipt($run, $signal);
    }

    private static function hasAcceptedReceipt(WorkflowRun $run, WorkflowSignal $signal): bool
    {
        // A run may predate worker registration. Missing declarations stay
        // unknown; a prior accepted command and its history authorize only
        // delivery of this exact already-admitted signal.
        if ($signal->workflow_run_id !== $run->id
            || $signal->workflow_instance_id !== $run->workflow_instance_id
            || $signal->status !== SignalStatus::Received
            || $signal->closed_at !== null) {
            return false;
        }
        $accepted = ConfiguredV2Models::query('command_model', WorkflowCommand::class)
            ->whereKey($signal->workflow_command_id)
            ->where('workflow_run_id', $run->id)
            ->where('workflow_instance_id', $run->workflow_instance_id)
            ->where('command_type', CommandType::Signal->value)
            ->where('status', CommandStatus::Accepted->value)
            ->exists();
        if (! $accepted) {
            return false;
        }

        return ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
            ->where('workflow_run_id', $run->id)
            ->where('workflow_command_id', $signal->workflow_command_id)
            ->where('event_type', HistoryEventType::SignalReceived->value)
            ->get()
            ->contains(
                static fn (WorkflowHistoryEvent $event): bool => ($event->payload['signal_id'] ?? null) === $signal->id
                && ($event->payload['signal_name'] ?? null) === $signal->signal_name
            );
    }
}
