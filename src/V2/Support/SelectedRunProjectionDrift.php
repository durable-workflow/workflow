<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowRun;

final class SelectedRunProjectionDrift
{
    /**
     * @param list<string> $runIds
     * @return array{
     *     runs_with_waits: int,
     *     projected_runs_with_waits: int,
     *     missing_runs_with_waits: int,
     *     stale_projected_runs: int
     * }
     */
    public static function waitMetrics(array $runIds = [], ?string $instanceId = null): array
    {
        $analysis = self::analyze(
            self::runQuery([
                'summary',
                'waits',
                'historyEvents',
                'commands',
                'tasks',
                'activityExecutions.attempts',
                'timers',
                'childLinks.childRun.summary',
                'childLinks.childRun.failures',
                'childLinks.childRun.historyEvents',
            ], $runIds, $instanceId),
            static fn (WorkflowRun $run): array => RunWaitProjector::driftStatusForRun($run),
        );

        return [
            'runs_with_waits' => $analysis['runs_with_canonical'],
            'projected_runs_with_waits' => $analysis['projected_runs_with_canonical'],
            'missing_runs_with_waits' => count($analysis['missing_run_ids']),
            'stale_projected_runs' => count($analysis['stale_run_ids']),
        ];
    }

    /**
     * @param list<string> $runIds
     * @return list<string>
     */
    public static function waitRunIdsNeedingRebuild(array $runIds = [], ?string $instanceId = null): array
    {
        return self::runIdsNeedingRebuild(
            self::analyze(
                self::runQuery([
                    'summary',
                    'waits',
                    'historyEvents',
                    'commands',
                    'tasks',
                    'activityExecutions.attempts',
                    'timers',
                    'childLinks.childRun.summary',
                    'childLinks.childRun.failures',
                    'childLinks.childRun.historyEvents',
                ], $runIds, $instanceId),
                static fn (WorkflowRun $run): array => RunWaitProjector::driftStatusForRun($run),
            ),
        );
    }

    /**
     * @param list<string> $runIds
     * @return array{
     *     runs_with_history: int,
     *     projected_runs_with_history: int,
     *     missing_runs_with_history: int,
     *     stale_projected_runs: int
     * }
     */
    public static function timelineMetrics(array $runIds = [], ?string $instanceId = null): array
    {
        $analysis = self::analyze(
            self::runQuery([
                'timelineEntries',
                'historyEvents',
                'commands',
                'tasks',
                'activityExecutions',
                'timers',
                'failures',
            ], $runIds, $instanceId),
            static fn (WorkflowRun $run): array => RunTimelineProjector::driftStatusForRun($run),
        );

        return [
            'runs_with_history' => $analysis['runs_with_canonical'],
            'projected_runs_with_history' => $analysis['projected_runs_with_canonical'],
            'missing_runs_with_history' => count($analysis['missing_run_ids']),
            'stale_projected_runs' => count($analysis['stale_run_ids']),
        ];
    }

    /**
     * @param list<string> $runIds
     * @return list<string>
     */
    public static function timelineRunIdsNeedingRebuild(array $runIds = [], ?string $instanceId = null): array
    {
        return self::runIdsNeedingRebuild(
            self::analyze(
                self::runQuery([
                    'timelineEntries',
                    'historyEvents',
                    'commands',
                    'tasks',
                    'activityExecutions',
                    'timers',
                    'failures',
                ], $runIds, $instanceId),
                static fn (WorkflowRun $run): array => RunTimelineProjector::driftStatusForRun($run),
            ),
        );
    }

    /**
     * @param list<string> $runIds
     * @return array{
     *     runs_with_lineage: int,
     *     projected_runs_with_lineage: int,
     *     missing_runs_with_lineage: int,
     *     stale_projected_runs: int
     * }
     */
    public static function lineageMetrics(array $runIds = [], ?string $instanceId = null): array
    {
        $analysis = self::analyze(
            self::runQuery([
                'lineageEntries',
                'historyEvents',
                'parentLinks.parentRun.summary',
                'childLinks.childRun.summary',
                'childLinks.childRun.instance.currentRun.summary',
                'instance.runs.summary',
            ], $runIds, $instanceId),
            static fn (WorkflowRun $run): array => RunLineageProjector::driftStatusForRun($run),
        );

        return [
            'runs_with_lineage' => $analysis['runs_with_canonical'],
            'projected_runs_with_lineage' => $analysis['projected_runs_with_canonical'],
            'missing_runs_with_lineage' => count($analysis['missing_run_ids']),
            'stale_projected_runs' => count($analysis['stale_run_ids']),
        ];
    }

    /**
     * @param list<string> $runIds
     * @return list<string>
     */
    public static function lineageRunIdsNeedingRebuild(array $runIds = [], ?string $instanceId = null): array
    {
        return self::runIdsNeedingRebuild(
            self::analyze(
                self::runQuery([
                    'lineageEntries',
                    'historyEvents',
                    'parentLinks.parentRun.summary',
                    'childLinks.childRun.summary',
                    'childLinks.childRun.instance.currentRun.summary',
                    'instance.runs.summary',
                ], $runIds, $instanceId),
                static fn (WorkflowRun $run): array => RunLineageProjector::driftStatusForRun($run),
            ),
        );
    }

    /**
     * @param callable(WorkflowRun): array{has_projection: bool, has_canonical: bool, missing: bool, stale: bool} $resolver
     * @return array{
     *     runs_with_canonical: int,
     *     projected_runs_with_canonical: int,
     *     missing_run_ids: list<string>,
     *     stale_run_ids: list<string>
     * }
     */
    private static function analyze($query, callable $resolver): array
    {
        $runsWithCanonical = 0;
        $projectedRunsWithCanonical = 0;
        $missingRunIds = [];
        $staleRunIds = [];

        $query->chunkById(100, static function ($runs) use (
            &$missingRunIds,
            &$projectedRunsWithCanonical,
            &$runsWithCanonical,
            &$staleRunIds,
            $resolver,
        ): void {
            foreach ($runs as $run) {
                $status = $resolver($run);

                if ($status['has_canonical']) {
                    $runsWithCanonical++;
                }

                if ($status['has_canonical'] && $status['has_projection']) {
                    $projectedRunsWithCanonical++;
                }

                if ($status['missing']) {
                    $missingRunIds[] = $run->id;
                }

                if ($status['stale']) {
                    $staleRunIds[] = $run->id;
                }
            }
        }, 'id');

        return [
            'runs_with_canonical' => $runsWithCanonical,
            'projected_runs_with_canonical' => $projectedRunsWithCanonical,
            'missing_run_ids' => array_values(array_unique($missingRunIds)),
            'stale_run_ids' => array_values(array_unique($staleRunIds)),
        ];
    }

    /**
     * @param list<string> $runIds
     */
    private static function runQuery(array $relations, array $runIds, ?string $instanceId)
    {
        $runModel = self::runModel();
        $query = $runModel::query()->with($relations);

        if ($runIds !== []) {
            $query->whereKey($runIds);
        }

        if ($instanceId !== null) {
            $query->where('workflow_instance_id', $instanceId);
        }

        return $query;
    }

    /**
     * @param array{
     *     missing_run_ids: list<string>,
     *     stale_run_ids: list<string>
     * } $analysis
     * @return list<string>
     */
    private static function runIdsNeedingRebuild(array $analysis): array
    {
        return array_values(array_unique(array_merge(
            $analysis['missing_run_ids'],
            $analysis['stale_run_ids'],
        )));
    }

    /**
     * @return class-string<WorkflowRun>
     */
    private static function runModel(): string
    {
        /** @var class-string<WorkflowRun> $model */
        $model = config('workflows.v2.run_model', WorkflowRun::class);

        return $model;
    }
}
