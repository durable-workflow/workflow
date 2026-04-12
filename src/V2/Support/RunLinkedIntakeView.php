<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Collection;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;

final class RunLinkedIntakeView
{
    public const SOURCE = 'workflow_commands.context.intake';

    /**
     * @return list<array<string, mixed>>
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing('commands');

        /** @var Collection<string, Collection<int, WorkflowCommand>> $grouped */
        $grouped = $run->commands
            ->filter(static function (WorkflowCommand $command): bool {
                return $command->intakeGroupId() !== null && $command->intakeMode() !== null;
            })
            ->sort(
                static fn (WorkflowCommand $left, WorkflowCommand $right): int => self::compareCommands($left, $right)
            )
            ->groupBy(static fn (WorkflowCommand $command): string => (string) $command->intakeGroupId());

        return $grouped
            ->map(static fn (Collection $commands, string $groupId): array => self::group($groupId, $commands))
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, WorkflowCommand> $commands
     *
     * @return array<string, mixed>
     */
    private static function group(string $groupId, Collection $commands): array
    {
        $commands = $commands->values();
        $mode = (string) $commands->firstOrFail()
            ->intakeMode();
        $commandTypes = $commands
            ->map(static fn (WorkflowCommand $command): string => $command->command_type->value)
            ->values()
            ->all();
        $expectedTypes = self::expectedCommandTypes($mode);
        $missingExpectedTypes = array_values(array_filter(
            $expectedTypes,
            static fn (string $type): bool => ! in_array($type, $commandTypes, true),
        ));
        $startCommand = $commands->first(
            static fn (WorkflowCommand $command): bool => $command->command_type === CommandType::Start
        );
        $primaryCommand = $commands->first(
            static fn (WorkflowCommand $command): bool => $command->command_type !== CommandType::Start
        ) ?? $startCommand;

        return [
            'group_id' => $groupId,
            'mode' => $mode,
            'source' => self::SOURCE,
            'complete' => $missingExpectedTypes === [],
            'missing_expected_command_types' => $missingExpectedTypes,
            'command_count' => $commands->count(),
            'command_ids' => $commands->pluck('id')
                ->values()
                ->all(),
            'command_sequences' => $commands
                ->pluck('command_sequence')
                ->filter(static fn (mixed $sequence): bool => is_int($sequence))
                ->values()
                ->all(),
            'start_command_id' => $startCommand?->id,
            'start_command_sequence' => $startCommand?->command_sequence,
            'start_command_status' => $startCommand?->status?->value,
            'start_outcome' => $startCommand?->outcome?->value,
            'primary_command_id' => $primaryCommand?->id,
            'primary_command_sequence' => $primaryCommand?->command_sequence,
            'primary_command_type' => $primaryCommand?->command_type->value,
            'primary_command_status' => $primaryCommand?->status?->value,
            'primary_outcome' => $primaryCommand?->outcome?->value,
            'commands' => $commands
                ->map(static fn (WorkflowCommand $command): array => self::command($command))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function command(WorkflowCommand $command): array
    {
        return [
            'id' => $command->id,
            'sequence' => $command->command_sequence,
            'type' => $command->command_type->value,
            'status' => $command->status->value,
            'outcome' => $command->outcome?->value,
            'target_scope' => $command->target_scope,
            'requested_run_id' => $command->requestedRunId(),
            'resolved_run_id' => $command->resolvedRunId(),
            'target_name' => $command->targetName(),
        ];
    }

    /**
     * @return list<string>
     */
    private static function expectedCommandTypes(string $mode): array
    {
        return match ($mode) {
            'signal_with_start' => [CommandType::Start->value, CommandType::Signal->value],
            default => [],
        };
    }

    private static function compareCommands(WorkflowCommand $left, WorkflowCommand $right): int
    {
        $sequenceComparison = ($left->command_sequence ?? PHP_INT_MAX) <=> ($right->command_sequence ?? PHP_INT_MAX);

        if ($sequenceComparison !== 0) {
            return $sequenceComparison;
        }

        return $left->id <=> $right->id;
    }
}
