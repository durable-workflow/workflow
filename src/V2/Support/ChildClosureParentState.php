<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
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
        'childLinks.childRun.summary',
        'childLinks.childRun.instance.currentRun',
        'childLinks.childRun.failures',
    ];

    public static function hydrate(WorkflowRun $parentRun): void
    {
        $parentRun->loadMissing(['historyEvents', 'childLinks']);
    }

    /**
     * Resolve the member while the parent lock is held. The selected history
     * and links are only visible to the operations for this one sequence.
     *
     * @param Closure(): WorkflowHistoryEvent $record
     * @return array{WorkflowHistoryEvent, list<array{
     *     parallel_group_id: string, parallel_group_kind: string, parallel_group_base_sequence: int,
     *     parallel_group_size: int, parallel_group_index: int
     * }>}
     */
    public static function recordResolution(
        WorkflowRun $parentRun,
        int $sequence,
        WorkflowRun $childRun,
        bool $defaultProjection,
        Closure $record,
    ): array {
        $events = $defaultProjection && ! $parentRun->relationLoaded('historyEvents')
            && ! $parentRun->relationLoaded('childLinks')
            && ChildRunHistory::resolvedStatus(null, $childRun) === RunStatus::Completed
            ? WorkflowHistoryEvent::lockedForStep($parentRun, $sequence) : null;

        if ($events === null) {
            self::hydrate($parentRun);
        } else {
            $links = $parentRun->childLinks()
                ->where('sequence', $sequence)
                ->get();
            $parentRun->setRelation('historyEvents', $events);
            $parentRun->setRelation('childLinks', $links);
        }

        try {
            WorkflowStepHistory::assertCompatible($parentRun, $sequence, WorkflowStepHistory::CHILD_WORKFLOW);
            WorkflowStepHistory::assertTypedHistoryRecorded($parentRun, $sequence, WorkflowStepHistory::CHILD_WORKFLOW);

            // Persist our outcome before the next parent-lock holder checks the barrier.
            $event = $record();

            return [$event, ChildRunHistory::parallelGroupPathForSequence($parentRun, $sequence)];
        } finally {
            if ($events !== null) {
                $parentRun->unsetRelation('historyEvents');
                $parentRun->unsetRelation('childLinks');
            }
        }
    }
}
