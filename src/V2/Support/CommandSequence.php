<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use Workflow\V2\Models\WorkflowRun;

final class CommandSequence
{
    public static function reserveNext(WorkflowRun $run): int
    {
        /** @var WorkflowRun $lockedRun */
        $lockedRun = WorkflowRun::query()
            ->lockForUpdate()
            ->findOrFail($run->id);

        $current = self::ensureBackfilled($lockedRun);
        $next = $current + 1;

        $lockedRun->forceFill([
            'last_command_sequence' => $next,
        ])->save();

        return $next;
    }

    private static function ensureBackfilled(WorkflowRun $run): int
    {
        $commandQuery = DB::table('workflow_commands')
            ->where('workflow_run_id', $run->id);

        $maxSequence = (int) ($commandQuery->max('command_sequence') ?? 0);
        $hasLegacySequenceGaps = (clone $commandQuery)
            ->whereNull('command_sequence')
            ->exists();

        if ($hasLegacySequenceGaps) {
            return self::backfill($run);
        }

        if ($maxSequence > (int) $run->last_command_sequence) {
            $run->forceFill([
                'last_command_sequence' => $maxSequence,
            ])->save();

            return $maxSequence;
        }

        return (int) $run->last_command_sequence;
    }

    private static function backfill(WorkflowRun $run): int
    {
        $sequence = 0;
        $commands = DB::table('workflow_commands')
            ->select(['id', 'command_sequence'])
            ->where('workflow_run_id', $run->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($commands as $command) {
            ++$sequence;

            if ((int) ($command->command_sequence ?? 0) === $sequence) {
                continue;
            }

            DB::table('workflow_commands')
                ->where('id', $command->id)
                ->update([
                    'command_sequence' => $sequence,
                ]);
        }

        $run->forceFill([
            'last_command_sequence' => $sequence,
        ])->save();

        return $sequence;
    }
}
