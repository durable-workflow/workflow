<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * Scopes the successful-claim projection hint around the configured history
 * role call. The context is keyed by run ID so a custom role may refresh the
 * model before delegating without losing the bounded projection path.
 */
final class WorkflowTaskClaimProjectionContext
{
    /**
     * @var array<string, list<array{
     *     task: WorkflowTask,
     *     child_resolution_events: list<WorkflowHistoryEvent>
     * }>>
     */
    private static array $projectionsByRunId = [];

    /**
     * @template TResult
     * @param callable(): TResult $project
     * @param list<WorkflowHistoryEvent> $childResolutionEvents
     * @return TResult
     */
    public static function run(
        WorkflowRun $run,
        WorkflowTask $task,
        callable $project,
        array $childResolutionEvents = [],
    ): mixed {
        $runId = (string) $run->getKey();
        self::$projectionsByRunId[$runId] ??= [];
        self::$projectionsByRunId[$runId][] = [
            'task' => $task,
            'child_resolution_events' => $childResolutionEvents,
        ];

        try {
            return $project();
        } finally {
            array_pop(self::$projectionsByRunId[$runId]);

            if (self::$projectionsByRunId[$runId] === []) {
                unset(self::$projectionsByRunId[$runId]);
            }
        }
    }

    public static function taskFor(WorkflowRun $run): ?WorkflowTask
    {
        $projections = self::$projectionsByRunId[(string) $run->getKey()] ?? [];
        $projection = end($projections);
        $task = is_array($projection) ? ($projection['task'] ?? null) : null;

        return $task instanceof WorkflowTask ? $task : null;
    }

    /**
     * @return list<WorkflowHistoryEvent>
     */
    public static function childResolutionEventsFor(WorkflowRun $run): array
    {
        $projections = self::$projectionsByRunId[(string) $run->getKey()] ?? [];
        $projection = end($projections);
        $events = is_array($projection) ? ($projection['child_resolution_events'] ?? []) : [];

        return array_values(array_filter(
            $events,
            static fn (mixed $event): bool => $event instanceof WorkflowHistoryEvent,
        ));
    }
}
