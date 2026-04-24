<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\App;

final class HealthCheck
{
    public const CATEGORY_CORRECTNESS = 'correctness';

    public const CATEGORY_ACCELERATION = 'acceleration';

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $metrics = OperatorMetrics::snapshot($now);
        $checks = [
            self::backendCheck($metrics['backend'] ?? []),
            self::runSummaryProjectionCheck($metrics['projections']['run_summaries'] ?? []),
            self::selectedRunProjectionCheck($metrics['projections'] ?? []),
            self::historyRetentionInvariantCheck($metrics['history'] ?? []),
            self::commandContractCheck($metrics['command_contracts'] ?? []),
            self::taskTransportCheck($metrics['tasks'] ?? [], $metrics['backlog'] ?? []),
            self::durableResumePathCheck($metrics['backlog'] ?? [], $metrics['repair'] ?? []),
            self::workerCompatibilityCheck($metrics['workers'] ?? []),
            self::schedulerRoleCheck($metrics['schedules'] ?? []),
            self::longPollWakeAccelerationCheck(),
        ];
        $status = self::status($checks);

        return [
            'generated_at' => $metrics['generated_at'] ?? $now->toJSON(),
            'status' => $status,
            'healthy' => $status !== 'error',
            'checks' => $checks,
            'categories' => self::categorySummary($checks),
            'operator_metrics' => $metrics,
            'structural_limits' => StructuralLimits::snapshot(),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function httpStatus(array $snapshot): int
    {
        return ($snapshot['status'] ?? null) === 'error' ? 503 : 200;
    }

    /**
     * @param array<string, mixed> $backend
     * @return array<string, mixed>
     */
    private static function backendCheck(array $backend): array
    {
        $issues = is_array($backend['issues'] ?? null) ? $backend['issues'] : [];
        $supported = BackendCapabilities::isSupported($backend);

        return self::check(
            'backend_capabilities',
            $supported ? 'ok' : 'error',
            $supported
                ? 'The configured database, queue, and cache backends satisfy the v2 capability contract.'
                : 'One or more configured v2 backend capabilities are unsupported.',
            self::CATEGORY_CORRECTNESS,
            [
                'issue_count' => count($issues),
                'issues' => $issues,
            ],
        );
    }

    /**
     * @param array<string, mixed> $projection
     * @return array<string, mixed>
     */
    private static function runSummaryProjectionCheck(array $projection): array
    {
        $needsRebuild = self::integer($projection['needs_rebuild'] ?? 0);

        return self::check(
            'run_summary_projection',
            $needsRebuild === 0 ? 'ok' : 'warning',
            $needsRebuild === 0
                ? 'Run-summary projections are aligned with durable v2 runs.'
                : 'Run-summary projections are missing, stale, schema-outdated, or orphaned; rebuild them before trusting Waterline lists.',
            self::CATEGORY_CORRECTNESS,
            [
                'needs_rebuild' => $needsRebuild,
                'missing' => self::integer($projection['missing'] ?? 0),
                'orphaned' => self::integer($projection['orphaned'] ?? 0),
                'stale' => self::integer($projection['stale'] ?? 0),
                'schema_outdated' => self::integer($projection['schema_outdated'] ?? 0),
                'projection_schema_version' => RunSummaryProjector::SCHEMA_VERSION,
            ],
        );
    }

    /**
     * @param array<string, mixed> $projections
     * @return array<string, mixed>
     */
    private static function selectedRunProjectionCheck(array $projections): array
    {
        $waits = is_array($projections['run_waits'] ?? null) ? $projections['run_waits'] : [];
        $timeline = is_array($projections['run_timeline_entries'] ?? null)
            ? $projections['run_timeline_entries']
            : [];
        $timers = is_array($projections['run_timer_entries'] ?? null)
            ? $projections['run_timer_entries']
            : [];
        $lineage = is_array($projections['run_lineage_entries'] ?? null)
            ? $projections['run_lineage_entries']
            : [];
        $waitNeedsRebuild = self::integer($waits['needs_rebuild'] ?? 0);
        $timelineNeedsRebuild = self::integer($timeline['needs_rebuild'] ?? 0);
        $timerNeedsRebuild = self::integer($timers['needs_rebuild'] ?? 0);
        $lineageNeedsRebuild = self::integer($lineage['needs_rebuild'] ?? 0);
        $needsRebuild = $waitNeedsRebuild + $timelineNeedsRebuild + $timerNeedsRebuild + $lineageNeedsRebuild;

        return self::check(
            'selected_run_projections',
            $needsRebuild === 0 ? 'ok' : 'warning',
            $needsRebuild === 0
                ? 'Selected-run wait, timeline, timer, and lineage projections are aligned with durable v2 detail.'
                : 'Selected-run wait, timeline, timer, or lineage projections need rebuild before trusting Waterline detail.',
            self::CATEGORY_CORRECTNESS,
            [
                'needs_rebuild' => $needsRebuild,
                'run_waits_needs_rebuild' => $waitNeedsRebuild,
                'run_waits_missing_runs_with_waits' => self::integer($waits['missing_runs_with_waits'] ?? 0),
                'run_waits_missing_current_open_waits' => self::integer($waits['missing_current_open_waits'] ?? 0),
                'run_waits_stale_projected_runs' => self::integer($waits['stale_projected_runs'] ?? 0),
                'run_waits_orphaned' => self::integer($waits['orphaned'] ?? 0),
                'timeline_needs_rebuild' => $timelineNeedsRebuild,
                'timeline_missing_runs_with_history' => self::integer($timeline['missing_runs_with_history'] ?? 0),
                'timeline_missing_history_events' => self::integer($timeline['missing_history_events'] ?? 0),
                'timeline_stale_projected_runs' => self::integer($timeline['stale_projected_runs'] ?? 0),
                'timeline_orphaned' => self::integer($timeline['orphaned'] ?? 0),
                'timer_needs_rebuild' => $timerNeedsRebuild,
                'timer_missing_runs_with_timers' => self::integer($timers['missing_runs_with_timers'] ?? 0),
                'timer_stale_projected_runs' => self::integer($timers['stale_projected_runs'] ?? 0),
                'timer_schema_version_mismatch_runs' => self::integer($timers['schema_version_mismatch_runs'] ?? 0),
                'timer_schema_version_mismatch_rows' => self::integer($timers['schema_version_mismatch_rows'] ?? 0),
                'timer_orphaned' => self::integer($timers['orphaned'] ?? 0),
                'lineage_needs_rebuild' => $lineageNeedsRebuild,
                'lineage_missing_runs_with_lineage' => self::integer($lineage['missing_runs_with_lineage'] ?? 0),
                'lineage_stale_projected_runs' => self::integer($lineage['stale_projected_runs'] ?? 0),
                'lineage_orphaned' => self::integer($lineage['orphaned'] ?? 0),
            ],
        );
    }

    /**
     * @param array<string, mixed> $history
     * @return array<string, mixed>
     */
    private static function historyRetentionInvariantCheck(array $history): array
    {
        $orphaned = self::integer($history['history_orphan_total'] ?? 0);

        return self::check(
            'history_retention_invariant',
            $orphaned === 0 ? 'ok' : 'warning',
            $orphaned === 0
                ? 'Workflow history events all reference retained workflow runs.'
                : 'Workflow history events exist without retained workflow runs; retention cleanup must reconcile them.',
            self::CATEGORY_CORRECTNESS,
            [
                'history_orphan_total' => $orphaned,
                'events' => self::integer($history['events'] ?? 0),
            ],
        );
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private static function commandContractCheck(array $metrics): array
    {
        $needed = self::integer($metrics['backfill_needed_runs'] ?? 0);

        return self::check(
            'command_contract_snapshots',
            $needed === 0 ? 'ok' : 'warning',
            $needed === 0
                ? 'WorkflowStarted command-contract snapshots are complete.'
                : 'Some WorkflowStarted command-contract snapshots need backfill before operators can trust command forms.',
            self::CATEGORY_CORRECTNESS,
            [
                'backfill_needed_runs' => $needed,
                'backfill_available_runs' => self::integer($metrics['backfill_available_runs'] ?? 0),
                'backfill_unavailable_runs' => self::integer($metrics['backfill_unavailable_runs'] ?? 0),
            ],
        );
    }

    /**
     * @param array<string, mixed> $tasks
     * @param array<string, mixed> $backlog
     * @return array<string, mixed>
     */
    private static function taskTransportCheck(array $tasks, array $backlog): array
    {
        $unhealthyTasks = self::integer($tasks['unhealthy'] ?? 0);

        return self::check(
            'task_transport',
            $unhealthyTasks === 0 ? 'ok' : 'warning',
            $unhealthyTasks === 0
                ? 'No unhealthy durable task transport state is currently projected.'
                : 'One or more durable tasks have unhealthy transport, claim, dispatch, or lease state.',
            self::CATEGORY_CORRECTNESS,
            [
                'unhealthy_tasks' => $unhealthyTasks,
                'lease_expired_tasks' => self::integer($tasks['lease_expired'] ?? 0),
                'oldest_lease_expired_at' => is_string($tasks['oldest_lease_expired_at'] ?? null)
                    ? $tasks['oldest_lease_expired_at']
                    : null,
                'max_lease_expired_age_ms' => self::integer($tasks['max_lease_expired_age_ms'] ?? 0),
                'repair_needed_runs' => self::integer($backlog['repair_needed_runs'] ?? 0),
                'claim_failed_runs' => self::integer($backlog['claim_failed_runs'] ?? 0),
                'compatibility_blocked_runs' => self::integer($backlog['compatibility_blocked_runs'] ?? 0),
                'oldest_compatibility_blocked_started_at' => is_string(
                    $backlog['oldest_compatibility_blocked_started_at'] ?? null
                )
                    ? $backlog['oldest_compatibility_blocked_started_at']
                    : null,
                'max_compatibility_blocked_age_ms' => self::integer($backlog['max_compatibility_blocked_age_ms'] ?? 0),
            ],
        );
    }

    /**
     * @param array<string, mixed> $backlog
     * @param array<string, mixed> $repair
     * @return array<string, mixed>
     */
    private static function durableResumePathCheck(array $backlog, array $repair): array
    {
        $repairNeededRuns = self::integer($backlog['repair_needed_runs'] ?? 0);

        return self::check(
            'durable_resume_paths',
            $repairNeededRuns === 0 ? 'ok' : 'warning',
            $repairNeededRuns === 0
                ? 'Every open v2 run has a projected durable resume path.'
                : 'One or more open v2 runs are missing their durable next-resume source and need repair.',
            self::CATEGORY_CORRECTNESS,
            [
                'repair_needed_runs' => $repairNeededRuns,
                'missing_task_candidates' => self::integer($repair['missing_task_candidates'] ?? 0),
                'selected_missing_task_candidates' => self::integer($repair['selected_missing_task_candidates'] ?? 0),
                'oldest_missing_run_started_at' => is_string($repair['oldest_missing_run_started_at'] ?? null)
                    ? $repair['oldest_missing_run_started_at']
                    : null,
                'max_missing_run_age_ms' => self::integer($repair['max_missing_run_age_ms'] ?? 0),
            ],
        );
    }

    /**
     * @param array<string, mixed> $workers
     * @return array<string, mixed>
     */
    private static function workerCompatibilityCheck(array $workers): array
    {
        $required = is_string($workers['required_compatibility'] ?? null)
            ? $workers['required_compatibility']
            : null;
        $supportingWorkers = self::integer($workers['active_workers_supporting_required'] ?? 0);
        $activeWorkers = self::integer($workers['active_workers'] ?? 0);
        $activeWorkerScopes = self::integer($workers['active_worker_scopes'] ?? 0);
        $validationMode = self::fleetValidationMode();

        $data = [
            'required_compatibility' => $required,
            'active_workers' => $activeWorkers,
            'active_worker_scopes' => $activeWorkerScopes,
            'active_workers_supporting_required' => $supportingWorkers,
            'validation_mode' => $validationMode,
        ];

        if ($required === null || $supportingWorkers > 0) {
            return self::check(
                'worker_compatibility',
                'ok',
                $required === null
                    ? 'No current v2 compatibility marker is required.'
                    : 'At least one active worker heartbeat advertises the current v2 compatibility marker.',
                self::CATEGORY_CORRECTNESS,
                $data,
            );
        }

        $status = $validationMode === 'fail' ? 'error' : 'warning';

        $message = $validationMode === 'fail'
            ? 'No active worker heartbeat advertises the current v2 compatibility marker; fleet validation mode is fail-closed.'
            : 'No active worker heartbeat advertises the current v2 compatibility marker.';

        return self::check('worker_compatibility', $status, $message, self::CATEGORY_CORRECTNESS, $data);
    }

    /**
     * Resolve the fleet admission posture from configuration. Mirrors the
     * validation_mode dial used by the long-poll cache validator: any value
     * other than the explicit `fail` sentinel reports a warning so a
     * misconfigured value does not silently escalate readiness to 503.
     */
    private static function fleetValidationMode(): string
    {
        $value = config('workflows.v2.fleet.validation_mode', 'warn');

        if (! is_string($value)) {
            return 'warn';
        }

        $normalized = strtolower(trim($value));

        return $normalized === 'fail' ? 'fail' : 'warn';
    }

    /**
     * Scheduler-role health: surfaces scheduler lag through the
     * `schedules.missed` / `schedules.max_overdue_ms` metrics so operators
     * can tell whether the scheduler tick is keeping up with active
     * schedules without reading `workflow_schedules` directly.
     *
     * @param array<string, mixed> $schedules
     * @return array<string, mixed>
     */
    private static function schedulerRoleCheck(array $schedules): array
    {
        $active = self::integer($schedules['active'] ?? 0);
        $paused = self::integer($schedules['paused'] ?? 0);
        $missed = self::integer($schedules['missed'] ?? 0);
        $maxOverdueMs = self::integer($schedules['max_overdue_ms'] ?? 0);
        $firesTotal = self::integer($schedules['fires_total'] ?? 0);
        $failuresTotal = self::integer($schedules['failures_total'] ?? 0);
        $oldestOverdueAt = is_string($schedules['oldest_overdue_at'] ?? null)
            ? $schedules['oldest_overdue_at']
            : null;

        $data = [
            'active' => $active,
            'paused' => $paused,
            'missed' => $missed,
            'oldest_overdue_at' => $oldestOverdueAt,
            'max_overdue_ms' => $maxOverdueMs,
            'fires_total' => $firesTotal,
            'failures_total' => $failuresTotal,
        ];

        return self::check(
            'scheduler_role',
            $missed === 0 ? 'ok' : 'warning',
            $missed === 0
                ? 'The scheduler tick is caught up to every active schedule in the namespace.'
                : 'One or more active schedules are past their next_fire_at; the scheduler tick has not yet caught up.',
            self::CATEGORY_CORRECTNESS,
            $data,
        );
    }

    /**
     * Acceleration-layer health for the long-poll wake surface.
     *
     * The wake layer is optional by contract: correctness continues even
     * when this check reports `warning`. The check exists so operators
     * can answer "is the acceleration layer propagating?" as a separate
     * question from "is work being discovered?".
     *
     * @return array<string, mixed>
     */
    private static function longPollWakeAccelerationCheck(): array
    {
        $multiNode = (bool) config('workflows.v2.long_poll.multi_node', false);
        $data = [
            'multi_node' => $multiNode,
            'backend' => null,
            'capable' => null,
            'safe' => null,
            'reason' => null,
        ];

        $cache = self::resolveCacheRepository();

        if ($cache === null) {
            return self::check(
                'long_poll_wake_acceleration',
                'warning',
                'Cache repository is not resolvable; wake acceleration may be disabled. Durable discovery continues via bounded polling.',
                self::CATEGORY_ACCELERATION,
                $data,
            );
        }

        $validator = new LongPollCacheValidator();
        $capability = $validator->validateMultiNodeCapable($cache);
        $safety = $validator->checkMultiNodeSafety($cache, $multiNode);

        $data['backend'] = is_string($capability['backend'] ?? null) ? $capability['backend'] : null;
        $data['capable'] = (bool) ($capability['capable'] ?? false);
        $data['safe'] = (bool) ($safety['safe'] ?? true);
        $data['reason'] = is_string($safety['message'] ?? null)
            ? $safety['message']
            : (is_string($capability['reason'] ?? null) ? $capability['reason'] : null);

        if ($data['safe'] === true) {
            return self::check(
                'long_poll_wake_acceleration',
                'ok',
                $multiNode
                    ? 'Wake acceleration backend is multi-node capable; dispatch discovery benefits from sub-second signalling.'
                    : 'Wake acceleration backend is configured; dispatch discovery benefits from sub-second signalling.',
                self::CATEGORY_ACCELERATION,
                $data,
            );
        }

        return self::check(
            'long_poll_wake_acceleration',
            'warning',
            $data['reason'] ?? 'Wake acceleration layer is degraded; durable discovery continues via bounded polling.',
            self::CATEGORY_ACCELERATION,
            $data,
        );
    }

    private static function resolveCacheRepository(): ?CacheRepository
    {
        try {
            // Resolve through the CacheManager so the check reflects the
            // currently configured default store. The cache.store container
            // singleton is bound on first access and does not reflect later
            // changes to cache.default, which drifts from the advertised
            // backend when operators reconfigure cache at runtime.
            return App::make('cache')->store();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Summarize check status per category so operators can answer
     * "is work being discovered?" (correctness) and "is the
     * acceleration layer propagating?" (acceleration) as separate
     * questions without re-aggregating the check list.
     *
     * @param list<array<string, mixed>> $checks
     * @return array<string, array<string, mixed>>
     */
    private static function categorySummary(array $checks): array
    {
        $categories = [
            self::CATEGORY_CORRECTNESS => [],
            self::CATEGORY_ACCELERATION => [],
        ];

        foreach ($checks as $check) {
            $category = $check['category'] ?? self::CATEGORY_CORRECTNESS;
            $categories[$category][] = $check;
        }

        $summaries = [];
        foreach ($categories as $name => $entries) {
            $summaries[$name] = [
                'status' => self::status($entries),
                'check_count' => count($entries),
            ];
        }

        return $summaries;
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private static function status(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        if (in_array('error', $statuses, true)) {
            return 'error';
        }

        if (in_array('warning', $statuses, true)) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function check(string $name, string $status, string $message, string $category, array $data): array
    {
        return [
            'name' => $name,
            'status' => $status,
            'category' => $category,
            'message' => $message,
            'data' => $data,
        ];
    }

    private static function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
