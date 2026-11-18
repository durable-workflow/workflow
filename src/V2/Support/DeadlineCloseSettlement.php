<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * The cascade after a bounded deadline close's terminal event (#1116): the
 * accepted updates it fails and the children its parent-close policy acts
 * on. Each transaction takes at most one batch, updates first and then
 * children, in the order a single close takes them. While work is left, the
 * closing workflow task (already Completed) carries a pending marker, and the
 * watchdog continues from it.
 */
final class DeadlineCloseSettlement
{
    public const MARKER = 'deadline_close_settlement';

    public const PENDING = 'pending';

    public const SETTLED = 'settled';

    /**
     * Settle at most $budget updates and children. Returns true when nothing
     * is left; otherwise marks $task so the watchdog continues.
     */
    public static function settle(WorkflowRun $run, WorkflowTask $task, int $budget): bool
    {
        $budget -= PendingUpdateCloser::closeForTerminalRun($run, $task, $budget);
        $settled = false;
        if (! PendingUpdateCloser::hasAccepted($run)) {
            $childrenSettled = true;
            ParentClosePolicyEnforcer::enforce($run, max(0, $budget), $childrenSettled);
            $settled = $childrenSettled;
        }

        $marked = ($task->payload[self::MARKER] ?? null) !== null;
        if (! $settled || $marked) {
            $task->forceFill([
                'payload' => [
                    ...(is_array($task->payload) ? $task->payload : []),
                    self::MARKER => $settled ? self::SETTLED : self::PENDING,
                ],
            ])->save();
        }

        return $settled;
    }
}
