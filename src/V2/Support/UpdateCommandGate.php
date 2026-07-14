<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;

final class UpdateCommandGate
{
    public static function blockingSignal(
        WorkflowRun $run,
        ?int $beforeCommandSequence = null,
        ?string $ignoredCommandId = null,
    ): ?WorkflowCommand
    {
        /** @var WorkflowCommand|null $command */
        $query = ConfiguredV2Models::query('command_model', WorkflowCommand::class)
            ->where('workflow_run_id', $run->id)
            ->where('command_type', CommandType::Signal->value)
            ->where('status', CommandStatus::Accepted->value)
            ->whereNull('applied_at');

        if ($beforeCommandSequence !== null) {
            $query->where('command_sequence', '<', $beforeCommandSequence);
        }

        if ($ignoredCommandId !== null) {
            $query->where('id', '!=', $ignoredCommandId);
        }

        $command = $query
            ->orderByRaw('CASE WHEN command_sequence IS NULL THEN 1 ELSE 0 END')
            ->orderBy('command_sequence')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        return $command;
    }
}
