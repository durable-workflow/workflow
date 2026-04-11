<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRun;

final class RepairBlockedReason
{
    public static function forRun(
        WorkflowRun $run,
        bool $isCurrentRun,
        ?string $livenessState,
        bool $hasReplayBlockedTask,
    ): ?string {
        if (! $isCurrentRun) {
            return 'selected_run_not_current';
        }

        if (self::isClosed($run)) {
            return 'run_closed';
        }

        if ($livenessState === 'repair_needed') {
            return null;
        }

        if ($livenessState === 'workflow_replay_blocked') {
            return $hasReplayBlockedTask
                ? null
                : 'unsupported_history';
        }

        if (is_string($livenessState) && str_contains($livenessState, 'waiting_for_compatible_worker')) {
            return 'waiting_for_compatible_worker';
        }

        return 'repair_not_needed';
    }

    private static function isClosed(WorkflowRun $run): bool
    {
        return in_array($run->status, [
            RunStatus::Completed,
            RunStatus::Failed,
            RunStatus::Cancelled,
            RunStatus::Terminated,
        ], true);
    }
}
