<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowRun;

/**
 * The parent state a closing child reads while it holds the parent-run lock.
 * The embedded executor and the service-mode bridge share it.
 *
 * Closing one child needs the parent's own history and its child links. A
 * sibling's run, failures or history are read only when that sibling's
 * sequence is consulted, and a sibling's outcome is read from the parent's
 * resolution event or the sibling's terminal event, so closing one child of a
 * wide fan-out does not reload every sibling's history.
 */
final class ChildClosureParentState
{
    /**
     * Relations hydrated for the parent projection after a child closes.
     *
     * @var list<string>
     */
    public const PROJECTION_RELATIONS = [
        'instance',
        'tasks',
        'activityExecutions',
        'timers',
        'failures',
        'historyEvents',
        'childLinks.childRun.instance.currentRun',
        'childLinks.childRun.failures',
    ];

    public static function hydrate(WorkflowRun $parentRun): void
    {
        $parentRun->loadMissing(['historyEvents', 'childLinks']);
    }
}
