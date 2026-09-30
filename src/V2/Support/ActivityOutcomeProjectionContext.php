<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

/**
 * Keep the newly recorded activity resolution available while the configured
 * history role projects the run, including when a custom role refreshes it.
 */
final class ActivityOutcomeProjectionContext
{
    /**
     * @var array<string, list<WorkflowHistoryEvent>>
     */
    private static array $eventsByRunId = [];

    /**
     * @template TResult
     * @param callable(): TResult $project
     * @return TResult
     */
    public static function run(WorkflowRun $run, WorkflowHistoryEvent $event, callable $project): mixed
    {
        $runId = (string) $run->getKey();
        self::$eventsByRunId[$runId] ??= [];
        self::$eventsByRunId[$runId][] = $event;

        try {
            return $project();
        } finally {
            array_pop(self::$eventsByRunId[$runId]);

            if (self::$eventsByRunId[$runId] === []) {
                unset(self::$eventsByRunId[$runId]);
            }
        }
    }

    public static function eventFor(WorkflowRun $run): ?WorkflowHistoryEvent
    {
        $events = self::$eventsByRunId[(string) $run->getKey()] ?? [];
        $event = end($events);

        return $event instanceof WorkflowHistoryEvent ? $event : null;
    }
}
