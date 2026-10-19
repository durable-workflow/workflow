<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;

final class ContinuedRunMessageHandoff
{
    public static function closePredecessor(
        WorkflowRun $closingRun,
        WorkflowRun $continuedRun,
        WorkflowTask $task,
        int $sequence,
        string $linkId,
        CarbonInterface $closedAt,
    ): void {
        self::transferInstanceScoped($closingRun, $continuedRun);
        $closingRun->forceFill([
            'status' => RunStatus::Completed,
            'closed_reason' => 'continued',
            'closed_at' => $closedAt,
            'last_progress_at' => $closedAt,
        ])->save();
        WorkflowHistoryEvent::record($closingRun, HistoryEventType::WorkflowContinuedAsNew, [
            'sequence' => $sequence,
            'continued_to_run_id' => $continuedRun->id,
            'continued_to_run_number' => $continuedRun->run_number,
            'workflow_link_id' => $linkId,
            'closed_reason' => 'continued',
        ], $task);
        PendingUpdateCloser::closeForTerminalRun($closingRun, $task);
    }

    public static function transferInstanceScoped(WorkflowRun $closingRun, WorkflowRun $continuedRun): void
    {
        $signals = ConfiguredV2Models::query('signal_model', WorkflowSignal::class)
            ->where('workflow_run_id', $closingRun->id)
            ->where('target_scope', 'instance')
            ->where('status', SignalStatus::Received->value)
            ->whereNull('closed_at')
            ->lockForUpdate()
            ->get();
        $updates = ConfiguredV2Models::query('update_model', WorkflowUpdate::class)
            ->where('workflow_run_id', $closingRun->id)
            ->where('target_scope', 'instance')
            ->where('status', UpdateStatus::Accepted->value)
            ->whereNull('workflow_sequence')
            ->lockForUpdate()
            ->get();

        $messages = $signals->toBase()
            ->concat($updates)
            ->sortBy(static fn ($message): array => [
                $message->command_sequence ?? PHP_INT_MAX,
                $message->created_at?->format('U.u') ?? '',
                $message->id,
            ]);
        foreach ($messages as $message) {
            if ($message instanceof WorkflowUpdate) {
                ContinuedRunUpdateHandoff::transfer($message, $continuedRun);
            } elseif ($message instanceof WorkflowSignal) {
                self::transferSignal($message, $continuedRun);
            }
        }
    }

    private static function transferSignal(WorkflowSignal $signal, WorkflowRun $continuedRun): void
    {
        /** @var WorkflowCommand|null $command */
        $command = ConfiguredV2Models::query('command_model', WorkflowCommand::class)
            ->whereKey($signal->workflow_command_id)
            ->lockForUpdate()
            ->first();
        if (! $command instanceof WorkflowCommand
            || $command->command_type !== CommandType::Signal
            || $command->status !== CommandStatus::Accepted
            || $command->target_scope !== 'instance'
            || $command->workflow_run_id !== $signal->workflow_run_id
            || $command->workflow_instance_id !== $signal->workflow_instance_id) {
            return;
        }

        $waitId = SignalWaits::bufferedWaitIdForCommandId($command->id);
        $signal->forceFill([
            'workflow_run_id' => $continuedRun->id,
            'resolved_workflow_run_id' => $continuedRun->id,
            'signal_wait_id' => $waitId,
        ])->save();
        $command->forceFill([
            'workflow_run_id' => $continuedRun->id,
            'resolved_workflow_run_id' => $continuedRun->id,
        ])->save();
        WorkflowHistoryEvent::record($continuedRun, HistoryEventType::SignalReceived, array_filter([
            'workflow_command_id' => $command->id,
            'signal_id' => $signal->id,
            'workflow_instance_id' => $continuedRun->workflow_instance_id,
            'workflow_run_id' => $continuedRun->id,
            'signal_name' => $signal->signal_name,
            'signal_wait_id' => $waitId,
            'arguments' => ExternalPayloads::historyValue(
                is_string($signal->arguments) ? $signal->arguments : null,
                is_string($signal->payload_codec) ? $signal->payload_codec : null,
                is_string($continuedRun->namespace) ? $continuedRun->namespace : null,
            ),
            'payload_codec' => $signal->payload_codec,
        ], static fn (mixed $value): bool => $value !== null), null, $command);
    }
}
