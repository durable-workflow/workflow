<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Model;
use Workflow\V2\Contracts\HistoryProjectionMaintenanceRole;
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

final class DefaultHistoryProjectionRole implements HistoryProjectionRole, HistoryProjectionMaintenanceRole
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

    public function pruneStaleProjections(array $runIds = [], ?string $instanceId = null, bool $dryRun = false): array
    {
        $staleSummaryQuery = $this->staleSummaryQuery($runIds, $instanceId);
        $staleWaitQuery = $this->staleWaitQuery($runIds, $instanceId);
        $waitTable = $this->tableFor($this->runWaitModel());
        $staleTimelineQuery = $this->staleTimelineQuery($runIds, $instanceId);
        $timelineTable = $this->tableFor($this->runTimelineEntryModel());
        $staleTimerQuery = $this->staleTimerQuery($runIds, $instanceId);
        $timerTable = $this->tableFor($this->runTimerEntryModel());
        $staleLineageQuery = $this->staleLineageQuery($runIds, $instanceId);
        $lineageTable = $this->tableFor($this->runLineageEntryModel());

        if ($dryRun) {
            return [
                'run_summaries' => (clone $staleSummaryQuery)->count(),
                'run_waits' => (clone $staleWaitQuery)->count(sprintf('%s.id', $waitTable)),
                'run_timeline_entries' => (clone $staleTimelineQuery)->count(sprintf('%s.id', $timelineTable)),
                'run_timer_entries' => (clone $staleTimerQuery)->count(sprintf('%s.id', $timerTable)),
                'run_lineage_entries' => (clone $staleLineageQuery)->count(sprintf('%s.id', $lineageTable)),
            ];
        }

        return [
            'run_summaries' => $staleSummaryQuery->delete(),
            'run_waits' => $this->deleteProjectionRows($staleWaitQuery, $this->runWaitModel(), $waitTable),
            'run_timeline_entries' => $this->deleteProjectionRows(
                $staleTimelineQuery,
                $this->runTimelineEntryModel(),
                $timelineTable,
            ),
            'run_timer_entries' => $this->deleteProjectionRows(
                $staleTimerQuery,
                $this->runTimerEntryModel(),
                $timerTable,
            ),
            'run_lineage_entries' => $this->deleteProjectionRows(
                $staleLineageQuery,
                $this->runLineageEntryModel(),
                $lineageTable,
            ),
        ];
    }

    /**
     * @param list<string> $runIds
     */
    private function staleSummaryQuery(array $runIds, ?string $instanceId)
    {
        $query = $this->summaryModel()::query()
            ->whereNotIn('id', $this->runModel()::query()->select('id'));

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
        $runTable = $this->tableFor($this->runModel());
        $waitTable = $this->tableFor($this->runWaitModel());

        $query = $this->runWaitModel()::query()
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
        $runTable = $this->tableFor($this->runModel());
        $historyTable = $this->tableFor($this->historyEventModel());
        $timelineTable = $this->tableFor($this->runTimelineEntryModel());

        $query = $this->runTimelineEntryModel()::query()
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
        $runTable = $this->tableFor($this->runModel());
        $timerTable = $this->tableFor($this->runTimerEntryModel());

        $query = $this->runTimerEntryModel()::query()
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
        $runTable = $this->tableFor($this->runModel());
        $lineageTable = $this->tableFor($this->runLineageEntryModel());

        $query = $this->runLineageEntryModel()::query()
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
     * @param class-string<Model> $model
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
        $model = ConfiguredV2Models::resolve('run_model', WorkflowRun::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowRunSummary>
     */
    private function summaryModel(): string
    {
        /** @var class-string<WorkflowRunSummary> $model */
        $model = ConfiguredV2Models::resolve('run_summary_model', WorkflowRunSummary::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowHistoryEvent>
     */
    private function historyEventModel(): string
    {
        /** @var class-string<WorkflowHistoryEvent> $model */
        $model = ConfiguredV2Models::resolve('history_event_model', WorkflowHistoryEvent::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowRunWait>
     */
    private function runWaitModel(): string
    {
        /** @var class-string<WorkflowRunWait> $model */
        $model = ConfiguredV2Models::resolve('run_wait_model', WorkflowRunWait::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowTimelineEntry>
     */
    private function runTimelineEntryModel(): string
    {
        /** @var class-string<WorkflowTimelineEntry> $model */
        $model = ConfiguredV2Models::resolve('run_timeline_entry_model', WorkflowTimelineEntry::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowRunTimerEntry>
     */
    private function runTimerEntryModel(): string
    {
        /** @var class-string<WorkflowRunTimerEntry> $model */
        $model = ConfiguredV2Models::resolve('run_timer_entry_model', WorkflowRunTimerEntry::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowRunLineageEntry>
     */
    private function runLineageEntryModel(): string
    {
        /** @var class-string<WorkflowRunLineageEntry> $model */
        $model = ConfiguredV2Models::resolve('run_lineage_entry_model', WorkflowRunLineageEntry::class);

        return $model;
    }

    /**
     * @param class-string<Model> $model
     */
    private function tableFor(string $model): string
    {
        return (new $model())->getTable();
    }
}
