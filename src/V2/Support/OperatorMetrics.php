<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;

final class OperatorMetrics
{
    /**
     * @return array<string, mixed>
     */
    public static function snapshot(?CarbonInterface $now = null): array
    {
        $now ??= now();

        return [
            'generated_at' => $now->toJSON(),
            'runs' => self::runMetrics(),
            'tasks' => self::taskMetrics($now),
            'activities' => self::activityMetrics(),
            'backlog' => self::backlogMetrics($now),
            'repair' => TaskRepairCandidates::snapshot($now),
            'starts' => self::startMetrics($now),
            'history' => self::historyMetrics(),
            'command_contracts' => self::commandContractMetrics(),
            'projections' => self::projectionMetrics(),
            'workers' => self::workerMetrics(),
            'backend' => BackendCapabilities::snapshot($now),
            'update_wait' => UpdateWaitPolicy::snapshot(),
            'repair_policy' => TaskRepairPolicy::snapshot(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function runMetrics(): array
    {
        $summaryModel = self::summaryModel();

        return [
            'total' => $summaryModel::query()->count(),
            'current' => $summaryModel::query()->where('is_current_run', true)->count(),
            'running' => $summaryModel::query()->where('status_bucket', 'running')->count(),
            'completed' => $summaryModel::query()->where('status', RunStatus::Completed->value)->count(),
            'failed' => $summaryModel::query()->where('status', RunStatus::Failed->value)->count(),
            'cancelled' => $summaryModel::query()->where('status', RunStatus::Cancelled->value)->count(),
            'terminated' => $summaryModel::query()->where('status', RunStatus::Terminated->value)->count(),
            'archived' => $summaryModel::query()->whereNotNull('archived_at')->count(),
            'repair_needed' => $summaryModel::query()->where('liveness_state', 'repair_needed')->count(),
            'claim_failed' => self::claimFailedRuns(),
            'compatibility_blocked' => self::compatibilityBlockedRuns(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function taskMetrics(CarbonInterface $now): array
    {
        return [
            'open' => self::openTasks(),
            'ready' => self::readyTasks(),
            'ready_due' => self::readyDueTasks($now),
            'delayed' => self::delayedTasks($now),
            'leased' => self::leasedTasks(),
            'dispatch_failed' => self::dispatchFailedTasks(),
            'claim_failed' => self::claimFailedTasks(),
            'dispatch_overdue' => self::dispatchOverdueTasks($now),
            'lease_expired' => self::leaseExpiredTasks($now),
            'unhealthy' => self::dispatchFailedTasks()
                + self::claimFailedTasks()
                + self::dispatchOverdueTasks($now)
                + self::leaseExpiredTasks($now),
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function backlogMetrics(CarbonInterface $now): array
    {
        return [
            'runnable_tasks' => self::readyDueTasks($now),
            'delayed_tasks' => self::delayedTasks($now),
            'leased_tasks' => self::leasedTasks(),
            'retrying_activities' => self::retryingActivities(),
            'unhealthy_tasks' => self::dispatchFailedTasks()
                + self::claimFailedTasks()
                + self::dispatchOverdueTasks($now)
                + self::leaseExpiredTasks($now),
            'repair_needed_runs' => self::summaryModel()::query()
                ->where('liveness_state', 'repair_needed')
                ->count(),
            'claim_failed_runs' => self::claimFailedRuns(),
            'compatibility_blocked_runs' => self::compatibilityBlockedRuns(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function activityMetrics(): array
    {
        return [
            'open' => self::activityExecutionModel()::query()
                ->whereIn('status', [ActivityStatus::Pending->value, ActivityStatus::Running->value])
                ->count(),
            'pending' => self::activityExecutionModel()::query()
                ->where('status', ActivityStatus::Pending->value)
                ->count(),
            'running' => self::activityExecutionModel()::query()
                ->where('status', ActivityStatus::Running->value)
                ->count(),
            'retrying' => self::retryingActivities(),
            'failed_attempts' => self::activityAttemptModel()::query()
                ->where('status', ActivityAttemptStatus::Failed->value)
                ->count(),
            'max_attempt_count' => (int) self::activityExecutionModel()::query()
                ->max('attempt_count'),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function startMetrics(CarbonInterface $now): array
    {
        $oldestPendingStartAt = self::oldestPendingStartAt();

        return [
            'pending_runs' => self::pendingStartRuns(),
            'pending_commands' => self::pendingStartCommands(),
            'ready_tasks' => self::readyPendingStartTasks($now),
            'oldest_pending_start_at' => $oldestPendingStartAt?->toJSON(),
            'max_pending_ms' => $oldestPendingStartAt === null
                ? 0
                : (int) $oldestPendingStartAt->diffInMilliseconds($now),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function workerMetrics(): array
    {
        $required = WorkerCompatibility::current();
        $snapshots = WorkerCompatibilityFleet::details($required);
        $workerIds = [];
        $supportingWorkerIds = [];

        foreach ($snapshots as $snapshot) {
            $workerId = is_string($snapshot['worker_id'] ?? null)
                ? $snapshot['worker_id']
                : null;

            if ($workerId === null) {
                continue;
            }

            $workerIds[$workerId] = true;

            if (($snapshot['supports_required'] ?? false) === true) {
                $supportingWorkerIds[$workerId] = true;
            }
        }

        return [
            'compatibility_namespace' => WorkerCompatibilityFleet::scopeNamespace(),
            'required_compatibility' => $required,
            'active_workers' => count($workerIds),
            'active_worker_scopes' => count($snapshots),
            'active_workers_supporting_required' => count($supportingWorkerIds),
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function historyMetrics(): array
    {
        $summaryModel = self::summaryModel();

        return [
            'continue_as_new_recommended_runs' => $summaryModel::query()
                ->where('continue_as_new_recommended', true)
                ->count(),
            'max_event_count' => (int) $summaryModel::query()->max('history_event_count'),
            'max_size_bytes' => (int) $summaryModel::query()->max('history_size_bytes'),
            'event_threshold' => HistoryBudget::eventThreshold(),
            'size_bytes_threshold' => HistoryBudget::sizeBytesThreshold(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function commandContractMetrics(): array
    {
        return CommandContractSnapshotDrift::metrics();
    }

    /**
     * @return array<string, array<string, int|string|null>>
     */
    private static function projectionMetrics(): array
    {
        $summaryModel = self::summaryModel();
        $runSummaries = RunSummaryProjectionDrift::metrics();

        return [
            'run_summaries' => [
                ...$runSummaries,
                'oldest_updated_at' => self::jsonTimestamp($summaryModel::query()->min('updated_at')),
                'newest_updated_at' => self::jsonTimestamp($summaryModel::query()->max('updated_at')),
            ],
            'run_waits' => self::runWaitProjectionMetrics(),
            'run_timeline_entries' => self::runTimelineProjectionMetrics(),
            'run_timer_entries' => self::runTimerProjectionMetrics(),
            'run_lineage_entries' => self::runLineageProjectionMetrics(),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function runWaitProjectionMetrics(): array
    {
        $waitModel = self::runWaitModel();
        $summariesWithOpenWaits = self::summariesWithOpenWaits();
        $missingCurrentOpenWaits = self::missingCurrentOpenWaitProjections();
        $drift = SelectedRunProjectionDrift::waitMetrics();
        $orphaned = self::projectionRowsMissingRun($waitModel);

        return [
            'runs' => self::runModel()::query()->count(),
            'rows' => $waitModel::query()->count(),
            'projected_runs' => $waitModel::query()->distinct()->count('workflow_run_id'),
            'runs_with_waits' => $drift['runs_with_waits'],
            'projected_runs_with_waits' => $drift['projected_runs_with_waits'],
            'missing_runs_with_waits' => $drift['missing_runs_with_waits'],
            'summaries_with_open_waits' => $summariesWithOpenWaits,
            'projected_current_open_waits' => max(0, $summariesWithOpenWaits - $missingCurrentOpenWaits),
            'missing_current_open_waits' => $missingCurrentOpenWaits,
            'stale_projected_runs' => $drift['stale_projected_runs'],
            'orphaned' => $orphaned,
            'needs_rebuild' => $drift['missing_runs_with_waits'] + $drift['stale_projected_runs'] + $orphaned,
            'oldest_updated_at' => self::jsonTimestamp($waitModel::query()->min('updated_at')),
            'newest_updated_at' => self::jsonTimestamp($waitModel::query()->max('updated_at')),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function runTimelineProjectionMetrics(): array
    {
        $timelineModel = self::runTimelineEntryModel();
        $missingHistoryEvents = self::missingTimelineEventProjections();
        $drift = SelectedRunProjectionDrift::timelineMetrics();
        $orphaned = self::orphanedTimelineRows();

        return [
            'runs' => self::runModel()::query()->count(),
            'history_events' => self::historyEventModel()::query()->count(),
            'rows' => $timelineModel::query()->count(),
            'projected_runs' => $timelineModel::query()->distinct()->count('workflow_run_id'),
            'runs_with_history' => $drift['runs_with_history'],
            'projected_runs_with_history' => $drift['projected_runs_with_history'],
            'missing_runs_with_history' => $drift['missing_runs_with_history'],
            'missing_history_events' => $missingHistoryEvents,
            'stale_projected_runs' => $drift['stale_projected_runs'],
            'orphaned' => $orphaned,
            'needs_rebuild' => $drift['missing_runs_with_history'] + $drift['stale_projected_runs'] + $orphaned,
            'oldest_updated_at' => self::jsonTimestamp($timelineModel::query()->min('updated_at')),
            'newest_updated_at' => self::jsonTimestamp($timelineModel::query()->max('updated_at')),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function runTimerProjectionMetrics(): array
    {
        $timerModel = self::runTimerEntryModel();
        $drift = SelectedRunProjectionDrift::timerMetrics();
        $orphaned = self::projectionRowsMissingRun($timerModel);

        return [
            'runs' => self::runModel()::query()->count(),
            'rows' => $timerModel::query()->count(),
            'projected_runs' => $timerModel::query()->distinct()->count('workflow_run_id'),
            'runs_with_timers' => $drift['runs_with_timers'],
            'projected_runs_with_timers' => $drift['projected_runs_with_timers'],
            'missing_runs_with_timers' => $drift['missing_runs_with_timers'],
            'stale_projected_runs' => $drift['stale_projected_runs'],
            'orphaned' => $orphaned,
            'needs_rebuild' => $drift['missing_runs_with_timers'] + $drift['stale_projected_runs'] + $orphaned,
            'oldest_updated_at' => self::jsonTimestamp($timerModel::query()->min('updated_at')),
            'newest_updated_at' => self::jsonTimestamp($timerModel::query()->max('updated_at')),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function runLineageProjectionMetrics(): array
    {
        $lineageModel = self::runLineageEntryModel();
        $drift = SelectedRunProjectionDrift::lineageMetrics();
        $orphaned = self::projectionRowsMissingRun($lineageModel);

        return [
            'runs' => self::runModel()::query()->count(),
            'rows' => $lineageModel::query()->count(),
            'projected_runs' => $lineageModel::query()->distinct()->count('workflow_run_id'),
            'runs_with_lineage' => $drift['runs_with_lineage'],
            'projected_runs_with_lineage' => $drift['projected_runs_with_lineage'],
            'missing_runs_with_lineage' => $drift['missing_runs_with_lineage'],
            'stale_projected_runs' => $drift['stale_projected_runs'],
            'orphaned' => $orphaned,
            'needs_rebuild' => $drift['missing_runs_with_lineage'] + $drift['stale_projected_runs'] + $orphaned,
            'oldest_updated_at' => self::jsonTimestamp($lineageModel::query()->min('updated_at')),
            'newest_updated_at' => self::jsonTimestamp($lineageModel::query()->max('updated_at')),
        ];
    }

    private static function summariesWithOpenWaits(): int
    {
        return self::summaryModel()::query()
            ->whereNotNull('open_wait_id')
            ->count();
    }

    private static function missingCurrentOpenWaitProjections(): int
    {
        $summaryModel = self::summaryModel();
        $waitModel = self::runWaitModel();
        $summaryTable = self::tableFor($summaryModel);
        $waitTable = self::tableFor($waitModel);

        return $summaryModel::query()
            ->leftJoin($waitTable, static function ($join) use ($summaryTable, $waitTable): void {
                $join->on($waitTable . '.workflow_run_id', '=', $summaryTable . '.id')
                    ->on($waitTable . '.wait_id', '=', $summaryTable . '.open_wait_id');
            })
            ->whereNotNull($summaryTable . '.open_wait_id')
            ->whereNull($waitTable . '.id')
            ->count($summaryTable . '.id');
    }

    /**
     * @param class-string<WorkflowRunWait|WorkflowTimelineEntry|WorkflowRunTimerEntry|WorkflowRunLineageEntry> $projectionModel
     */
    private static function projectionRowsMissingRun(string $projectionModel): int
    {
        $runModel = self::runModel();
        $projectionTable = self::tableFor($projectionModel);
        $runTable = self::tableFor($runModel);

        return $projectionModel::query()
            ->leftJoin($runTable, $projectionTable . '.workflow_run_id', '=', $runTable . '.id')
            ->whereNull($runTable . '.id')
            ->count($projectionTable . '.id');
    }

    private static function missingTimelineEventProjections(): int
    {
        $historyModel = self::historyEventModel();
        $timelineModel = self::runTimelineEntryModel();
        $historyTable = self::tableFor($historyModel);
        $timelineTable = self::tableFor($timelineModel);

        return $historyModel::query()
            ->leftJoin($timelineTable, static function ($join) use ($historyTable, $timelineTable): void {
                $join->on($timelineTable . '.workflow_run_id', '=', $historyTable . '.workflow_run_id')
                    ->on($timelineTable . '.history_event_id', '=', $historyTable . '.id');
            })
            ->whereNull($timelineTable . '.id')
            ->count($historyTable . '.id');
    }

    private static function orphanedTimelineRows(): int
    {
        $runModel = self::runModel();
        $historyModel = self::historyEventModel();
        $timelineModel = self::runTimelineEntryModel();
        $runTable = self::tableFor($runModel);
        $historyTable = self::tableFor($historyModel);
        $timelineTable = self::tableFor($timelineModel);

        return $timelineModel::query()
            ->leftJoin($runTable, $timelineTable . '.workflow_run_id', '=', $runTable . '.id')
            ->leftJoin($historyTable, static function ($join) use ($historyTable, $timelineTable): void {
                $join->on($historyTable . '.workflow_run_id', '=', $timelineTable . '.workflow_run_id')
                    ->on($historyTable . '.id', '=', $timelineTable . '.history_event_id');
            })
            ->where(static function ($query) use ($runTable, $historyTable): void {
                $query->whereNull($runTable . '.id')
                    ->orWhereNull($historyTable . '.id');
            })
            ->count($timelineTable . '.id');
    }

    private static function compatibilityBlockedRuns(): int
    {
        return self::summaryModel()::query()
            ->where('liveness_state', 'like', '%_task_waiting_for_compatible_worker')
            ->count();
    }

    private static function claimFailedRuns(): int
    {
        return self::summaryModel()::query()
            ->where('liveness_state', 'like', '%_task_claim_failed')
            ->count();
    }

    private static function retryingActivities(): int
    {
        return self::activityExecutionModel()::query()
            ->where('status', ActivityStatus::Pending->value)
            ->where('attempt_count', '>', 0)
            ->count();
    }

    private static function pendingStartRuns(): int
    {
        return self::summaryModel()::query()
            ->where('status', RunStatus::Pending->value)
            ->count();
    }

    private static function pendingStartCommands(): int
    {
        return self::commandModel()::query()
            ->where('command_type', CommandType::Start->value)
            ->where('status', CommandStatus::Accepted->value)
            ->where('outcome', CommandOutcome::StartedNew->value)
            ->whereHas('run', static function ($query): void {
                $query->where('status', RunStatus::Pending->value);
            })
            ->count();
    }

    private static function readyPendingStartTasks(CarbonInterface $now): int
    {
        return self::taskModel()::query()
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', $now);
            })
            ->whereHas('run', static function ($query): void {
                $query->where('status', RunStatus::Pending->value);
            })
            ->count();
    }

    private static function oldestPendingStartAt(): ?CarbonInterface
    {
        /** @var WorkflowCommand|null $command */
        $command = self::commandModel()::query()
            ->where('command_type', CommandType::Start->value)
            ->where('status', CommandStatus::Accepted->value)
            ->where('outcome', CommandOutcome::StartedNew->value)
            ->whereNotNull('accepted_at')
            ->whereHas('run', static function ($query): void {
                $query->where('status', RunStatus::Pending->value);
            })
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->first();

        if ($command?->accepted_at instanceof CarbonInterface) {
            return $command->accepted_at;
        }

        /** @var WorkflowRunSummary|null $summary */
        $summary = self::summaryModel()::query()
            ->where('status', RunStatus::Pending->value)
            ->whereNotNull('started_at')
            ->orderBy('started_at')
            ->orderBy('id')
            ->first();

        return $summary?->started_at;
    }

    private static function openTasks(): int
    {
        return self::taskModel()::query()
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count();
    }

    private static function readyTasks(): int
    {
        return self::taskModel()::query()
            ->where('status', TaskStatus::Ready->value)
            ->count();
    }

    private static function readyDueTasks(CarbonInterface $now): int
    {
        return self::taskModel()::query()
            ->where('status', TaskStatus::Ready->value)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', $now);
            })
            ->count();
    }

    private static function delayedTasks(CarbonInterface $now): int
    {
        return self::taskModel()::query()
            ->where('status', TaskStatus::Ready->value)
            ->where('available_at', '>', $now)
            ->count();
    }

    private static function leasedTasks(): int
    {
        return self::taskModel()::query()
            ->where('status', TaskStatus::Leased->value)
            ->count();
    }

    private static function leaseExpiredTasks(CarbonInterface $now): int
    {
        return self::taskModel()::query()
            ->where('status', TaskStatus::Leased->value)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', $now)
            ->count();
    }

    private static function dispatchFailedTasks(): int
    {
        return self::dispatchFailedQuery()->count();
    }

    private static function claimFailedTasks(): int
    {
        return self::claimFailedQuery()->count();
    }

    private static function dispatchOverdueTasks(CarbonInterface $now): int
    {
        $cutoff = $now->copy()->subSeconds(TaskRepairPolicy::redispatchAfterSeconds());

        return self::taskModel()::query()
            ->where('status', TaskStatus::Ready->value)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', $now);
            })
            ->where(static function ($query): void {
                self::applyDispatchHealthy($query);
            })
            ->where(static function ($query): void {
                self::applyClaimHealthy($query);
            })
            ->where(static function ($query) use ($cutoff): void {
                $query->where(static function ($dispatched) use ($cutoff): void {
                    $dispatched->whereNotNull('last_dispatched_at')
                        ->where('last_dispatched_at', '<=', $cutoff);
                })->orWhere(static function ($neverDispatched) use ($cutoff): void {
                    $neverDispatched->whereNull('last_dispatched_at')
                        ->where('created_at', '<=', $cutoff);
                });
            })
            ->count();
    }

    private static function dispatchFailedQuery()
    {
        $query = self::taskModel()::query();

        self::applyDispatchFailed($query);

        return $query;
    }

    private static function claimFailedQuery()
    {
        return self::taskModel()::query()
            ->where('status', TaskStatus::Ready->value)
            ->whereNotNull('last_claim_failed_at')
            ->whereNotNull('last_claim_error')
            ->where('last_claim_error', '!=', '');
    }

    private static function applyDispatchFailed($query): void
    {
        $query
            ->where('status', TaskStatus::Ready->value)
            ->whereNotNull('last_dispatch_attempt_at')
            ->whereNotNull('last_dispatch_error')
            ->where('last_dispatch_error', '!=', '')
            ->where(static function ($dispatch): void {
                $dispatch->whereNull('last_dispatched_at')
                    ->orWhereColumn('last_dispatch_attempt_at', '>', 'last_dispatched_at');
            });
    }

    private static function applyDispatchHealthy($query): void
    {
        $query
            ->whereNull('last_dispatch_attempt_at')
            ->orWhereNull('last_dispatch_error')
            ->orWhere('last_dispatch_error', '')
            ->orWhere(static function ($successfulDispatch): void {
                $successfulDispatch->whereNotNull('last_dispatched_at')
                    ->whereColumn('last_dispatch_attempt_at', '<=', 'last_dispatched_at');
            });
    }

    private static function applyClaimHealthy($query): void
    {
        $query
            ->whereNull('last_claim_failed_at')
            ->orWhereNull('last_claim_error')
            ->orWhere('last_claim_error', '');
    }

    /**
     * @return class-string<WorkflowRunSummary>
     */
    private static function summaryModel(): string
    {
        /** @var class-string<WorkflowRunSummary> $model */
        $model = config('workflows.v2.run_summary_model', WorkflowRunSummary::class);

        return $model;
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

    /**
     * @return class-string<WorkflowCommand>
     */
    private static function commandModel(): string
    {
        /** @var class-string<WorkflowCommand> $model */
        $model = config('workflows.v2.command_model', WorkflowCommand::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowTask>
     */
    private static function taskModel(): string
    {
        /** @var class-string<WorkflowTask> $model */
        $model = config('workflows.v2.task_model', WorkflowTask::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowHistoryEvent>
     */
    private static function historyEventModel(): string
    {
        /** @var class-string<WorkflowHistoryEvent> $model */
        $model = config('workflows.v2.history_event_model', WorkflowHistoryEvent::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowRunWait>
     */
    private static function runWaitModel(): string
    {
        /** @var class-string<WorkflowRunWait> $model */
        $model = config('workflows.v2.run_wait_model', WorkflowRunWait::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowTimelineEntry>
     */
    private static function runTimelineEntryModel(): string
    {
        /** @var class-string<WorkflowTimelineEntry> $model */
        $model = config('workflows.v2.run_timeline_entry_model', WorkflowTimelineEntry::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowRunTimerEntry>
     */
    private static function runTimerEntryModel(): string
    {
        /** @var class-string<WorkflowRunTimerEntry> $model */
        $model = config('workflows.v2.run_timer_entry_model', WorkflowRunTimerEntry::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowRunLineageEntry>
     */
    private static function runLineageEntryModel(): string
    {
        /** @var class-string<WorkflowRunLineageEntry> $model */
        $model = config('workflows.v2.run_lineage_entry_model', WorkflowRunLineageEntry::class);

        return $model;
    }

    /**
     * @return class-string<ActivityExecution>
     */
    private static function activityExecutionModel(): string
    {
        /** @var class-string<ActivityExecution> $model */
        $model = config('workflows.v2.activity_execution_model', ActivityExecution::class);

        return $model;
    }

    /**
     * @return class-string<ActivityAttempt>
     */
    private static function activityAttemptModel(): string
    {
        /** @var class-string<ActivityAttempt> $model */
        $model = config('workflows.v2.activity_attempt_model', ActivityAttempt::class);

        return $model;
    }

    private static function jsonTimestamp(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        if (is_string($value) && $value !== '') {
            return \Illuminate\Support\Carbon::parse($value)->toJSON();
        }

        return null;
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model
     */
    private static function tableFor(string $model): string
    {
        return (new $model())->getTable();
    }
}
