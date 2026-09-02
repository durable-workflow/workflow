<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * Scopes one newly recorded child resolution around the configured history
 * role. The default role can apply the event incrementally, while custom roles
 * retain ownership of every projection dispatch.
 */
final class ChildResolutionProjectionContext
{
    /**
     * @var array<string, list<array{task: WorkflowTask, event: WorkflowHistoryEvent}>>
     */
    private static array $projectionsByRunId = [];

    /**
     * @template TResult
     * @param callable(): TResult $project
     * @return TResult
     */
    public static function run(
        WorkflowRun $run,
        WorkflowTask $task,
        WorkflowHistoryEvent $event,
        callable $project,
    ): mixed {
        $runId = (string) $run->getKey();
        self::$projectionsByRunId[$runId] ??= [];
        self::$projectionsByRunId[$runId][] = [
            'task' => $task,
            'event' => $event,
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

    /**
     * @return array{task: WorkflowTask, event: WorkflowHistoryEvent}|null
     */
    public static function projectionFor(WorkflowRun $run): ?array
    {
        $projections = self::$projectionsByRunId[(string) $run->getKey()] ?? [];
        $projection = end($projections);

        return is_array($projection) ? $projection : null;
    }
}
