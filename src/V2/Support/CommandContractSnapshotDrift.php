<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowRun;

final class CommandContractSnapshotDrift
{
    /**
     * @param list<string> $runIds
     * @return array{
     *     backfill_needed_runs: int,
     *     backfill_available_runs: int,
     *     backfill_unavailable_runs: int
     * }
     */
    public static function metrics(array $runIds = [], ?string $instanceId = null): array
    {
        $analysis = self::analyze($runIds, $instanceId);

        return [
            'backfill_needed_runs' => count($analysis['needed_run_ids']),
            'backfill_available_runs' => count($analysis['available_run_ids']),
            'backfill_unavailable_runs' => count($analysis['unavailable_run_ids']),
        ];
    }

    /**
     * @param list<string> $runIds
     * @return list<string>
     */
    public static function runIdsNeedingBackfill(array $runIds = [], ?string $instanceId = null): array
    {
        return self::analyze($runIds, $instanceId)['needed_run_ids'];
    }

    /**
     * @param list<string> $runIds
     * @return array{
     *     needed_run_ids: list<string>,
     *     available_run_ids: list<string>,
     *     unavailable_run_ids: list<string>
     * }
     */
    private static function analyze(array $runIds, ?string $instanceId): array
    {
        $analysis = [
            'needed_run_ids' => [],
            'available_run_ids' => [],
            'unavailable_run_ids' => [],
        ];

        self::runQuery($runIds, $instanceId)->chunkById(100, static function ($runs) use (&$analysis): void {
            foreach ($runs as $run) {
                $state = RunCommandContract::historyBackfillState($run);

                if (! $state['needed']) {
                    continue;
                }

                $analysis['needed_run_ids'][] = $run->id;

                if ($state['available']) {
                    $analysis['available_run_ids'][] = $run->id;
                } else {
                    $analysis['unavailable_run_ids'][] = $run->id;
                }
            }
        });

        return $analysis;
    }

    /**
     * @param list<string> $runIds
     */
    private static function runQuery(array $runIds, ?string $instanceId)
    {
        $runModel = self::runModel();

        $query = $runModel::query()
            ->select(['id', 'workflow_instance_id', 'workflow_class', 'workflow_type'])
            ->with([
                'historyEvents' => static function ($query): void {
                    $query->select(['workflow_run_id', 'event_type', 'payload', 'sequence'])
                        ->where('event_type', HistoryEventType::WorkflowStarted->value)
                        ->orderBy('sequence');
                },
            ]);

        if ($runIds !== []) {
            $query->whereKey($runIds);
        }

        if ($instanceId !== null) {
            $query->where('workflow_instance_id', $instanceId);
        }

        return $query;
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
