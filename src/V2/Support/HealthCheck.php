<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;

final class HealthCheck
{
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
        ];
        $status = self::status($checks);

        return [
            'generated_at' => $metrics['generated_at'] ?? $now->toJSON(),
            'status' => $status,
            'healthy' => $status !== 'error',
            'checks' => $checks,
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
            [
                'unhealthy_tasks' => $unhealthyTasks,
                'repair_needed_runs' => self::integer($backlog['repair_needed_runs'] ?? 0),
                'claim_failed_runs' => self::integer($backlog['claim_failed_runs'] ?? 0),
                'compatibility_blocked_runs' => self::integer($backlog['compatibility_blocked_runs'] ?? 0),
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

        if ($required === null || $supportingWorkers > 0) {
            return self::check(
                'worker_compatibility',
                'ok',
                $required === null
                    ? 'No current v2 compatibility marker is required.'
                    : 'At least one active worker heartbeat advertises the current v2 compatibility marker.',
                [
                    'required_compatibility' => $required,
                    'active_workers' => self::integer($workers['active_workers'] ?? 0),
                    'active_worker_scopes' => self::integer($workers['active_worker_scopes'] ?? 0),
                    'active_workers_supporting_required' => $supportingWorkers,
                ],
            );
        }

        return self::check(
            'worker_compatibility',
            'warning',
            'No active worker heartbeat advertises the current v2 compatibility marker.',
            [
                'required_compatibility' => $required,
                'active_workers' => self::integer($workers['active_workers'] ?? 0),
                'active_worker_scopes' => self::integer($workers['active_worker_scopes'] ?? 0),
                'active_workers_supporting_required' => $supportingWorkers,
            ],
        );
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
    private static function check(string $name, string $status, string $message, array $data): array
    {
        return [
            'name' => $name,
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ];
    }

    private static function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
