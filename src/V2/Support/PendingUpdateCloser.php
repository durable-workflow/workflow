<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use RuntimeException;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;

final class PendingUpdateCloser
{
    public static function closeForTerminalRun(WorkflowRun $run, ?WorkflowTask $task = null): void
    {
        $updates = ConfiguredV2Models::query('update_model', WorkflowUpdate::class)
            ->where('workflow_run_id', $run->id)
            ->where('status', UpdateStatus::Accepted->value)
            ->orderByRaw('CASE WHEN command_sequence IS NULL THEN 1 ELSE 0 END')
            ->orderBy('command_sequence')
            ->orderBy('accepted_at')
            ->orderBy('id')
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
            $message = sprintf(
                'Workflow run closed as %s before accepted update %s could be applied.',
                $run->closed_reason ?? $run->status->value,
                $update->update_name,
            );
            $exceptionClass = RuntimeException::class;
            $failureCategory = FailureCategory::Application;

            /** @var WorkflowFailure $failure */
            $failure = ConfiguredV2Models::query('failure_model', WorkflowFailure::class)->create([
                'workflow_run_id' => $run->id,
                'source_kind' => $command instanceof WorkflowCommand ? 'workflow_command' : 'workflow_update',
                'source_id' => $command?->id ?? $update->id,
                'propagation_kind' => 'update',
                'failure_category' => $failureCategory->value,
                'non_retryable' => true,
                'handled' => false,
                'exception_class' => $exceptionClass,
                'message' => $message,
                'file' => '',
                'line' => 0,
                'trace_preview' => '',
            ]);

            WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted, [
                'workflow_command_id' => $command?->id,
                'update_id' => $update->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_run_id' => $run->id,
                'update_name' => $update->update_name,
                'failure_id' => $failure->id,
                'failure_category' => $failureCategory->value,
                'non_retryable' => true,
                'exception_class' => $exceptionClass,
                'message' => $message,
                'code' => 0,
                'exception' => [
                    'class' => $exceptionClass,
                    'message' => $message,
                    'code' => 0,
                    'file' => '',
                    'line' => 0,
                    'trace' => [],
                    'properties' => [],
                ],
                'terminal_reason' => $run->closed_reason ?? $run->status->value,
            ], $task, $command);

            $closedAt = now();

            $update->forceFill([
                'status' => UpdateStatus::Failed->value,
                'outcome' => CommandOutcome::UpdateFailed->value,
                'failure_id' => $failure->id,
                'failure_message' => $message,
                'closed_at' => $closedAt,
            ])->save();

            if ($command instanceof WorkflowCommand) {
                $command->forceFill([
                    'outcome' => CommandOutcome::UpdateFailed->value,
                    'applied_at' => $closedAt,
                ])->save();

                if ($command->message_sequence !== null) {
                    MessageStreamCursor::advanceCursor($run, (int) $command->message_sequence, $task);
                }
            }
        }
    }
}
