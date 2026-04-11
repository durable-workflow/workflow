<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowRun;

final class CommandContractBackfillSweep
{
    public const CURSOR_CACHE_KEY = 'workflow:v2:command-contract-backfill:cursor';

    /**
     * @param list<string> $runIds
     * @return array{
     *     selected_candidates: int,
     *     backfilled: int,
     *     unavailable: int,
     *     failures: list<array{run_id: string, message: string}>
     * }
     */
    public static function run(array $runIds = [], ?string $instanceId = null, ?int $limit = null): array
    {
        $report = [
            'selected_candidates' => 0,
            'backfilled' => 0,
            'unavailable' => 0,
            'failures' => [],
        ];

        foreach (self::runs($runIds, $instanceId, $limit) as $run) {
            try {
                $state = RunCommandContract::historyBackfillState($run);

                if (! $state['needed']) {
                    continue;
                }

                $report['selected_candidates']++;

                if (! $state['available']) {
                    $report['unavailable']++;

                    continue;
                }

                if (RunCommandContract::backfillHistory($run)) {
                    $report['backfilled']++;
                }
            } catch (Throwable $throwable) {
                $report['failures'][] = [
                    'run_id' => $run->id,
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        return $report;
    }

    /**
     * @param list<string> $runIds
     * @return Collection<int, WorkflowRun>
     */
    private static function runs(array $runIds, ?string $instanceId, ?int $limit): Collection
    {
        if ($runIds !== [] || $instanceId !== null) {
            return self::runQuery($runIds, $instanceId)
                ->orderBy('id')
                ->get();
        }

        $limit = max(1, $limit ?? TaskRepairPolicy::scanLimit());
        $cursor = self::cursor();
        $runs = self::cursorQuery($cursor, $limit)->get();

        if ($runs->isEmpty() && $cursor !== null) {
            self::resetCursor();
            $runs = self::cursorQuery(null, $limit)->get();
        }

        if ($runs->isNotEmpty()) {
            self::storeCursor($runs->last()->id);
        }

        return $runs;
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
                    $query->select(['id', 'workflow_run_id', 'event_type', 'payload', 'sequence'])
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

    private static function cursorQuery(?string $cursor, int $limit)
    {
        $query = self::runQuery([], null)
            ->orderBy('id')
            ->limit($limit);

        if ($cursor !== null) {
            $query->where('id', '>', $cursor);
        }

        return $query;
    }

    private static function cursor(): ?string
    {
        $cursor = Cache::get(self::CURSOR_CACHE_KEY);

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    private static function storeCursor(string $cursor): void
    {
        Cache::forever(self::CURSOR_CACHE_KEY, $cursor);
    }

    private static function resetCursor(): void
    {
        Cache::forget(self::CURSOR_CACHE_KEY);
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
