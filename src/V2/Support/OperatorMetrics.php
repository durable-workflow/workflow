<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;

final class OperatorMetrics
{
    /**
     * @return array<string, mixed>
     */
    public static function snapshot(?CarbonInterface $now = null, ?string $namespace = null): array
    {
        $now ??= now();
        $namespace = self::normalizeNamespace($namespace);

        return [
            'generated_at' => $now->toJSON(),
            'runs' => self::runMetrics($now, $namespace),
            'tasks' => self::taskMetrics($now, $namespace),
            'activities' => self::activityMetrics($namespace),
            'backlog' => self::backlogMetrics($now, $namespace),
            'repair' => $namespace === null ? TaskRepairCandidates::snapshot($now) : self::emptyRepairSnapshot(),
            'starts' => self::startMetrics($now, $namespace),
            'history' => self::historyMetrics($namespace),
            'command_contracts' => self::commandContractMetrics($namespace),
            'projections' => self::projectionMetrics($namespace),
            'schedules' => self::scheduleMetrics($now, $namespace),
            'workers' => self::workerMetrics(),
            'backend' => BackendCapabilities::snapshot($now),
            'structural_limits' => StructuralLimits::snapshot(),
            'update_wait' => UpdateWaitPolicy::snapshot(),
            'repair_policy' => TaskRepairPolicy::snapshot(),
            'matching_role' => self::matchingRoleSnapshot(),
        ];
    }

    /**
     * Process-local view of the matching-role deployment shape on this node.
     *
     * `queue_wake_enabled` reports `workflows.v2.matching_role.queue_wake_enabled`
     * exactly as the queue-worker Looping listener in WorkflowServiceProvider
     * consumes it. `shape` reports `in_worker` when the in-worker broad-poll
     * wake is active on this process and `dedicated` when the process has
     * opted out and the broad sweep is expected to run as
     * `php artisan workflow:v2:repair-pass` instead. `task_dispatch_mode`
     * reports the configured dispatch mode (`queue` or `poll`).
     *
     * @return array{queue_wake_enabled: bool, shape: string, task_dispatch_mode: string}
     */
    private static function matchingRoleSnapshot(): array
    {
        $queueWakeEnabled = (bool) config('workflows.v2.matching_role.queue_wake_enabled', true);
        $dispatchModeConfig = config('workflows.v2.task_dispatch_mode', 'queue');
        $dispatchMode = is_string($dispatchModeConfig) && $dispatchModeConfig !== ''
            ? $dispatchModeConfig
            : 'queue';

        return [
            'queue_wake_enabled' => $queueWakeEnabled,
            'shape' => $queueWakeEnabled ? 'in_worker' : 'dedicated',
            'task_dispatch_mode' => $dispatchMode,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function runMetrics(CarbonInterface $now, ?string $namespace): array
    {
        $oldestWaitStartedAt = self::oldestRunWaitStartedAt($namespace);

        return [
            'total' => self::summaryQuery($namespace)->count(),
            'current' => self::summaryQuery($namespace)->where('is_current_run', true)->count(),
            'running' => self::summaryQuery($namespace)->where('status_bucket', 'running')->count(),
            'completed' => self::summaryQuery($namespace)->where('status', RunStatus::Completed->value)->count(),
            'failed' => self::summaryQuery($namespace)->where('status', RunStatus::Failed->value)->count(),
            'cancelled' => self::summaryQuery($namespace)->where('status', RunStatus::Cancelled->value)->count(),
            'terminated' => self::summaryQuery($namespace)->where('status', RunStatus::Terminated->value)->count(),
            'archived' => self::summaryQuery($namespace)->whereNotNull('archived_at')->count(),
            'repair_needed' => self::summaryQuery($namespace)->where('liveness_state', 'repair_needed')->count(),
            'claim_failed' => self::claimFailedRuns($namespace),
            'compatibility_blocked' => self::compatibilityBlockedRuns($namespace),
            'waiting' => self::waitingRuns($namespace),
            'oldest_wait_started_at' => $oldestWaitStartedAt?->toJSON(),
            'max_wait_age_ms' => $oldestWaitStartedAt === null
                ? 0
                : (int) $oldestWaitStartedAt->diffInMilliseconds($now),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function taskMetrics(CarbonInterface $now, ?string $namespace): array
    {
        $oldestLeaseExpiredAt = self::oldestLeaseExpiredAt($now, $namespace);
        $oldestReadyDueAt = self::oldestReadyDueAt($now, $namespace);

        return [
            'open' => self::openTasks($namespace),
            'ready' => self::readyTasks($namespace),
            'ready_due' => self::readyDueTasks($now, $namespace),
            'delayed' => self::delayedTasks($now, $namespace),
            'leased' => self::leasedTasks($namespace),
            'dispatch_failed' => self::dispatchFailedTasks($namespace),
            'claim_failed' => self::claimFailedTasks($namespace),
            'dispatch_overdue' => self::dispatchOverdueTasks($now, $namespace),
            'lease_expired' => self::leaseExpiredTasks($now, $namespace),
            'oldest_lease_expired_at' => $oldestLeaseExpiredAt?->toJSON(),
            'max_lease_expired_age_ms' => $oldestLeaseExpiredAt === null
                ? 0
                : (int) $oldestLeaseExpiredAt->diffInMilliseconds($now),
            'oldest_ready_due_at' => $oldestReadyDueAt?->toJSON(),
            'max_ready_due_age_ms' => $oldestReadyDueAt === null
                ? 0
                : (int) $oldestReadyDueAt->diffInMilliseconds($now),
            'unhealthy' => self::dispatchFailedTasks($namespace)
                + self::claimFailedTasks($namespace)
                + self::dispatchOverdueTasks($now, $namespace)
                + self::leaseExpiredTasks($now, $namespace),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function backlogMetrics(CarbonInterface $now, ?string $namespace): array
    {
        $oldestCompatibilityBlockedAt = self::oldestCompatibilityBlockedRunAt($namespace);

        return [
            'runnable_tasks' => self::readyDueTasks($now, $namespace),
            'delayed_tasks' => self::delayedTasks($now, $namespace),
            'leased_tasks' => self::leasedTasks($namespace),
            'retrying_activities' => self::retryingActivities($namespace),
            'unhealthy_tasks' => self::dispatchFailedTasks($namespace)
                + self::claimFailedTasks($namespace)
                + self::dispatchOverdueTasks($now, $namespace)
                + self::leaseExpiredTasks($now, $namespace),
            'repair_needed_runs' => self::summaryQuery($namespace)
                ->where('liveness_state', 'repair_needed')
                ->count(),
            'claim_failed_runs' => self::claimFailedRuns($namespace),
            'compatibility_blocked_runs' => self::compatibilityBlockedRuns($namespace),
            'oldest_compatibility_blocked_started_at' => $oldestCompatibilityBlockedAt?->toJSON(),
            'max_compatibility_blocked_age_ms' => $oldestCompatibilityBlockedAt === null
                ? 0
                : (int) $oldestCompatibilityBlockedAt->diffInMilliseconds($now),
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function activityMetrics(?string $namespace): array
    {
        return [
            'open' => self::scopedRunModelQuery(self::activityExecutionModel(), $namespace)
                ->whereIn('status', [ActivityStatus::Pending->value, ActivityStatus::Running->value])
                ->count(),
            'pending' => self::scopedRunModelQuery(self::activityExecutionModel(), $namespace)
                ->where('status', ActivityStatus::Pending->value)
                ->count(),
            'running' => self::scopedRunModelQuery(self::activityExecutionModel(), $namespace)
                ->where('status', ActivityStatus::Running->value)
                ->count(),
            'retrying' => self::retryingActivities($namespace),
            'failed_attempts' => self::scopedRunModelQuery(self::activityAttemptModel(), $namespace)
                ->where('status', ActivityAttemptStatus::Failed->value)
                ->count(),
            'max_attempt_count' => (int) self::scopedRunModelQuery(self::activityExecutionModel(), $namespace)
                ->max('attempt_count'),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function startMetrics(CarbonInterface $now, ?string $namespace): array
    {
        $oldestPendingStartAt = self::oldestPendingStartAt($namespace);

        return [
            'pending_runs' => self::pendingStartRuns($namespace),
            'pending_commands' => self::pendingStartCommands($namespace),
            'ready_tasks' => self::readyPendingStartTasks($now, $namespace),
            'oldest_pending_start_at' => $oldestPendingStartAt?->toJSON(),
            'max_pending_ms' => $oldestPendingStartAt === null
                ? 0
                : (int) $oldestPendingStartAt->diffInMilliseconds($now),
        ];
    }

    /**
     * @return array{
     *     active: int,
     *     paused: int,
     *     missed: int,
     *     oldest_overdue_at: string|null,
     *     max_overdue_ms: int,
     *     fires_total: int,
     *     failures_total: int,
     * }
     */
    private static function scheduleMetrics(CarbonInterface $now, ?string $namespace): array
    {
        $active = self::scheduleQuery($namespace)
            ->where('status', ScheduleStatus::Active->value)
            ->count();

        $paused = self::scheduleQuery($namespace)
            ->where('status', ScheduleStatus::Paused->value)
            ->count();

        $missedQuery = self::scheduleQuery($namespace)
            ->where('status', ScheduleStatus::Active->value)
            ->whereNotNull('next_fire_at')
            ->where('next_fire_at', '<=', $now);

        $missed = $missedQuery->count();

        $oldestOverdueAt = $missed === 0
            ? null
            : self::scheduleQuery($namespace)
                ->where('status', ScheduleStatus::Active->value)
                ->whereNotNull('next_fire_at')
                ->where('next_fire_at', '<=', $now)
                ->min('next_fire_at');

        $oldestOverdue = self::jsonTimestamp($oldestOverdueAt);

        $maxOverdueMs = 0;

        if ($oldestOverdue !== null && $oldestOverdueAt !== null) {
            $oldest = $oldestOverdueAt instanceof CarbonInterface
                ? $oldestOverdueAt
                : \Illuminate\Support\Carbon::parse((string) $oldestOverdueAt);
            $maxOverdueMs = max(0, (int) $oldest->diffInMilliseconds($now));
        }

        $firesTotal = (int) self::scheduleQuery($namespace)
            ->where('status', ScheduleStatus::Active->value)
            ->sum('fires_count');

        $failuresTotal = (int) self::scheduleQuery($namespace)
            ->where('status', ScheduleStatus::Active->value)
            ->sum('failures_count');

        return [
            'active' => $active,
            'paused' => $paused,
            'missed' => $missed,
            'oldest_overdue_at' => $oldestOverdue,
            'max_overdue_ms' => $maxOverdueMs,
            'fires_total' => $firesTotal,
            'failures_total' => $failuresTotal,
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
        $fleet = [];

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

            $fleet[] = self::fleetEntry($snapshot);
        }

        return [
            'compatibility_namespace' => WorkerCompatibilityFleet::scopeNamespace(),
            'required_compatibility' => $required,
            'active_workers' => count($workerIds),
            'active_worker_scopes' => count($snapshots),
            'active_workers_supporting_required' => count($supportingWorkerIds),
            'fleet' => $fleet,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private static function fleetEntry(array $snapshot): array
    {
        $recordedAt = $snapshot['recorded_at'] ?? null;
        $expiresAt = $snapshot['expires_at'] ?? null;

        $supported = is_array($snapshot['supported'] ?? null)
            ? array_values(array_filter($snapshot['supported'], static fn ($value): bool => is_string($value)))
            : [];

        return [
            'worker_id' => (string) ($snapshot['worker_id'] ?? ''),
            'namespace' => self::stringOrNull($snapshot['namespace'] ?? null),
            'host' => self::stringOrNull($snapshot['host'] ?? null),
            'process_id' => self::stringOrNull($snapshot['process_id'] ?? null),
            'connection' => self::stringOrNull($snapshot['connection'] ?? null),
            'queue' => self::stringOrNull($snapshot['queue'] ?? null),
            'supported' => $supported,
            'supports_required' => ($snapshot['supports_required'] ?? false) === true,
            'recorded_at' => $recordedAt instanceof CarbonInterface ? $recordedAt->toJSON() : self::stringOrNull(
                $recordedAt
            ),
            'expires_at' => $expiresAt instanceof CarbonInterface ? $expiresAt->toJSON() : self::stringOrNull(
                $expiresAt
            ),
            'source' => is_string($snapshot['source'] ?? null) ? $snapshot['source'] : '',
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, int>
     */
    private static function historyMetrics(?string $namespace): array
    {
        return [
            'continue_as_new_recommended_runs' => self::summaryQuery($namespace)
                ->where('continue_as_new_recommended', true)
                ->count(),
            'events' => self::scopedRunModelQuery(self::historyEventModel(), $namespace)->count(),
            'history_orphan_total' => self::historyEventsMissingRun($namespace),
            'max_event_count' => (int) self::summaryQuery($namespace)->max('history_event_count'),
            'max_size_bytes' => (int) self::summaryQuery($namespace)->max('history_size_bytes'),
            'event_threshold' => HistoryBudget::eventThreshold(),
            'size_bytes_threshold' => HistoryBudget::sizeBytesThreshold(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function commandContractMetrics(?string $namespace): array
    {
        $needed = 0;
        $available = 0;

        self::runQuery($namespace)
            ->whereHas('historyEvents', static function ($query): void {
                $query->where('event_type', HistoryEventType::WorkflowStarted->value);
            })
            ->with([
                'historyEvents' => static function ($query): void {
                    $query->where('event_type', HistoryEventType::WorkflowStarted->value)
                        ->orderBy('sequence');
                },
            ])
            ->chunkById(200, static function ($runs) use (&$needed, &$available): void {
                foreach ($runs as $run) {
                    if (! $run instanceof WorkflowRun) {
                        continue;
                    }

                    $status = RunCommandContract::backfillStatus($run);

                    if (! $status['needed']) {
                        continue;
                    }

                    $needed++;

                    if ($status['available']) {
                        $available++;
                    }
                }
            });

        return [
            'backfill_needed_runs' => $needed,
            'backfill_available_runs' => $available,
            'backfill_unavailable_runs' => max(0, $needed - $available),
        ];
    }

    /**
     * @return array<string, array<string, int|string|null>>
     */
    private static function projectionMetrics(?string $namespace): array
    {
        $runSummaries = RunSummaryProjectionDrift::metrics($namespace);

        return [
            'run_summaries' => [
                ...$runSummaries,
                'oldest_updated_at' => self::jsonTimestamp(self::summaryQuery($namespace)->min('updated_at')),
                'newest_updated_at' => self::jsonTimestamp(self::summaryQuery($namespace)->max('updated_at')),
            ],
            'run_waits' => self::runWaitProjectionMetrics($namespace),
            'run_timeline_entries' => self::runTimelineProjectionMetrics($namespace),
            'run_timer_entries' => self::runTimerProjectionMetrics($namespace),
            'run_lineage_entries' => self::runLineageProjectionMetrics($namespace),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function runWaitProjectionMetrics(?string $namespace): array
    {
        $waitModel = self::runWaitModel();
        $summariesWithOpenWaits = self::summariesWithOpenWaits($namespace);
        $missingCurrentOpenWaits = self::missingCurrentOpenWaitProjections($namespace);
        $drift = SelectedRunProjectionDrift::waitMetrics(namespace: $namespace);
        $orphaned = self::projectionRowsMissingRun($waitModel, $namespace);

        return [
            'runs' => self::runQuery($namespace)->count(),
            'rows' => self::scopedRunModelQuery($waitModel, $namespace)->count(),
            'projected_runs' => self::scopedRunModelQuery($waitModel, $namespace)->distinct()->count('workflow_run_id'),
            'runs_with_waits' => $drift['runs_with_waits'],
            'projected_runs_with_waits' => $drift['projected_runs_with_waits'],
            'missing_runs_with_waits' => $drift['missing_runs_with_waits'],
            'summaries_with_open_waits' => $summariesWithOpenWaits,
            'projected_current_open_waits' => max(0, $summariesWithOpenWaits - $missingCurrentOpenWaits),
            'missing_current_open_waits' => $missingCurrentOpenWaits,
            'stale_projected_runs' => $drift['stale_projected_runs'],
            'orphaned' => $orphaned,
            'needs_rebuild' => $drift['missing_runs_with_waits'] + $drift['stale_projected_runs'] + $orphaned,
            'oldest_updated_at' => self::jsonTimestamp(
                self::scopedRunModelQuery($waitModel, $namespace)->min('updated_at')
            ),
            'newest_updated_at' => self::jsonTimestamp(
                self::scopedRunModelQuery($waitModel, $namespace)->max('updated_at')
            ),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function runTimelineProjectionMetrics(?string $namespace): array
    {
        $timelineModel = self::runTimelineEntryModel();
        $missingHistoryEvents = self::missingTimelineEventProjections($namespace);
        $drift = SelectedRunProjectionDrift::timelineMetrics(namespace: $namespace);
        $orphaned = self::orphanedTimelineRows($namespace);

        return [
            'runs' => self::runQuery($namespace)->count(),
            'history_events' => self::scopedRunModelQuery(self::historyEventModel(), $namespace)->count(),
            'rows' => self::scopedRunModelQuery($timelineModel, $namespace)->count(),
            'projected_runs' => self::scopedRunModelQuery($timelineModel, $namespace)->distinct()->count(
                'workflow_run_id'
            ),
            'runs_with_history' => $drift['runs_with_history'],
            'projected_runs_with_history' => $drift['projected_runs_with_history'],
            'missing_runs_with_history' => $drift['missing_runs_with_history'],
            'missing_history_events' => $missingHistoryEvents,
            'stale_projected_runs' => $drift['stale_projected_runs'],
            'orphaned' => $orphaned,
            'needs_rebuild' => $drift['missing_runs_with_history'] + $drift['stale_projected_runs'] + $orphaned,
            'oldest_updated_at' => self::jsonTimestamp(
                self::scopedRunModelQuery($timelineModel, $namespace)->min('updated_at')
            ),
            'newest_updated_at' => self::jsonTimestamp(
                self::scopedRunModelQuery($timelineModel, $namespace)->max('updated_at')
            ),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function runTimerProjectionMetrics(?string $namespace): array
    {
        $timerModel = self::runTimerEntryModel();
        $drift = SelectedRunProjectionDrift::timerMetrics(namespace: $namespace);
        $orphaned = self::projectionRowsMissingRun($timerModel, $namespace);

        return [
            'runs' => self::runQuery($namespace)->count(),
            'rows' => self::scopedRunModelQuery($timerModel, $namespace)->count(),
            'projected_runs' => self::scopedRunModelQuery($timerModel, $namespace)->distinct()->count(
                'workflow_run_id'
            ),
            'runs_with_timers' => $drift['runs_with_timers'],
            'projected_runs_with_timers' => $drift['projected_runs_with_timers'],
            'missing_runs_with_timers' => $drift['missing_runs_with_timers'],
            'stale_projected_runs' => $drift['stale_projected_runs'],
            'schema_version_mismatch_runs' => $drift['schema_version_mismatch_runs'],
            'schema_version_mismatch_rows' => self::scopedRunModelQuery($timerModel, $namespace)
                ->where(static function ($query): void {
                    $query->whereNull('schema_version')
                        ->orWhere('schema_version', '!=', WorkflowRunTimerEntry::CURRENT_SCHEMA_VERSION);
                })
                ->count(),
            'orphaned' => $orphaned,
            'needs_rebuild' => $drift['missing_runs_with_timers'] + $drift['stale_projected_runs'] + $orphaned,
            'oldest_updated_at' => self::jsonTimestamp(
                self::scopedRunModelQuery($timerModel, $namespace)->min('updated_at')
            ),
            'newest_updated_at' => self::jsonTimestamp(
                self::scopedRunModelQuery($timerModel, $namespace)->max('updated_at')
            ),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function runLineageProjectionMetrics(?string $namespace): array
    {
        $lineageModel = self::runLineageEntryModel();
        $drift = SelectedRunProjectionDrift::lineageMetrics(namespace: $namespace);
        $orphaned = self::projectionRowsMissingRun($lineageModel, $namespace);

        return [
            'runs' => self::runQuery($namespace)->count(),
            'rows' => self::scopedRunModelQuery($lineageModel, $namespace)->count(),
            'projected_runs' => self::scopedRunModelQuery($lineageModel, $namespace)->distinct()->count(
                'workflow_run_id'
            ),
            'runs_with_lineage' => $drift['runs_with_lineage'],
            'projected_runs_with_lineage' => $drift['projected_runs_with_lineage'],
            'missing_runs_with_lineage' => $drift['missing_runs_with_lineage'],
            'stale_projected_runs' => $drift['stale_projected_runs'],
            'orphaned' => $orphaned,
            'needs_rebuild' => $drift['missing_runs_with_lineage'] + $drift['stale_projected_runs'] + $orphaned,
            'oldest_updated_at' => self::jsonTimestamp(
                self::scopedRunModelQuery($lineageModel, $namespace)->min('updated_at')
            ),
            'newest_updated_at' => self::jsonTimestamp(
                self::scopedRunModelQuery($lineageModel, $namespace)->max('updated_at')
            ),
        ];
    }

    private static function summariesWithOpenWaits(?string $namespace): int
    {
        return self::summaryQuery($namespace)
            ->whereNotNull('open_wait_id')
            ->count();
    }

    private static function missingCurrentOpenWaitProjections(?string $namespace): int
    {
        $summaryModel = self::summaryModel();
        $waitModel = self::runWaitModel();
        $summaryTable = self::tableFor($summaryModel);
        $waitTable = self::tableFor($waitModel);

        return self::summaryQuery($namespace)
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
    private static function projectionRowsMissingRun(string $projectionModel, ?string $namespace): int
    {
        $runModel = self::runModel();
        $projectionTable = self::tableFor($projectionModel);
        $runTable = self::tableFor($runModel);

        $query = $projectionModel::query()
            ->leftJoin($runTable, $projectionTable . '.workflow_run_id', '=', $runTable . '.id')
            ->whereNull($runTable . '.id');

        if ($namespace !== null) {
            return 0;
        }

        return $query->count($projectionTable . '.id');
    }

    private static function missingTimelineEventProjections(?string $namespace): int
    {
        $historyModel = self::historyEventModel();
        $timelineModel = self::runTimelineEntryModel();
        $historyTable = self::tableFor($historyModel);
        $timelineTable = self::tableFor($timelineModel);

        return self::scopedRunModelQuery($historyModel, $namespace)
            ->leftJoin($timelineTable, static function ($join) use ($historyTable, $timelineTable): void {
                $join->on($timelineTable . '.workflow_run_id', '=', $historyTable . '.workflow_run_id')
                    ->on($timelineTable . '.history_event_id', '=', $historyTable . '.id');
            })
            ->whereNull($timelineTable . '.id')
            ->count($historyTable . '.id');
    }

    private static function orphanedTimelineRows(?string $namespace): int
    {
        if ($namespace !== null) {
            return 0;
        }

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

    private static function historyEventsMissingRun(?string $namespace): int
    {
        if ($namespace !== null) {
            return 0;
        }

        $runModel = self::runModel();
        $historyModel = self::historyEventModel();
        $runTable = self::tableFor($runModel);
        $historyTable = self::tableFor($historyModel);

        return $historyModel::query()
            ->leftJoin($runTable, $historyTable . '.workflow_run_id', '=', $runTable . '.id')
            ->whereNull($runTable . '.id')
            ->count($historyTable . '.id');
    }

    private static function compatibilityBlockedRuns(?string $namespace): int
    {
        return self::summaryQuery($namespace)
            ->where('liveness_state', 'like', '%_task_waiting_for_compatible_worker')
            ->count();
    }

    /**
     * Oldest wait start timestamp across runs whose liveness_state is the
     * compatibility-blocked state. Rollout-safety surfaces this alongside
     * `compatibility_blocked_runs` so operators can answer "how stale is
     * the worst case?" from the metric alone, mirroring the existing
     * `repair.oldest_missing_run_started_at` shape.
     *
     * Prefers `wait_started_at` (set by `RunSummaryProjector` when a run
     * enters a task-waiting state) and falls back to `next_task_at` when
     * the projection has not yet recorded a wait boundary.
     */
    private static function oldestCompatibilityBlockedRunAt(?string $namespace): ?CarbonInterface
    {
        /** @var WorkflowRunSummary|null $summary */
        $summary = self::summaryQuery($namespace)
            ->where('liveness_state', 'like', '%_task_waiting_for_compatible_worker')
            ->orderByRaw('COALESCE(wait_started_at, next_task_at) asc')
            ->first();

        if (! $summary instanceof WorkflowRunSummary) {
            return null;
        }

        return $summary->wait_started_at ?? $summary->next_task_at;
    }

    private static function claimFailedRuns(?string $namespace): int
    {
        return self::summaryQuery($namespace)
            ->where('liveness_state', 'like', '%_task_claim_failed')
            ->count();
    }

    /**
     * Open runs that are currently parked at a wait point — running runs whose
     * `RunSummaryProjector` has recorded a `wait_started_at` because they are
     * blocked on a signal, update, timer, or compatible-worker arrival.
     *
     * Counted unconditionally so the worst-case wait age is legible regardless
     * of what kind of wait it is; consumers that want to exclude
     * compatibility-blocked waits can subtract `runs.compatibility_blocked`.
     */
    private static function waitingRuns(?string $namespace): int
    {
        return self::summaryQuery($namespace)
            ->where('status_bucket', 'running')
            ->whereNotNull('wait_started_at')
            ->count();
    }

    /**
     * Earliest `wait_started_at` timestamp across runs currently parked at a
     * wait point. Rollout-safety surfaces this alongside `runs.waiting` so
     * operators can answer "how long has the worst-case run been waiting at
     * a durable resume point?" from the metric alone, mirroring the existing
     * `backlog.oldest_compatibility_blocked_started_at` and
     * `tasks.oldest_lease_expired_at` shapes. The signal includes
     * compatibility-blocked waits because they too are durable-resume points
     * the system is waiting on; consumers that need to isolate the
     * non-compatibility share can use `backlog.oldest_compatibility_blocked_started_at`.
     */
    private static function oldestRunWaitStartedAt(?string $namespace): ?CarbonInterface
    {
        /** @var WorkflowRunSummary|null $summary */
        $summary = self::summaryQuery($namespace)
            ->where('status_bucket', 'running')
            ->whereNotNull('wait_started_at')
            ->orderBy('wait_started_at')
            ->first();

        if (! $summary instanceof WorkflowRunSummary) {
            return null;
        }

        return $summary->wait_started_at;
    }

    private static function retryingActivities(?string $namespace): int
    {
        return self::scopedRunModelQuery(self::activityExecutionModel(), $namespace)
            ->where('status', ActivityStatus::Pending->value)
            ->where('attempt_count', '>', 0)
            ->count();
    }

    private static function pendingStartRuns(?string $namespace): int
    {
        return self::summaryQuery($namespace)
            ->where('status', RunStatus::Pending->value)
            ->count();
    }

    private static function pendingStartCommands(?string $namespace): int
    {
        return self::scopedRunModelQuery(self::commandModel(), $namespace)
            ->where('command_type', CommandType::Start->value)
            ->where('status', CommandStatus::Accepted->value)
            ->where('outcome', CommandOutcome::StartedNew->value)
            ->whereHas('run', static function ($query): void {
                $query->where('status', RunStatus::Pending->value);
            })
            ->count();
    }

    private static function readyPendingStartTasks(CarbonInterface $now, ?string $namespace): int
    {
        return self::scopedRunModelQuery(self::taskModel(), $namespace)
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

    private static function oldestPendingStartAt(?string $namespace): ?CarbonInterface
    {
        /** @var WorkflowCommand|null $command */
        $command = self::scopedRunModelQuery(self::commandModel(), $namespace)
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
        $summary = self::summaryQuery($namespace)
            ->where('status', RunStatus::Pending->value)
            ->whereNotNull('started_at')
            ->orderBy('started_at')
            ->orderBy('id')
            ->first();

        return $summary?->started_at;
    }

    private static function openTasks(?string $namespace): int
    {
        return self::scopedRunModelQuery(self::taskModel(), $namespace)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count();
    }

    private static function readyTasks(?string $namespace): int
    {
        return self::scopedRunModelQuery(self::taskModel(), $namespace)
            ->where('status', TaskStatus::Ready->value)
            ->count();
    }

    private static function readyDueTasks(CarbonInterface $now, ?string $namespace): int
    {
        return self::scopedRunModelQuery(self::taskModel(), $namespace)
            ->where('status', TaskStatus::Ready->value)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', $now);
            })
            ->count();
    }

    private static function delayedTasks(CarbonInterface $now, ?string $namespace): int
    {
        return self::scopedRunModelQuery(self::taskModel(), $namespace)
            ->where('status', TaskStatus::Ready->value)
            ->where('available_at', '>', $now)
            ->count();
    }

    private static function leasedTasks(?string $namespace): int
    {
        return self::scopedRunModelQuery(self::taskModel(), $namespace)
            ->where('status', TaskStatus::Leased->value)
            ->count();
    }

    private static function leaseExpiredTasks(CarbonInterface $now, ?string $namespace): int
    {
        return self::scopedRunModelQuery(self::taskModel(), $namespace)
            ->where('status', TaskStatus::Leased->value)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', $now)
            ->count();
    }

    /**
     * Oldest `lease_expires_at` across leased tasks whose lease has already
     * expired at snapshot time. Rollout-safety surfaces this alongside
     * `tasks.lease_expired` so operators can answer "how long has the worst
     * leased task been expired without redelivery?" from the metric alone,
     * mirroring the existing `backlog.oldest_compatibility_blocked_started_at`
     * and `repair.oldest_missing_run_started_at` shapes.
     */
    private static function oldestLeaseExpiredAt(CarbonInterface $now, ?string $namespace): ?CarbonInterface
    {
        /** @var WorkflowTask|null $task */
        $task = self::scopedRunModelQuery(self::taskModel(), $namespace)
            ->where('status', TaskStatus::Leased->value)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', $now)
            ->orderBy('lease_expires_at')
            ->first();

        if (! $task instanceof WorkflowTask) {
            return null;
        }

        return $task->lease_expires_at;
    }

    /**
     * Earliest "ready since" timestamp across ready-due tasks — the effective
     * moment a task became eligible for dispatch, taking `available_at` when
     * present and falling back to `created_at` for tasks that were ready
     * immediately on creation. Rollout-safety surfaces this alongside
     * `tasks.ready_due` so operators can read queue latency ("how long has
     * the oldest actionable task been waiting to dispatch?") from the
     * metric alone, mirroring the existing `oldest_lease_expired_at` /
     * `max_lease_expired_age_ms` shape.
     */
    private static function oldestReadyDueAt(CarbonInterface $now, ?string $namespace): ?CarbonInterface
    {
        /** @var WorkflowTask|null $task */
        $task = self::scopedRunModelQuery(self::taskModel(), $namespace)
            ->where('status', TaskStatus::Ready->value)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', $now);
            })
            ->orderByRaw('COALESCE(available_at, created_at) asc')
            ->first();

        if (! $task instanceof WorkflowTask) {
            return null;
        }

        return $task->available_at ?? $task->created_at;
    }

    private static function dispatchFailedTasks(?string $namespace): int
    {
        return self::dispatchFailedQuery($namespace)->count();
    }

    private static function claimFailedTasks(?string $namespace): int
    {
        return self::claimFailedQuery($namespace)->count();
    }

    private static function dispatchOverdueTasks(CarbonInterface $now, ?string $namespace): int
    {
        $cutoff = $now->copy()
            ->subSeconds(TaskRepairPolicy::redispatchAfterSeconds());

        return self::scopedRunModelQuery(self::taskModel(), $namespace)
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

    private static function dispatchFailedQuery(?string $namespace)
    {
        $query = self::scopedRunModelQuery(self::taskModel(), $namespace);

        self::applyDispatchFailed($query);

        return $query;
    }

    private static function claimFailedQuery(?string $namespace)
    {
        return self::scopedRunModelQuery(self::taskModel(), $namespace)
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

    private static function summaryQuery(?string $namespace)
    {
        $model = self::summaryModel();
        $query = $model::query();

        if ($namespace !== null) {
            $query->where((new $model())->getTable() . '.namespace', $namespace);
        }

        return $query;
    }

    private static function runQuery(?string $namespace)
    {
        $model = self::runModel();
        $query = $model::query();

        if ($namespace !== null) {
            $query->where((new $model())->getTable() . '.namespace', $namespace);
        }

        return $query;
    }

    private static function scheduleQuery(?string $namespace)
    {
        $model = self::scheduleModel();
        $query = $model::query();

        if ($namespace !== null) {
            $query->where((new $model())->getTable() . '.namespace', $namespace);
        }

        return $query;
    }

    private static function scopedRunModelQuery(string $model, ?string $namespace)
    {
        $query = $model::query();

        if ($namespace !== null) {
            $runModel = self::runModel();
            $runTable = (new $runModel())->getTable();
            $query->whereHas('run', static function ($run) use ($namespace, $runTable): void {
                $run->where($runTable . '.namespace', $namespace);
            });
        }

        return $query;
    }

    /**
     * The repair candidate scanner is global because its fair scope selector is
     * not namespace-aware yet. Namespace-pinned Waterline views must not expose
     * global repair counts, so return an explicit zeroed repair snapshot there.
     *
     * @return array<string, mixed>
     */
    private static function emptyRepairSnapshot(): array
    {
        return [
            'existing_task_candidates' => 0,
            'missing_task_candidates' => 0,
            'total_candidates' => 0,
            'scan_limit' => TaskRepairPolicy::scanLimit(),
            'scan_strategy' => TaskRepairPolicy::SCAN_STRATEGY,
            'selected_existing_task_candidates' => 0,
            'selected_missing_task_candidates' => 0,
            'selected_total_candidates' => 0,
            'existing_task_scan_limit_reached' => false,
            'missing_task_scan_limit_reached' => false,
            'scan_pressure' => false,
            'oldest_task_candidate_created_at' => null,
            'oldest_missing_run_started_at' => null,
            'max_task_candidate_age_ms' => 0,
            'max_missing_run_age_ms' => 0,
            'scopes' => [],
        ];
    }

    private static function normalizeNamespace(?string $namespace): ?string
    {
        if ($namespace === null || trim($namespace) === '') {
            return null;
        }

        return trim($namespace);
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
     * @return class-string<WorkflowSchedule>
     */
    private static function scheduleModel(): string
    {
        /** @var class-string<WorkflowSchedule> $model */
        $model = config('workflows.v2.schedule_model', WorkflowSchedule::class);

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
