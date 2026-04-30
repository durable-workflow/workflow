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
            'activities' => self::activityMetrics($now, $namespace),
            'backlog' => self::backlogMetrics($now, $namespace),
            'repair' => $namespace === null ? TaskRepairCandidates::snapshot($now) : self::emptyRepairSnapshot(),
            'starts' => self::startMetrics($now, $namespace),
            'history' => self::historyMetrics($namespace),
            'command_contracts' => self::commandContractMetrics($namespace),
            'projections' => self::projectionMetrics($now, $namespace),
            'schedules' => self::scheduleMetrics($now, $namespace),
            'workers' => self::workerMetrics($namespace),
            'backend' => BackendCapabilities::snapshot($now),
            'structural_limits' => StructuralLimits::snapshot(),
            'update_wait' => UpdateWaitPolicy::snapshot(),
            'repair_policy' => TaskRepairPolicy::snapshot(),
            'matching_role' => MatchingRoleSnapshot::current(),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function runMetrics(CarbonInterface $now, ?string $namespace): array
    {
        $oldestWaitStartedAt = self::oldestRunWaitStartedAt($namespace);
        $oldestRepairNeededAt = self::oldestRepairNeededRunAt($namespace);

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
            'oldest_repair_needed_at' => $oldestRepairNeededAt?->toJSON(),
            'max_repair_needed_age_ms' => $oldestRepairNeededAt === null
                ? 0
                : (int) $oldestRepairNeededAt->diffInMilliseconds($now),
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
        $oldestDispatchOverdueSince = self::oldestDispatchOverdueSince($now, $namespace);
        $oldestClaimFailedAt = self::oldestClaimFailedAt($namespace);
        $oldestDispatchFailedAt = self::oldestDispatchFailedAt($namespace);
        $oldestUnhealthyAt = self::earliestTimestamp([
            $oldestDispatchFailedAt,
            $oldestClaimFailedAt,
            $oldestDispatchOverdueSince,
            $oldestLeaseExpiredAt,
        ]);

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
            'oldest_dispatch_overdue_since' => $oldestDispatchOverdueSince?->toJSON(),
            'max_dispatch_overdue_age_ms' => $oldestDispatchOverdueSince === null
                ? 0
                : (int) $oldestDispatchOverdueSince->diffInMilliseconds($now),
            'oldest_claim_failed_at' => $oldestClaimFailedAt?->toJSON(),
            'max_claim_failed_age_ms' => $oldestClaimFailedAt === null
                ? 0
                : (int) $oldestClaimFailedAt->diffInMilliseconds($now),
            'oldest_dispatch_failed_at' => $oldestDispatchFailedAt?->toJSON(),
            'max_dispatch_failed_age_ms' => $oldestDispatchFailedAt === null
                ? 0
                : (int) $oldestDispatchFailedAt->diffInMilliseconds($now),
            'max_attempt_count' => self::maxOpenTaskAttemptCount($namespace),
            'max_repair_count' => self::maxOpenTaskRepairCount($namespace),
            'unhealthy' => self::dispatchFailedTasks($namespace)
                + self::claimFailedTasks($namespace)
                + self::dispatchOverdueTasks($now, $namespace)
                + self::leaseExpiredTasks($now, $namespace),
            'oldest_unhealthy_at' => $oldestUnhealthyAt?->toJSON(),
            'max_unhealthy_age_ms' => $oldestUnhealthyAt === null
                ? 0
                : (int) $oldestUnhealthyAt->diffInMilliseconds($now),
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
            'tasks_added_last_minute' => self::tasksAddedLastMinute($now, $namespace),
            'tasks_dispatched_last_minute' => self::tasksDispatchedLastMinute($now, $namespace),
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
     * @return array<string, int|string|null>
     */
    private static function activityMetrics(CarbonInterface $now, ?string $namespace): array
    {
        $oldestRetryingStartedAt = self::oldestRetryingActivityStartedAt($namespace);
        $oldestTimeoutOverdueAt = self::oldestActivityTimeoutOverdueAt($now, $namespace);

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
            'oldest_retrying_started_at' => $oldestRetryingStartedAt?->toJSON(),
            'max_retrying_age_ms' => $oldestRetryingStartedAt === null
                ? 0
                : (int) $oldestRetryingStartedAt->diffInMilliseconds($now),
            'timeout_overdue' => self::timeoutOverdueActivities($now, $namespace),
            'oldest_timeout_overdue_at' => $oldestTimeoutOverdueAt?->toJSON(),
            'max_timeout_overdue_age_ms' => $oldestTimeoutOverdueAt === null
                ? 0
                : (int) $oldestTimeoutOverdueAt->diffInMilliseconds($now),
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
    private static function workerMetrics(?string $namespace): array
    {
        $required = WorkerCompatibility::current();
        $compatibilityNamespace = $namespace ?? WorkerCompatibilityFleet::scopeNamespace();
        $snapshots = $compatibilityNamespace === null
            ? WorkerCompatibilityFleet::details($required)
            : WorkerCompatibilityFleet::detailsForNamespace($compatibilityNamespace, $required);
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
            'compatibility_namespace' => $compatibilityNamespace,
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
     * Returns the earliest non-null `CarbonInterface` from the given list,
     * or `null` if every entry is `null`. Used to roll up multiple per-path
     * "oldest at" timestamps into a single worst-case duplicate-risk age the
     * rollout-safety contract pins on `OperatorMetrics::snapshot()`.
     *
     * @param  array<int, CarbonInterface|null>  $timestamps
     */
    private static function earliestTimestamp(array $timestamps): ?CarbonInterface
    {
        $earliest = null;

        foreach ($timestamps as $timestamp) {
            if ($timestamp === null) {
                continue;
            }

            if ($earliest === null || $timestamp->lessThan($earliest)) {
                $earliest = $timestamp;
            }
        }

        return $earliest;
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
    private static function projectionMetrics(CarbonInterface $now, ?string $namespace): array
    {
        $runSummaries = RunSummaryProjectionDrift::metrics($namespace);
        $oldestMissingRunStartedAt = self::oldestMissingRunSummaryStartedAt($namespace);

        return [
            'run_summaries' => [
                ...$runSummaries,
                'oldest_updated_at' => self::jsonTimestamp(self::summaryQuery($namespace)->min('updated_at')),
                'newest_updated_at' => self::jsonTimestamp(self::summaryQuery($namespace)->max('updated_at')),
                'oldest_missing_run_started_at' => $oldestMissingRunStartedAt?->toJSON(),
                'max_missing_run_age_ms' => $oldestMissingRunStartedAt === null
                    ? 0
                    : (int) $oldestMissingRunStartedAt->diffInMilliseconds($now),
            ],
            'run_waits' => self::runWaitProjectionMetrics($namespace),
            'run_timeline_entries' => self::runTimelineProjectionMetrics($namespace),
            'run_timer_entries' => self::runTimerProjectionMetrics($namespace),
            'run_lineage_entries' => self::runLineageProjectionMetrics($namespace),
        ];
    }

    /**
     * Earliest `COALESCE(workflow_runs.started_at, workflow_runs.created_at)`
     * among runs whose id is not present in `workflow_run_summaries`.
     * Mirrors the `repair.oldest_missing_run_started_at` shape so
     * rollout-safety consumers can read "how long has the worst-case run
     * been without a run-summary projection?" — the primary projection-lag
     * age indicator on the run-summary path — from the metric alone without
     * walking `workflow_runs`. Falls back to `created_at` when the run has
     * not yet recorded a `started_at` so not-yet-started runs still report
     * the backlog age they contribute to the projection lag.
     */
    private static function oldestMissingRunSummaryStartedAt(?string $namespace): ?CarbonInterface
    {
        /** @var WorkflowRun|null $run */
        $run = RunSummaryProjectionDrift::missingRunQuery($namespace)
            ->orderByRaw('COALESCE(started_at, created_at) asc')
            ->first();

        if (! $run instanceof WorkflowRun) {
            return null;
        }

        return $run->started_at ?? $run->created_at;
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
     * Earliest "stuck since" timestamp across runs whose liveness_state is
     * exactly `repair_needed`. Rollout-safety surfaces this alongside
     * `runs.repair_needed` so operators can answer "how long has the
     * worst-case run been stuck without progress?" from the metric alone,
     * the canonical stuck-workflow age indicator paired with the
     * `durable_resume_paths` health check.
     *
     * The summary's `updated_at` is sourced by `RunSummaryProjector` from
     * `WorkflowRun::last_progress_at`, so it advances when the run made
     * forward progress and stalls when the run stopped progressing. For
     * runs already pinned at `repair_needed` it is therefore the closest
     * available proxy for "when this run last made progress before being
     * marked broken." The summary `updated_at` is preferred; the run's
     * `started_at` is the fallback when the projection did not record a
     * progress boundary (a fresh run that was projected straight into
     * `repair_needed` without a prior progress write).
     */
    private static function oldestRepairNeededRunAt(?string $namespace): ?CarbonInterface
    {
        /** @var WorkflowRunSummary|null $summary */
        $summary = self::summaryQuery($namespace)
            ->where('liveness_state', 'repair_needed')
            ->orderByRaw('COALESCE(updated_at, started_at) asc')
            ->first();

        if (! $summary instanceof WorkflowRunSummary) {
            return null;
        }

        return $summary->updated_at ?? $summary->started_at;
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

    /**
     * Open activity executions whose schedule-to-start, start-to-close,
     * schedule-to-close, or heartbeat deadline has passed and is therefore
     * waiting for `ActivityTimeoutEnforcer` to enforce the timeout.
     *
     * The predicate mirrors `ActivityTimeoutEnforcer::expiredExecutionIds()`
     * exactly so the count is the operator-visible view of the same
     * sweep backlog: a non-zero value means at least one activity has hit
     * a deadline that the enforcement pass has not yet acted on. When the
     * sweep is healthy this count returns to zero between passes; sustained
     * non-zero readings indicate the activity-timeout sweep is lagging or
     * stalled and that worker liveness via heartbeat or start-to-close has
     * stopped on at least one execution. The signal is the activity-path
     * counterpart of `tasks.lease_expired` — both surface stuck work that
     * the corresponding sweep has not yet reclaimed.
     */
    private static function timeoutOverdueActivities(CarbonInterface $now, ?string $namespace): int
    {
        return self::scopedRunModelQuery(self::activityExecutionModel(), $namespace)
            ->whereIn('status', [ActivityStatus::Pending->value, ActivityStatus::Running->value])
            ->where(static function ($query) use ($now): void {
                $query->where(static function ($schedule) use ($now): void {
                    $schedule->where('status', ActivityStatus::Pending->value)
                        ->whereNotNull('schedule_deadline_at')
                        ->where('schedule_deadline_at', '<=', $now);
                })->orWhere(static function ($close) use ($now): void {
                    $close->where('status', ActivityStatus::Running->value)
                        ->whereNotNull('close_deadline_at')
                        ->where('close_deadline_at', '<=', $now);
                })->orWhere(static function ($scheduleToClose) use ($now): void {
                    $scheduleToClose->whereNotNull('schedule_to_close_deadline_at')
                        ->where('schedule_to_close_deadline_at', '<=', $now);
                })->orWhere(static function ($heartbeat) use ($now): void {
                    $heartbeat->where('status', ActivityStatus::Running->value)
                        ->whereNotNull('heartbeat_deadline_at')
                        ->where('heartbeat_deadline_at', '<=', $now);
                });
            })
            ->count();
    }

    /**
     * Earliest deadline timestamp across activity executions whose
     * schedule-to-start, start-to-close, schedule-to-close, or heartbeat
     * deadline has already passed. Rollout-safety surfaces this alongside
     * `activities.timeout_overdue` so operators can answer "how long has
     * the worst-case activity been past a timeout deadline without
     * enforcement?" — the primary stuck-activity duplicate-risk age
     * indicator on the activity path — from the metric alone, mirroring
     * the `tasks.oldest_lease_expired_at` / `max_lease_expired_age_ms`
     * shape on the task path. The earliest expired deadline among the
     * four enforcement-relevant deadline columns wins.
     */
    private static function oldestActivityTimeoutOverdueAt(CarbonInterface $now, ?string $namespace): ?CarbonInterface
    {
        return self::earliestTimestamp([
            self::firstActivityDeadlineAt(
                $namespace,
                'schedule_deadline_at',
                $now,
                [ActivityStatus::Pending->value],
            ),
            self::firstActivityDeadlineAt($namespace, 'close_deadline_at', $now, [ActivityStatus::Running->value]),
            self::firstActivityDeadlineAt(
                $namespace,
                'schedule_to_close_deadline_at',
                $now,
                [ActivityStatus::Pending->value, ActivityStatus::Running->value],
            ),
            self::firstActivityDeadlineAt(
                $namespace,
                'heartbeat_deadline_at',
                $now,
                [ActivityStatus::Running->value],
            ),
        ]);
    }

    /**
     * Earliest expired deadline timestamp on $column among activity
     * executions in one of $statuses, or null when none are expired. Used
     * by `oldestActivityTimeoutOverdueAt()` to roll the four
     * enforcement-relevant deadline columns into a single worst-case age.
     *
     * @param  list<string>  $statuses
     */
    private static function firstActivityDeadlineAt(
        ?string $namespace,
        string $column,
        CarbonInterface $now,
        array $statuses,
    ): ?CarbonInterface {
        /** @var ActivityExecution|null $execution */
        $execution = self::scopedRunModelQuery(self::activityExecutionModel(), $namespace)
            ->whereIn('status', $statuses)
            ->whereNotNull($column)
            ->where($column, '<=', $now)
            ->orderBy($column)
            ->first();

        if (! $execution instanceof ActivityExecution) {
            return null;
        }

        $value = $execution->getAttribute($column);

        return $value instanceof CarbonInterface ? $value : null;
    }

    /**
     * Earliest `started_at` across activity executions currently in the
     * retry window — Pending status with `attempt_count > 0`. Rollout-safety
     * surfaces this alongside `activities.retrying` so operators can read
     * "how long has the worst-case activity been chewing retries?" — the
     * primary retry-rate age indicator on the activity path — from the
     * metric alone, mirroring the `tasks.oldest_lease_expired_at` /
     * `max_lease_expired_age_ms` shape on the task path. The retrying
     * predicate matches `retryingActivities()` exactly so the two keys
     * stay aligned.
     */
    private static function oldestRetryingActivityStartedAt(?string $namespace): ?CarbonInterface
    {
        /** @var ActivityExecution|null $execution */
        $execution = self::scopedRunModelQuery(self::activityExecutionModel(), $namespace)
            ->where('status', ActivityStatus::Pending->value)
            ->where('attempt_count', '>', 0)
            ->whereNotNull('started_at')
            ->orderBy('started_at')
            ->first();

        if (! $execution instanceof ActivityExecution) {
            return null;
        }

        return $execution->started_at;
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

    /**
     * Distinct durable task rows created inside the trailing 60-second window.
     * This is the best stable "tasks added" fact the durable model exposes
     * without inventing a second transport-only counter stream.
     */
    private static function tasksAddedLastMinute(CarbonInterface $now, ?string $namespace): int
    {
        $windowStart = $now->copy()
            ->subMinute();

        return self::scopedRunModelQuery(self::taskModel(), $namespace)
            ->where('created_at', '>=', $windowStart)
            ->count();
    }

    /**
     * Distinct durable task rows whose latest successful dispatch landed
     * inside the trailing 60-second window. Repeated redispatches of the same
     * durable task collapse to one row because `workflow_tasks` retains only
     * the latest successful `last_dispatched_at`.
     */
    private static function tasksDispatchedLastMinute(CarbonInterface $now, ?string $namespace): int
    {
        $windowStart = $now->copy()
            ->subMinute();

        return self::scopedRunModelQuery(self::taskModel(), $namespace)
            ->whereNotNull('last_dispatched_at')
            ->where('last_dispatched_at', '>=', $windowStart)
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
        return self::dispatchOverdueQuery($now, $namespace)->count();
    }

    /**
     * Earliest "waiting-for-dispatch" moment among dispatch-overdue tasks —
     * the effective `COALESCE(last_dispatched_at, created_at)`, which is the
     * timestamp the task has been waiting for a successful dispatch since
     * (either the last attempted dispatch that didn't stick, or the task's
     * creation time for tasks that were never dispatched at all). Rollout-safety
     * surfaces this alongside `tasks.dispatch_overdue` so operators can read
     * wake-latency ("how long has the oldest ready-but-unclaimed task been
     * waiting for a working dispatch wake?") from the metric alone, mirroring
     * the existing `oldest_ready_due_at` / `max_ready_due_age_ms` shape.
     */
    private static function oldestDispatchOverdueSince(CarbonInterface $now, ?string $namespace): ?CarbonInterface
    {
        /** @var WorkflowTask|null $task */
        $task = self::dispatchOverdueQuery($now, $namespace)
            ->orderByRaw('COALESCE(last_dispatched_at, created_at) asc')
            ->first();

        if (! $task instanceof WorkflowTask) {
            return null;
        }

        return $task->last_dispatched_at ?? $task->created_at;
    }

    /**
     * Earliest `last_claim_failed_at` among tasks currently counted by
     * `tasks.claim_failed` (Ready tasks whose most recent claim attempt
     * recorded an uncleared `last_claim_error`). Rollout-safety surfaces
     * this alongside `tasks.claim_failed` so operators can read "how long
     * has the worst-case task been sitting with an uncleared claim error?"
     * — the primary lease-conflict and duplicate-risk age indicator for
     * the claim path — from the metric alone, mirroring the existing
     * `oldest_dispatch_overdue_since` / `max_dispatch_overdue_age_ms` shape
     * for the dispatch path.
     */
    private static function oldestClaimFailedAt(?string $namespace): ?CarbonInterface
    {
        /** @var WorkflowTask|null $task */
        $task = self::claimFailedQuery($namespace)
            ->orderBy('last_claim_failed_at')
            ->first();

        if (! $task instanceof WorkflowTask) {
            return null;
        }

        return $task->last_claim_failed_at;
    }

    /**
     * Earliest `last_dispatch_attempt_at` among tasks currently counted by
     * `tasks.dispatch_failed` (Ready tasks whose most recent dispatch
     * attempt recorded an uncleared `last_dispatch_error` that has not
     * been superseded by a later successful dispatch). Rollout-safety
     * surfaces this alongside `tasks.dispatch_failed` so operators can
     * read "how long has the worst-case task been sitting with an
     * uncleared dispatch error?" — the primary transport-failure age
     * indicator on the dispatch path — from the metric alone, mirroring
     * the existing `oldest_claim_failed_at` / `max_claim_failed_age_ms`
     * shape for the claim path.
     */
    private static function oldestDispatchFailedAt(?string $namespace): ?CarbonInterface
    {
        /** @var WorkflowTask|null $task */
        $task = self::dispatchFailedQuery($namespace)
            ->orderBy('last_dispatch_attempt_at')
            ->first();

        if (! $task instanceof WorkflowTask) {
            return null;
        }

        return $task->last_dispatch_attempt_at;
    }

    /**
     * Largest `attempt_count` among open tasks (Ready or Leased).
     * Rollout-safety surfaces this as the task-side retry-rate
     * indicator: a sustained non-zero reading means the worst-case
     * task currently in flight has been claimed or dispatched many
     * times without completing, mirroring `activities.max_attempt_count`
     * on the activity path. Closed tasks (Completed or Failed) are
     * excluded so the signal tracks active retry burn rather than
     * accumulating against historical rows.
     */
    private static function maxOpenTaskAttemptCount(?string $namespace): int
    {
        return (int) self::scopedRunModelQuery(self::taskModel(), $namespace)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->max('attempt_count');
    }

    /**
     * Largest `repair_count` among open tasks (Ready or Leased).
     * Rollout-safety surfaces this as the redispatch-burn indicator
     * on the task transport path: a sustained non-zero reading means
     * the worst-case task currently in flight has been redispatched
     * by `TaskRepair::repairRun()` many times without making it
     * through to a worker, which is the canonical signal that
     * dispatch wakes are not landing on a compatible claimer. Closed
     * tasks (Completed or Failed) are excluded so the signal tracks
     * active repair burn rather than accumulating against historical
     * rows.
     */
    private static function maxOpenTaskRepairCount(?string $namespace): int
    {
        return (int) self::scopedRunModelQuery(self::taskModel(), $namespace)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->max('repair_count');
    }

    private static function dispatchOverdueQuery(CarbonInterface $now, ?string $namespace)
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
            });
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
