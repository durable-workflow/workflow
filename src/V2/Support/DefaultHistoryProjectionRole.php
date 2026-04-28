<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;

final class DefaultHistoryProjectionRole implements HistoryProjectionRole
{
    public function projectRun(WorkflowRun $run): WorkflowRunSummary
    {
        return RunSummaryProjector::project($run);
    }

    public function recordActivityStarted(
        WorkflowRun $run,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        WorkflowTask $task,
    ): WorkflowRunSummary {
        $parallelMetadataPath = ParallelChildGroup::metadataPathForSequence($run, (int) $execution->sequence);
        $parallelMetadata = ParallelChildGroup::payloadForPath($parallelMetadataPath);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityStarted, array_merge([
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attempt->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'attempt_number' => $attempt->attempt_number,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ], $parallelMetadata ?? []), $task);

        LifecycleEventDispatcher::activityStarted(
            $run,
            (string) $execution->id,
            (string) ($execution->activity_type ?? $execution->activity_class),
            (string) $execution->activity_class,
            (int) $execution->sequence,
            (int) $attempt->attempt_number,
        );

        return $this->projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']) ?? $run);
    }

    /**
     * @param list<string> $runIds
     * @return array{
     *     run_summaries_pruned: int,
     *     run_summaries_would_prune: int,
     *     run_waits_pruned: int,
     *     run_waits_would_prune: int,
     *     run_timeline_entries_pruned: int,
     *     run_timeline_entries_would_prune: int,
     *     run_timer_entries_pruned: int,
     *     run_timer_entries_would_prune: int,
     *     run_lineage_entries_pruned: int,
     *     run_lineage_entries_would_prune: int
     * }
     */
    public function pruneStaleProjections(
        array $runIds = [],
        ?string $instanceId = null,
        bool $dryRun = false,
    ): array {
        $staleSummaryQuery = $this->staleSummaryQuery($runIds, $instanceId);
        $staleSummaryCount = (clone $staleSummaryQuery)->count();

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
            return [
                'run_summaries_pruned' => 0,
                'run_summaries_would_prune' => $staleSummaryCount,
                'run_waits_pruned' => 0,
                'run_waits_would_prune' => $staleWaitCount,
                'run_timeline_entries_pruned' => 0,
                'run_timeline_entries_would_prune' => $staleTimelineCount,
                'run_timer_entries_pruned' => 0,
                'run_timer_entries_would_prune' => $staleTimerCount,
                'run_lineage_entries_pruned' => 0,
                'run_lineage_entries_would_prune' => $staleLineageCount,
            ];
        }

        return [
            'run_summaries_pruned' => $staleSummaryQuery->delete(),
            'run_summaries_would_prune' => 0,
            'run_waits_pruned' => $this->deleteProjectionRows($staleWaitQuery, $this->runWaitModel(), $waitTable),
            'run_waits_would_prune' => 0,
            'run_timeline_entries_pruned' => $this->deleteProjectionRows(
                $staleTimelineQuery,
                $this->runTimelineEntryModel(),
                $timelineTable,
            ),
            'run_timeline_entries_would_prune' => 0,
            'run_timer_entries_pruned' => $this->deleteProjectionRows(
                $staleTimerQuery,
                $this->runTimerEntryModel(),
                $timerTable,
            ),
            'run_timer_entries_would_prune' => 0,
            'run_lineage_entries_pruned' => $this->deleteProjectionRows(
                $staleLineageQuery,
                $this->runLineageEntryModel(),
                $lineageTable,
            ),
            'run_lineage_entries_would_prune' => 0,
        ];
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
}
