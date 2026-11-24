<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Exceptions\HistoryTransactionLimitExceededException;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;

final class PendingUpdateCloser
{
    /**
     * Fail every accepted update of a closed run, in acceptance order, or at
     * most $limit of them for a bounded deadline close (#1116), which
     * continues with the rest in a later transaction in the same order.
     *
     * @return int the updates closed
     */
    public static function closeForTerminalRun(WorkflowRun $run, ?WorkflowTask $task = null, ?int $limit = null): int
    {
        if ($limit !== null && $limit <= 0) {
            return 0;
        }
        $updates = self::acceptedQuery($run)
            ->when($limit !== null, static fn ($query) => $query->limit($limit))
            ->lockForUpdate()
            ->get();

        foreach ($updates as $update) {
            if (! $update instanceof WorkflowUpdate) {
                continue;
            }

            /** @var WorkflowCommand|null $command */
            $command = $update->workflow_command_id === null
                ? null
                : self::updateCommands($run)
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

        return $updates->count();
    }

    public static function hasAccepted(WorkflowRun $run): bool
    {
        return self::acceptedQuery($run)->exists();
    }

    /**
     * Check the history needed by an ordinary failure and its accepted updates
     * while the caller holds the run lock and before it starts the close.
     *
     * @internal
     */
    public static function guardFailureHistoryBudget(WorkflowRun $run): void
    {
        $eventLimit = StructuralLimits::historyTransactionSizeLimit();
        $rowLimit = StructuralLimits::historyTransactionRowsLimit();
        if (($eventLimit === 0 && $rowLimit === 0)
            || $run::class !== WorkflowRun::class
            || ConfiguredV2Models::resolve('update_model', WorkflowUpdate::class) !== WorkflowUpdate::class
            || ConfiguredV2Models::resolve('command_model', WorkflowCommand::class) !== WorkflowCommand::class
            || WorkflowUpdate::getEventDispatcher()?->hasListeners('eloquent.retrieved: ' . WorkflowUpdate::class)
            || WorkflowCommand::getEventDispatcher()?->hasListeners('eloquent.retrieved: ' . WorkflowCommand::class)) {
            return;
        }

        // Reserve the WorkflowFailed event. Each event charges two legacy history rows.
        $eventRoom = $eventLimit > 0 ? $eventLimit - 1 : PHP_INT_MAX;
        $rowRoom = $rowLimit > 0 ? intdiv($rowLimit - 2, 2) : PHP_INT_MAX;
        $room = min($eventRoom, $rowRoom);

        $commandIds = self::acceptedQuery($run)
            ->toBase()
            ->lockForUpdate()
            ->limit($room + 1)
            ->pluck('workflow_command_id');
        $events = $commandIds->count();
        if ($events <= intdiv($room, 2)) {
            // An outcome and at most one cursor event fit for every update.
            return;
        }
        if ($events <= $room) {
            $cursor = MessageStreamCursor::positionForRun($run);
            foreach ($commandIds->chunk(500) as $batch) {
                $sequences = self::updateCommands($run)
                    ->toBase()
                    ->lockForUpdate()
                    ->whereIn('id', $batch->filter()->unique()->values()->all())
                    ->pluck('message_sequence', 'id');

                foreach ($batch as $commandId) {
                    $sequence = $commandId === null ? null : $sequences->get($commandId);
                    if ($sequence !== null && (int) $sequence > $cursor) {
                        $cursor = (int) $sequence;
                        if (++$events > $room) {
                            break 2;
                        }
                    }
                }
            }
        }

        if ($events > $room) {
            $dimension = $eventRoom <= $rowRoom ? HistoryTransactionMeter::EVENTS : HistoryTransactionMeter::ROWS;
            $limit = $dimension === HistoryTransactionMeter::EVENTS ? $eventLimit : $rowLimit;

            throw HistoryTransactionLimitExceededException::over(
                $dimension,
                $limit < PHP_INT_MAX ? $limit + 1 : $limit,
                $limit,
            );
        }
    }

    /**
     * @return Builder<WorkflowCommand>
     */
    private static function updateCommands(WorkflowRun $run): Builder
    {
        return ConfiguredV2Models::query('command_model', WorkflowCommand::class)
            ->where('workflow_instance_id', $run->workflow_instance_id)
            ->where('workflow_run_id', $run->id)
            ->where('command_type', CommandType::Update->value);
    }

    private static function acceptedQuery(WorkflowRun $run)
    {
        return ConfiguredV2Models::query('update_model', WorkflowUpdate::class)
            ->where('workflow_run_id', $run->id)
            ->where('status', UpdateStatus::Accepted->value)
            ->orderByRaw('CASE WHEN command_sequence IS NULL THEN 1 ELSE 0 END')
            ->orderBy('command_sequence')
            ->orderBy('accepted_at')
            ->orderBy('id');
    }
}
