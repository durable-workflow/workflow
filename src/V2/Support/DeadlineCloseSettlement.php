<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * The cascade after a bounded deadline close's terminal event (#1116): the
 * accepted updates it fails and the children its parent-close policy acts
 * on. Each transaction takes at most one batch, updates first and then
 * children, in the order a single close takes them.
 *
 * While work is left, the run already reads Failed and its closing workflow
 * task is held: Leased by {@see self::OWNER} with no lease expiry, so no
 * worker can claim it and task repair never resets it, and its payload says
 * {@see self::PENDING}. The watchdog finds held tasks among the open
 * (Ready/Leased) tasks, a set that does not grow with retained runs, and
 * continues the settlement. When nothing is left, the task is Completed and
 * its payload says {@see self::SETTLED}.
 */
final class DeadlineCloseSettlement
{
    public const OWNER = 'deadline-close-settlement';

    public const MARKER = 'deadline_close_settlement';

    public const PENDING = 'pending';

    public const SETTLED = 'settled';

    public static function holds(WorkflowTask $task): bool
    {
        return $task->status === TaskStatus::Leased && $task->lease_owner === self::OWNER;
    }

    public static function hold(WorkflowTask $task): void
    {
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'leased_at' => $task->leased_at ?? now(),
            'lease_owner' => self::OWNER,
            'lease_expires_at' => null,
            'payload' => [
                ...(is_array($task->payload) ? $task->payload : []),
                self::MARKER => self::PENDING,
            ],
        ])->save();
    }

    /**
     * Settle at most $budget updates and children. Returns true when nothing
     * is left, completing the held task; otherwise the task stays held.
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

        if (! $settled) {
            if (! self::holds($task)) {
                self::hold($task);
            }

            return false;
        }

        $task->forceFill([
            'status' => TaskStatus::Completed,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'payload' => [
                ...(is_array($task->payload) ? $task->payload : []),
                self::MARKER => self::SETTLED,
            ],
        ])->save();

        return true;
    }
}
