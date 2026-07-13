<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * Scopes the successful-claim projection hint around the configured history
 * role call. The context is keyed by run ID so a custom role may refresh the
 * model before delegating without losing the bounded projection path.
 */
final class WorkflowTaskClaimProjectionContext
{
    /** @var array<string, list<WorkflowTask>> */
    private static array $tasksByRunId = [];

    /**
     * @template TResult
     * @param callable(): TResult $project
     * @return TResult
     */
    public static function run(
        WorkflowRun $run,
        WorkflowTask $task,
        callable $project,
    ): mixed {
        $runId = (string) $run->getKey();
        self::$tasksByRunId[$runId] ??= [];
        self::$tasksByRunId[$runId][] = $task;

        try {
            return $project();
        } finally {
            array_pop(self::$tasksByRunId[$runId]);

            if (self::$tasksByRunId[$runId] === []) {
                unset(self::$tasksByRunId[$runId]);
            }
        }
    }

    public static function taskFor(WorkflowRun $run): ?WorkflowTask
    {
        $tasks = self::$tasksByRunId[(string) $run->getKey()] ?? [];
        $task = end($tasks);

        return $task instanceof WorkflowTask ? $task : null;
    }
}
