<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

final class SelectedRunSnapshot
{
    /**
     * @return array{
     *     current_run: array{
     *         run: ?WorkflowRun,
     *         summary: ?WorkflowRunSummary,
     *         source: ?string
     *     },
     *     waits: array{source: string, waits: list<array<string, mixed>>},
     *     timeline: array{source: string, timeline: list<array<string, mixed>>},
     *     lineage: array{
     *         source: string,
     *         parents: list<array<string, mixed>>,
     *         continued_workflows: list<array<string, mixed>>
     *     }
     * }
     */
    public static function forRun(WorkflowRun $run): array
    {
        return [
            'current_run' => self::currentRun($run),
            'waits' => self::waits($run),
            'timeline' => self::timeline($run),
            'lineage' => self::lineage($run),
        ];
    }

    /**
     * @return array{
     *     run: ?WorkflowRun,
     *     summary: ?WorkflowRunSummary,
     *     source: ?string
     * }
     */
    public static function currentRun(WorkflowRun $run): array
    {
        $run->loadMissing(['summary', 'instance']);

        $resolution = CurrentRunResolver::resolutionForRun($run, ['summary']);
        $currentRun = $resolution['run'];

        return [
            'run' => $currentRun,
            'summary' => $currentRun?->summary,
            'source' => $resolution['source'],
        ];
    }

    /**
     * @return array{source: string, waits: list<array<string, mixed>>}
     */
    public static function waits(WorkflowRun $run): array
    {
        return RunWaitProjector::snapshotForRun($run);
    }

    /**
     * @return array{source: string, timeline: list<array<string, mixed>>}
     */
    public static function timeline(WorkflowRun $run): array
    {
        return RunTimelineProjector::snapshotForRun($run);
    }

    /**
     * @return array{
     *     source: string,
     *     parents: list<array<string, mixed>>,
     *     continued_workflows: list<array<string, mixed>>
     * }
     */
    public static function lineage(WorkflowRun $run): array
    {
        return RunLineageProjector::snapshotForRun($run);
    }

    /**
     * @return array{has_projection: bool, has_canonical: bool, missing: bool, stale: bool}
     */
    public static function waitDriftStatus(WorkflowRun $run): array
    {
        return RunWaitProjector::driftStatusForRun($run);
    }

    /**
     * @return array{has_projection: bool, has_canonical: bool, missing: bool, stale: bool}
     */
    public static function timelineDriftStatus(WorkflowRun $run): array
    {
        return RunTimelineProjector::driftStatusForRun($run);
    }

    /**
     * @return array{has_projection: bool, has_canonical: bool, missing: bool, stale: bool}
     */
    public static function lineageDriftStatus(WorkflowRun $run): array
    {
        return RunLineageProjector::driftStatusForRun($run);
    }
}
