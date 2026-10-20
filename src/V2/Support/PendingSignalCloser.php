<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;

final class PendingSignalCloser
{
    public static function closeForTerminalEvent(
        WorkflowRun $run,
        WorkflowHistoryEvent $terminalEvent,
        ?WorkflowTask $task = null,
    ): void {
        if (! $run->status->isTerminal() || ! in_array($terminalEvent->event_type, [
            HistoryEventType::WorkflowCompleted,
            HistoryEventType::WorkflowContinuedAsNew,
            HistoryEventType::WorkflowFailed,
            HistoryEventType::WorkflowCancelled,
            HistoryEventType::WorkflowTerminated,
            HistoryEventType::WorkflowTimedOut,
        ], true)) {
            return;
        }

        $signals = ConfiguredV2Models::query('signal_model', WorkflowSignal::class)
            ->where('workflow_run_id', $run->id)
            ->where('status', SignalStatus::Received->value)
            ->orderByRaw('CASE WHEN command_sequence IS NULL THEN 1 ELSE 0 END')
            ->orderBy('command_sequence')
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($signals as $signal) {
            if (! $signal instanceof WorkflowSignal) {
                continue;
            }
            $closedAt = now();
            $reason = 'run_closed_before_signal_applied';
            $signal->forceFill([
                'status' => SignalStatus::Rejected->value,
                'outcome' => CommandOutcome::RejectedNotActive->value,
                'rejection_reason' => $reason,
                'rejected_at' => $closedAt,
                'closed_at' => $closedAt,
            ])->save();

            // Preserve the original command's acceptance receipt. This event
            // records the later disposition, without claiming application.
            WorkflowHistoryEvent::record($run, HistoryEventType::SignalRejected, [
                'workflow_command_id' => $signal->workflow_command_id,
                'signal_id' => $signal->id,
                'signal_name' => $signal->signal_name,
                'signal_wait_id' => $signal->signal_wait_id,
                'command_sequence' => $signal->command_sequence,
                'rejection_reason' => $reason,
                'terminal_reason' => $run->closed_reason ?? $run->status->value,
            ], $task, $signal->workflow_command_id);
        }
    }
}
