<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class UpdateCommandGate
{
    public const BLOCKED_BY_PENDING_SIGNAL = 'earlier_signal_pending';

    public static function blockedReason(WorkflowRun $run): ?string
    {
        return self::blockingSignal($run) instanceof WorkflowCommand
            && ! (self::drainableSignal($run) instanceof WorkflowCommand)
            ? self::BLOCKED_BY_PENDING_SIGNAL
            : null;
    }

    public static function blockingSignal(WorkflowRun $run): ?WorkflowCommand
    {
        $run->loadMissing('commands');

        /** @var WorkflowCommand|null $command */
        $command = $run->commands
            ->filter(
                static fn (WorkflowCommand $command): bool => $command->command_type === CommandType::Signal
                    && $command->status === CommandStatus::Accepted
                    && $command->applied_at === null
            )
            ->sort(static function (WorkflowCommand $left, WorkflowCommand $right): int {
                $leftSequence = $left->command_sequence ?? PHP_INT_MAX;
                $rightSequence = $right->command_sequence ?? PHP_INT_MAX;

                if ($leftSequence !== $rightSequence) {
                    return $leftSequence <=> $rightSequence;
                }

                $leftCreatedAt = $left->created_at?->getTimestampMs() ?? PHP_INT_MAX;
                $rightCreatedAt = $right->created_at?->getTimestampMs() ?? PHP_INT_MAX;

                if ($leftCreatedAt !== $rightCreatedAt) {
                    return $leftCreatedAt <=> $rightCreatedAt;
                }

                return $left->id <=> $right->id;
            })
            ->first();

        return $command;
    }

    public static function drainableSignal(WorkflowRun $run): ?WorkflowCommand
    {
        $signal = self::blockingSignal($run);

        if (! $signal instanceof WorkflowCommand) {
            return null;
        }

        return self::readyWorkflowTask($run) instanceof WorkflowTask
            ? $signal
            : null;
    }

    public static function readyWorkflowTask(WorkflowRun $run): ?WorkflowTask
    {
        $run->loadMissing('tasks');

        /** @var WorkflowTask|null $task */
        $task = $run->tasks
            ->filter(
                static fn (WorkflowTask $task): bool => $task->task_type === TaskType::Workflow
                    && $task->status === TaskStatus::Ready
            )
            ->sort(static function (WorkflowTask $left, WorkflowTask $right): int {
                $leftAvailableAt = $left->available_at?->getTimestampMs() ?? PHP_INT_MAX;
                $rightAvailableAt = $right->available_at?->getTimestampMs() ?? PHP_INT_MAX;

                if ($leftAvailableAt !== $rightAvailableAt) {
                    return $leftAvailableAt <=> $rightAvailableAt;
                }

                $leftCreatedAt = $left->created_at?->getTimestampMs() ?? PHP_INT_MAX;
                $rightCreatedAt = $right->created_at?->getTimestampMs() ?? PHP_INT_MAX;

                if ($leftCreatedAt !== $rightCreatedAt) {
                    return $leftCreatedAt <=> $rightCreatedAt;
                }

                return $left->id <=> $right->id;
            })
            ->first();

        return $task;
    }
}
