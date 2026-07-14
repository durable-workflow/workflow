<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowUpdate;

final class ContinuedRunUpdateHandoff
{
    public static function transferInstanceScoped(
        WorkflowRun $closingRun,
        WorkflowRun $continuedRun,
    ): void {
        $updates = ConfiguredV2Models::query('update_model', WorkflowUpdate::class)
            ->where('workflow_run_id', $closingRun->id)
            ->where('target_scope', 'instance')
            ->where('status', UpdateStatus::Accepted->value)
            ->whereNull('workflow_sequence')
            ->lockForUpdate()
            ->get();

        foreach ($updates as $update) {
            if (! $update instanceof WorkflowUpdate) {
                continue;
            }

            /** @var WorkflowCommand|null $command */
            $command = $update->workflow_command_id === null
                ? null
                : ConfiguredV2Models::query('command_model', WorkflowCommand::class)
                    ->lockForUpdate()
                    ->find($update->workflow_command_id);

            $update->forceFill([
                'workflow_run_id' => $continuedRun->id,
                'resolved_workflow_run_id' => $continuedRun->id,
            ])->save();

            if ($command instanceof WorkflowCommand
                && $command->command_type === CommandType::Update
                && $command->status === CommandStatus::Accepted
            ) {
                $command->forceFill([
                    'workflow_run_id' => $continuedRun->id,
                    'resolved_workflow_run_id' => $continuedRun->id,
                ])->save();
            }

            WorkflowHistoryEvent::record($continuedRun, HistoryEventType::UpdateAccepted, [
                'workflow_command_id' => $command?->id,
                'update_id' => $update->id,
                'workflow_instance_id' => $continuedRun->workflow_instance_id,
                'workflow_run_id' => $continuedRun->id,
                'update_name' => $update->update_name,
                'arguments' => $update->arguments,
            ], null, $command);
        }
    }
}
