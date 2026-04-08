<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;

final class UpdateCommandGate
{
    public const BLOCKED_BY_PENDING_SIGNAL = 'earlier_signal_pending';

    public static function blockedReason(WorkflowRun $run): ?string
    {
        return self::blockingSignal($run) instanceof WorkflowCommand
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
}
