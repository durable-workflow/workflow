<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Support\RunSummaryProjectionDrift;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\SelectedRunProjectionDrift;

#[AsCommand(name: 'workflow:v2:rebuild-projections')]
class V2RebuildProjectionsCommand extends Command
{
    protected $signature = 'workflow:v2:rebuild-projections
        {--run-id=* : Rebuild one or more selected workflow run ids}
        {--instance-id= : Rebuild every run for one workflow instance id}
        {--missing : Only rebuild runs that do not have a run-summary row}
        {--needs-rebuild : Only rebuild runs whose summary, wait, timeline, timer, or lineage projections need rebuild}
        {--prune-stale : Delete projection rows whose durable workflow run or history row no longer exists}
        {--dry-run : Report the affected rows without changing projection tables}
        {--json : Output the rebuild report as JSON}';

    protected $description = 'Rebuild Workflow v2 projection rows from durable runtime state';

    public function handle(): int
    {
        $runIds = $this->runIds();
        $instanceId = $this->stringOption('instance-id');
        $missingOnly = (bool) $this->option('missing');
        $needsRebuildOnly = (bool) $this->option('needs-rebuild');
        $pruneStale = (bool) $this->option('prune-stale');
        $dryRun = (bool) $this->option('dry-run');

        $runQuery = $this->runQuery($runIds, $instanceId, $missingOnly, $needsRebuildOnly);
        $matchedRuns = (clone $runQuery)->count();

        $report = [
            'dry_run' => $dryRun,
            'runs_matched' => $matchedRuns,
            'run_summaries_rebuilt' => 0,
            'run_summaries_would_rebuild' => $dryRun ? $matchedRuns : 0,
            'run_summaries_pruned' => 0,
            'run_summaries_would_prune' => 0,
            'run_waits_pruned' => 0,
            'run_waits_would_prune' => 0,
            'run_timeline_entries_pruned' => 0,
            'run_timeline_entries_would_prune' => 0,
            'run_timer_entries_pruned' => 0,
            'run_timer_entries_would_prune' => 0,
            'run_lineage_entries_pruned' => 0,
            'run_lineage_entries_would_prune' => 0,
            'failures' => [],
        ];

        $runQuery->chunkById(100, static function ($runs) use (&$report, $dryRun): void {
            foreach ($runs as $run) {
                try {
                    if ($dryRun) {
                        continue;
                    }

                    RunSummaryProjector::project($run);
                    $report['run_summaries_rebuilt']++;
                } catch (Throwable $exception) {
                    $report['failures'][] = [
                        'run_id' => $run->id,
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        });

        if ($pruneStale) {
            $staleQuery = $this->staleSummaryQuery($runIds, $instanceId);
            $staleCount = (clone $staleQuery)->count();
            $staleWaitQuery = $this->staleWaitQuery($runIds, $instanceId);
            $waitTable = $this->tableFor($this->runWaitModel());
            $staleWaitCount = (clone $staleWaitQuery)->count(sprintf('%s.id', $waitTable));
            $staleTimelineQuery = $this->staleTimelineQuery($runIds, $instanceId);
            $timelineTable = $this->tableFor($this->runTimelineEntryModel());
            $staleTimelineCount = (clone $staleTimelineQuery)->count(sprintf('%s.id', $timelineTable));
            $staleTimerQuery = $this->staleTimerQuery($runIds, $instanceId);
            $timerTable = $this->tableFor($this->runTimerEntryModel());
            $staleTimerCount = (clone $staleTimerQuery)->count(sprintf('%s.id', $timerTable));
            $staleLineageQuery = $this->staleLineageQuery($runIds, $instanceId);
            $lineageTable = $this->tableFor($this->runLineageEntryModel());
            $staleLineageCount = (clone $staleLineageQuery)->count(sprintf('%s.id', $lineageTable));

            if ($dryRun) {
                $report['run_summaries_would_prune'] = $staleCount;
                $report['run_waits_would_prune'] = $staleWaitCount;
                $report['run_timeline_entries_would_prune'] = $staleTimelineCount;
                $report['run_timer_entries_would_prune'] = $staleTimerCount;
                $report['run_lineage_entries_would_prune'] = $staleLineageCount;
            } else {
                $report['run_summaries_pruned'] = $staleQuery->delete();
                $report['run_waits_pruned'] = $this->deleteProjectionRows(
                    $staleWaitQuery,
                    $this->runWaitModel(),
                    $waitTable,
                );
                $report['run_timeline_entries_pruned'] = $this->deleteProjectionRows(
                    $staleTimelineQuery,
                    $this->runTimelineEntryModel(),
                    $timelineTable,
                );
                $report['run_timer_entries_pruned'] = $this->deleteProjectionRows(
                    $staleTimerQuery,
                    $this->runTimerEntryModel(),
                    $timerTable,
                );
                $report['run_lineage_entries_pruned'] = $this->deleteProjectionRows(
                    $staleLineageQuery,
                    $this->runLineageEntryModel(),
                    $lineageTable,
                );
            }
        }

        $this->renderReport($report);

        return $report['failures'] === []
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param list<string> $runIds
     */
    private function runQuery(array $runIds, ?string $instanceId, bool $missingOnly, bool $needsRebuildOnly)
    {
        $runModel = $this->runModel();
        $summaryModel = $this->summaryModel();
        $runTable = (new $runModel())->getTable();
        $summaryTable = (new $summaryModel())->getTable();

        $query = $runModel::query()
            ->with([
                'instance.runs',
                'tasks',
                'activityExecutions',
                'timers',
                'failures',
                'historyEvents',
                'childLinks.childRun.instance.currentRun',
                'childLinks.childRun.failures',
            ]);

        if ($runIds !== []) {
            $query->whereKey($runIds);
        }

        if ($instanceId !== null) {
            $query->where('workflow_instance_id', $instanceId);
        }

        if ($needsRebuildOnly) {
            $staleSummaryIds = RunSummaryProjectionDrift::staleSummaryQuery($runIds, $instanceId)
                ->select(sprintf('%s.id', $summaryTable));
            $schemaOutdatedIds = RunSummaryProjectionDrift::schemaOutdatedQuery($runIds, $instanceId)
                ->select(sprintf('%s.id', $summaryTable));
            $selectedRunWaitIds = SelectedRunProjectionDrift::waitRunIdsNeedingRebuild($runIds, $instanceId);
            $selectedRunTimelineIds = SelectedRunProjectionDrift::timelineRunIdsNeedingRebuild($runIds, $instanceId);
            $selectedRunTimerIds = SelectedRunProjectionDrift::timerRunIdsNeedingRebuild($runIds, $instanceId);
            $selectedRunLineageIds = SelectedRunProjectionDrift::lineageRunIdsNeedingRebuild($runIds, $instanceId);

            $query->where(static function ($query) use (
                $selectedRunLineageIds,
                $selectedRunTimerIds,
                $selectedRunTimelineIds,
                $selectedRunWaitIds,
                $summaryModel,
                $staleSummaryIds,
                $schemaOutdatedIds,
                $runTable,
            ): void {
                $query->whereNotIn(sprintf('%s.id', $runTable), $summaryModel::query()->select('id'))
                    ->orWhereIn(sprintf('%s.id', $runTable), $staleSummaryIds)
                    ->orWhereIn(sprintf('%s.id', $runTable), $schemaOutdatedIds);

                if ($selectedRunWaitIds !== []) {
                    $query->orWhereIn(sprintf('%s.id', $runTable), $selectedRunWaitIds);
                }

                if ($selectedRunTimelineIds !== []) {
                    $query->orWhereIn(sprintf('%s.id', $runTable), $selectedRunTimelineIds);
                }

                if ($selectedRunTimerIds !== []) {
                    $query->orWhereIn(sprintf('%s.id', $runTable), $selectedRunTimerIds);
                }

                if ($selectedRunLineageIds !== []) {
                    $query->orWhereIn(sprintf('%s.id', $runTable), $selectedRunLineageIds);
                }
            });
        } elseif ($missingOnly) {
            $query->whereNotIn('id', $summaryModel::query()->select('id'));
        }

        return $query;
    }

    /**
     * @param list<string> $runIds
     */
    private function staleSummaryQuery(array $runIds, ?string $instanceId)
    {
        $runModel = $this->runModel();
        $summaryModel = $this->summaryModel();

        $query = $summaryModel::query()
            ->whereNotIn('id', $runModel::query()->select('id'));

        if ($runIds !== []) {
            $query->whereKey($runIds);
        }

        if ($instanceId !== null) {
            $query->where('workflow_instance_id', $instanceId);
        }

        return $query;
    }

    /**
     * @param list<string> $runIds
     */
    private function staleWaitQuery(array $runIds, ?string $instanceId)
    {
        $runModel = $this->runModel();
        $waitModel = $this->runWaitModel();
        $runTable = $this->tableFor($runModel);
        $waitTable = $this->tableFor($waitModel);

        $query = $waitModel::query()
            ->leftJoin($runTable, sprintf('%s.workflow_run_id', $waitTable), '=', sprintf('%s.id', $runTable))
            ->whereNull(sprintf('%s.id', $runTable));

        if ($runIds !== []) {
            $query->whereIn(sprintf('%s.workflow_run_id', $waitTable), $runIds);
        }

        if ($instanceId !== null) {
            $query->where(sprintf('%s.workflow_instance_id', $waitTable), $instanceId);
        }

        return $query;
    }

    /**
     * @param list<string> $runIds
     */
    private function staleTimelineQuery(array $runIds, ?string $instanceId)
    {
        $runModel = $this->runModel();
        $historyModel = $this->historyEventModel();
        $timelineModel = $this->runTimelineEntryModel();
        $runTable = $this->tableFor($runModel);
        $historyTable = $this->tableFor($historyModel);
        $timelineTable = $this->tableFor($timelineModel);

        $query = $timelineModel::query()
            ->leftJoin($runTable, sprintf('%s.workflow_run_id', $timelineTable), '=', sprintf('%s.id', $runTable))
            ->leftJoin($historyTable, static function ($join) use ($historyTable, $timelineTable): void {
                $join->on(
                    sprintf('%s.workflow_run_id', $historyTable),
                    '=',
                    sprintf('%s.workflow_run_id', $timelineTable),
                )->on(sprintf('%s.id', $historyTable), '=', sprintf('%s.history_event_id', $timelineTable));
            })
            ->where(static function ($query) use ($historyTable, $runTable): void {
                $query->whereNull(sprintf('%s.id', $runTable))
                    ->orWhereNull(sprintf('%s.id', $historyTable));
            });

        if ($runIds !== []) {
            $query->whereIn(sprintf('%s.workflow_run_id', $timelineTable), $runIds);
        }

        if ($instanceId !== null) {
            $query->where(sprintf('%s.workflow_instance_id', $timelineTable), $instanceId);
        }

        return $query;
    }

    /**
     * @param list<string> $runIds
     */
    private function staleTimerQuery(array $runIds, ?string $instanceId)
    {
        $runModel = $this->runModel();
        $timerModel = $this->runTimerEntryModel();
        $runTable = $this->tableFor($runModel);
        $timerTable = $this->tableFor($timerModel);

        $query = $timerModel::query()
            ->leftJoin($runTable, sprintf('%s.workflow_run_id', $timerTable), '=', sprintf('%s.id', $runTable))
            ->whereNull(sprintf('%s.id', $runTable));

        if ($runIds !== []) {
            $query->whereIn(sprintf('%s.workflow_run_id', $timerTable), $runIds);
        }

        if ($instanceId !== null) {
            $query->where(sprintf('%s.workflow_instance_id', $timerTable), $instanceId);
        }

        return $query;
    }

    /**
     * @param list<string> $runIds
     */
    private function staleLineageQuery(array $runIds, ?string $instanceId)
    {
        $runModel = $this->runModel();
        $lineageModel = $this->runLineageEntryModel();
        $runTable = $this->tableFor($runModel);
        $lineageTable = $this->tableFor($lineageModel);

        $query = $lineageModel::query()
            ->leftJoin($runTable, sprintf('%s.workflow_run_id', $lineageTable), '=', sprintf('%s.id', $runTable))
            ->whereNull(sprintf('%s.id', $runTable));

        if ($runIds !== []) {
            $query->whereIn(sprintf('%s.workflow_run_id', $lineageTable), $runIds);
        }

        if ($instanceId !== null) {
            $query->where(sprintf('%s.workflow_instance_id', $lineageTable), $instanceId);
        }

        return $query;
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model
     */
    private function deleteProjectionRows($query, string $model, string $table): int
    {
        $deleted = 0;

        $query->select(sprintf('%s.id', $table))
            ->chunkById(500, static function ($rows) use (&$deleted, $model): void {
                $ids = $rows->pluck('id')
                    ->all();

                if ($ids !== []) {
                    $deleted += $model::query()
                        ->whereKey($ids)
                        ->delete();
                }
            }, sprintf('%s.id', $table), 'id');

        return $deleted;
    }

    /**
     * @return class-string<WorkflowRun>
     */
    private function runModel(): string
    {
        /** @var class-string<WorkflowRun> $model */
        $model = config('workflows.v2.run_model', WorkflowRun::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowRunSummary>
     */
    private function summaryModel(): string
    {
        /** @var class-string<WorkflowRunSummary> $model */
        $model = config('workflows.v2.run_summary_model', WorkflowRunSummary::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowHistoryEvent>
     */
    private function historyEventModel(): string
    {
        /** @var class-string<WorkflowHistoryEvent> $model */
        $model = config('workflows.v2.history_event_model', WorkflowHistoryEvent::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowRunWait>
     */
    private function runWaitModel(): string
    {
        /** @var class-string<WorkflowRunWait> $model */
        $model = config('workflows.v2.run_wait_model', WorkflowRunWait::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowTimelineEntry>
     */
    private function runTimelineEntryModel(): string
    {
        /** @var class-string<WorkflowTimelineEntry> $model */
        $model = config('workflows.v2.run_timeline_entry_model', WorkflowTimelineEntry::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowRunTimerEntry>
     */
    private function runTimerEntryModel(): string
    {
        /** @var class-string<WorkflowRunTimerEntry> $model */
        $model = config('workflows.v2.run_timer_entry_model', WorkflowRunTimerEntry::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowRunLineageEntry>
     */
    private function runLineageEntryModel(): string
    {
        /** @var class-string<WorkflowRunLineageEntry> $model */
        $model = config('workflows.v2.run_lineage_entry_model', WorkflowRunLineageEntry::class);

        return $model;
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model
     */
    private function tableFor(string $model): string
    {
        return (new $model())->getTable();
    }

    /**
     * @return list<string>
     */
    private function runIds(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            (array) $this->option('run-id'),
        ))));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array{
     *     dry_run: bool,
     *     runs_matched: int,
     *     run_summaries_rebuilt: int,
     *     run_summaries_would_rebuild: int,
     *     run_summaries_pruned: int,
     *     run_summaries_would_prune: int,
     *     run_waits_pruned: int,
     *     run_waits_would_prune: int,
     *     run_timeline_entries_pruned: int,
     *     run_timeline_entries_would_prune: int,
     *     run_timer_entries_pruned: int,
     *     run_timer_entries_would_prune: int,
     *     run_lineage_entries_pruned: int,
     *     run_lineage_entries_would_prune: int,
     *     failures: list<array{run_id: string, message: string}>
     * } $report
     */
    private function renderReport(array $report): void
    {
        if ((bool) $this->option('json')) {
            try {
                $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } catch (JsonException $exception) {
                $this->error($exception->getMessage());
            }

            return;
        }

        if ($report['dry_run']) {
            $this->info(sprintf(
                'Would rebuild %d run-summary projection row(s).',
                $report['run_summaries_would_rebuild'],
            ));

            if ($report['run_summaries_would_prune'] > 0) {
                $this->info(sprintf(
                    'Would prune %d stale run-summary projection row(s).',
                    $report['run_summaries_would_prune'],
                ));
            }

            if ($report['run_waits_would_prune'] > 0) {
                $this->info(sprintf(
                    'Would prune %d stale wait projection row(s).',
                    $report['run_waits_would_prune'],
                ));
            }

            if ($report['run_timeline_entries_would_prune'] > 0) {
                $this->info(sprintf(
                    'Would prune %d stale timeline projection row(s).',
                    $report['run_timeline_entries_would_prune'],
                ));
            }

            if ($report['run_timer_entries_would_prune'] > 0) {
                $this->info(sprintf(
                    'Would prune %d stale timer projection row(s).',
                    $report['run_timer_entries_would_prune'],
                ));
            }

            if ($report['run_lineage_entries_would_prune'] > 0) {
                $this->info(sprintf(
                    'Would prune %d stale lineage projection row(s).',
                    $report['run_lineage_entries_would_prune'],
                ));
            }
        } else {
            $this->info(sprintf('Rebuilt %d run-summary projection row(s).', $report['run_summaries_rebuilt']));

            if ($report['run_summaries_pruned'] > 0) {
                $this->info(sprintf(
                    'Pruned %d stale run-summary projection row(s).',
                    $report['run_summaries_pruned'],
                ));
            }

            if ($report['run_waits_pruned'] > 0) {
                $this->info(sprintf('Pruned %d stale wait projection row(s).', $report['run_waits_pruned']));
            }

            if ($report['run_timeline_entries_pruned'] > 0) {
                $this->info(sprintf(
                    'Pruned %d stale timeline projection row(s).',
                    $report['run_timeline_entries_pruned'],
                ));
            }

            if ($report['run_timer_entries_pruned'] > 0) {
                $this->info(sprintf(
                    'Pruned %d stale timer projection row(s).',
                    $report['run_timer_entries_pruned'],
                ));
            }

            if ($report['run_lineage_entries_pruned'] > 0) {
                $this->info(sprintf(
                    'Pruned %d stale lineage projection row(s).',
                    $report['run_lineage_entries_pruned'],
                ));
            }
        }

        foreach ($report['failures'] as $failure) {
            $this->error(sprintf('Failed to rebuild run [%s]: %s', $failure['run_id'], $failure['message']));
        }
    }
}
