<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkerCompatibilityHeartbeat;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowRunSummary;

final class OperatorDashboardSummary
{
    /**
     * Null workflow types include every type. An empty list matches no runs.
     * Operational metrics keep their namespace scope independently of this
     * workflow-volume filter.
     *
     * @param list<string>|null $workflowTypes
     * @return array<string, mixed>
     */
    public static function snapshot(
        ?CarbonInterface $now = null,
        ?string $namespace = null,
        bool $includeHistoryAudits = true,
        ?array $workflowTypes = null,
    ): array {
        $now ??= now();
        $namespace = self::normalizeNamespace($namespace);
        $flowsPastHour = self::flowsPastHour($now, $namespace, $workflowTypes);

        return [
            'flows' => self::totalFlows($namespace, $workflowTypes),
            'flows_per_minute' => $flowsPastHour / 60,
            'flows_past_hour' => $flowsPastHour,
            'exceptions_past_hour' => self::exceptionsPastHour($now, $namespace, $workflowTypes),
            'failed_flows_past_week' => self::failedFlowsPastWeek($now, $namespace, $workflowTypes),
            'max_wait_time_workflow' => self::modelArray(self::maxWaitTimeWorkflow($namespace, $workflowTypes)),
            'max_duration_workflow' => self::modelArray(self::maxDurationWorkflow($namespace, $workflowTypes)),
            'max_exceptions_workflow' => self::modelArray(self::maxExceptionsWorkflow($namespace, $workflowTypes)),
            'fleet_overview' => self::fleetOverview($now, $namespace, $workflowTypes),
            'workflow_type_health' => self::workflowTypeHealth($now, $namespace, $workflowTypes),
            'needs_attention' => self::needsAttention($now, $namespace, $workflowTypes),
            'fleet_trends_series' => self::fleetTrendsSeries($now, $namespace, $workflowTypes),
            'workflow_scope' => [
                'namespace' => $namespace,
                'workflow_types' => $workflowTypes,
                'all_workflow_types' => $workflowTypes === null,
            ],
            'time_windows' => [
                'generated_at' => $now->toIso8601String(),
                'total_runs' => 'all_retained_runs',
                'current_status' => 'current',
                'recent_hour_from' => $now->copy()->subHour()->toIso8601String(),
                'recent_day_from' => $now->copy()->subDay()->toIso8601String(),
                'recent_week_from' => $now->copy()->subWeek()->toIso8601String(),
                'trends_resolution' => 'hour',
            ],
            'operator_metrics_scope' => ['namespace' => $namespace, 'workflow_types' => null],
            'operator_metrics' => OperatorMetrics::snapshot($now, $namespace, $includeHistoryAudits),
        ];
    }

    /**
     * Time-series data for fleet trends chart.
     * Returns hourly bucketed counts over the last 7 days.
     *
     * @param list<string>|null $workflowTypes
     * @return array<string, mixed>
     */
    public static function fleetTrendsSeries(
        ?CarbonInterface $now = null,
        ?string $namespace = null,
        ?array $workflowTypes = null,
    ): array
    {
        $now ??= now();
        $namespace = self::normalizeNamespace($namespace);
        $weekAgo = $now->copy()
            ->subWeek();

        // Aggregate before fetching: at most two status rows for each displayed
        // hour, independent of how many maintenance runs completed in that hour.
        $query = self::summaryQuery($namespace, $workflowTypes);
        $hourBucket = match ($query->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => "DATE_FORMAT(closed_at, '%Y-%m-%d %H:00:00')",
            'pgsql' => "TO_CHAR(closed_at, 'YYYY-MM-DD HH24:00:00')",
            'sqlite' => "STRFTIME('%Y-%m-%d %H:00:00', closed_at)",
            'sqlsrv' => "CONVERT(varchar(13), closed_at, 120) + ':00:00'",
            default => throw new LogicException('Unsupported database driver for dashboard trend aggregation.'),
        };
        $rows = $query
            ->selectRaw($hourBucket . ' AS hour_bucket, status_bucket, COUNT(*) AS run_count')
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $weekAgo)
            ->where('closed_at', '<', $now->copy()->startOfHour()->addHour())
            ->whereIn('status_bucket', ['completed', 'failed'])
            ->groupBy(DB::raw($hourBucket), 'status_bucket')
            ->get();

        // Build time series with all hours (fill gaps with zeros)
        $series = [
            'timestamps' => [],
            'completed' => [],
            'failed' => [],
        ];

        $hourCounts = [];
        foreach ($rows as $row) {
            $hourCounts[(string) $row->hour_bucket][$row->status_bucket] = (int) $row->run_count;
        }

        // Generate all hours in the range
        $current = $weekAgo->copy()
            ->startOfHour();
        $end = $now->copy()
            ->startOfHour();

        while ($current->lte($end)) {
            $hourKey = $current->format('Y-m-d H:00:00');
            $series['timestamps'][] = $current->timestamp * 1000; // milliseconds for ApexCharts
            $series['completed'][] = $hourCounts[$hourKey]['completed'] ?? 0;
            $series['failed'][] = $hourCounts[$hourKey]['failed'] ?? 0;

            $current->addHour();
        }

        return $series;
    }

    /**
     * Fleet overview with status breakdown and trends.
     *
     * @param list<string>|null $workflowTypes
     * @return array<string, mixed>
     */
    private static function fleetOverview(CarbonInterface $now, ?string $namespace, ?array $workflowTypes): array
    {
        $hourAgo = $now->copy()
            ->subHour();
        $dayAgo = $now->copy()
            ->subDay();
        $weekAgo = $now->copy()
            ->subWeek();

        // Current counts by status bucket
        $currentCounts = self::summaryQuery($namespace, $workflowTypes)
            ->select('status_bucket', DB::raw('COUNT(*) as count'))
            ->where('status_bucket', '!=', 'completed') // Only active statuses
            ->groupBy('status_bucket')
            ->pluck('count', 'status_bucket')
            ->toArray();

        // Trend over last hour (completed in last hour)
        $hourTrend = self::summaryQuery($namespace, $workflowTypes)
            ->select('status_bucket', DB::raw('COUNT(*) as count'))
            ->where('closed_at', '>=', $hourAgo)
            ->whereIn('status_bucket', ['completed', 'failed'])
            ->groupBy('status_bucket')
            ->pluck('count', 'status_bucket')
            ->toArray();

        // Trend over last day
        $dayTrend = self::summaryQuery($namespace, $workflowTypes)
            ->select('status_bucket', DB::raw('COUNT(*) as count'))
            ->where('closed_at', '>=', $dayAgo)
            ->whereIn('status_bucket', ['completed', 'failed'])
            ->groupBy('status_bucket')
            ->pluck('count', 'status_bucket')
            ->toArray();

        // Trend over last week
        $weekTrend = self::summaryQuery($namespace, $workflowTypes)
            ->select('status_bucket', DB::raw('COUNT(*) as count'))
            ->where('closed_at', '>=', $weekAgo)
            ->whereIn('status_bucket', ['completed', 'failed'])
            ->groupBy('status_bucket')
            ->pluck('count', 'status_bucket')
            ->toArray();

        return [
            'current' => [
                'running' => $currentCounts['running'] ?? 0,
                'failed' => $currentCounts['failed'] ?? 0,
            ],
            'trends' => [
                'hour' => [
                    'completed' => $hourTrend['completed'] ?? 0,
                    'failed' => $hourTrend['failed'] ?? 0,
                ],
                'day' => [
                    'completed' => $dayTrend['completed'] ?? 0,
                    'failed' => $dayTrend['failed'] ?? 0,
                ],
                'week' => [
                    'completed' => $weekTrend['completed'] ?? 0,
                    'failed' => $weekTrend['failed'] ?? 0,
                ],
            ],
        ];
    }

    /**
     * Per-workflow-type health metrics.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function workflowTypeHealth(CarbonInterface $now, ?string $namespace, ?array $workflowTypes): array
    {
        $weekAgo = $now->copy()
            ->subWeek();

        // Get top 10 workflow types by volume in the last week
        $types = self::summaryQuery($namespace, $workflowTypes)
            ->select('workflow_type', DB::raw('COUNT(*) as total_runs'))
            ->where('created_at', '>=', $weekAgo)
            ->groupBy('workflow_type')
            ->orderByDesc('total_runs')
            ->limit(10)
            ->pluck('total_runs', 'workflow_type')
            ->toArray();

        $health = [];

        foreach ($types as $workflowType => $totalRuns) {
            // Get status breakdown for this type
            $statusCounts = self::summaryQuery($namespace, $workflowTypes)
                ->select('status', DB::raw('COUNT(*) as count'))
                ->where('workflow_type', $workflowType)
                ->where('created_at', '>=', $weekAgo)
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $completed = $statusCounts[RunStatus::Completed->value] ?? 0;
            $failed = $statusCounts[RunStatus::Failed->value] ?? 0;
            $cancelled = $statusCounts[RunStatus::Cancelled->value] ?? 0;
            $terminated = $statusCounts[RunStatus::Terminated->value] ?? 0;

            $terminalRuns = $completed + $failed + $cancelled + $terminated;
            $passRate = $terminalRuns > 0 ? ($completed / $terminalRuns) * 100 : 0;

            // Preserve the existing upper-middle median without fetching and
            // sorting every completed duration in the application process.
            $durations = self::summaryQuery($namespace, $workflowTypes)
                ->where('workflow_type', $workflowType)
                ->where('status', RunStatus::Completed->value)
                ->where('created_at', '>=', $weekAgo)
                ->whereNotNull('duration_ms');
            $durationCount = (clone $durations)->count();

            $medianDuration = $durationCount > 0
                ? $durations->orderBy('duration_ms')
                    ->offset(intdiv($durationCount, 2))
                    ->value('duration_ms')
                : null;

            // Get error breakdown
            $errorCount = $failed + $cancelled + $terminated;

            $health[] = [
                'workflow_type' => $workflowType,
                'workflow_class' => $workflowType, // Same for now
                'total_runs' => $totalRuns,
                'pass_rate' => round($passRate, 1),
                'median_duration_ms' => $medianDuration,
                'error_count' => $errorCount,
                'status_breakdown' => [
                    'completed' => $completed,
                    'failed' => $failed,
                    'cancelled' => $cancelled,
                    'terminated' => $terminated,
                ],
            ];
        }

        return $health;
    }

    /**
     * Detect workflows and workers that need attention.
     *
     * @return array<string, mixed>
     */
    private static function needsAttention(CarbonInterface $now, ?string $namespace, ?array $workflowTypes): array
    {
        $alerts = [];

        // 1. Stuck workers (no heartbeat in last 5 minutes)
        $staleHeartbeatThreshold = $now->copy()
            ->subMinutes(5);
        $stuckWorkers = WorkerCompatibilityHeartbeat::query()
            ->where('recorded_at', '<', $staleHeartbeatThreshold)
            ->where('recorded_at', '>', $now->copy()->subHour()) // Still recently active
            ->count();

        if ($stuckWorkers > 0) {
            $alerts[] = [
                'type' => 'stuck_workers',
                'severity' => 'warning',
                'message' => "{$stuckWorkers} worker(s) have not sent heartbeat in 5+ minutes",
                'count' => $stuckWorkers,
                'action' => 'Check worker health and restart if needed',
            ];
        }

        // 2. Long-running workflows (running > 1 hour without wait)
        $longRunningThreshold = $now->copy()
            ->subHour();
        $longRunners = self::summaryQuery($namespace, $workflowTypes)
            ->where('status_bucket', 'running')
            ->where('started_at', '<', $longRunningThreshold)
            ->whereNull('wait_started_at') // Not waiting
            ->count();

        if ($longRunners > 0) {
            $alerts[] = [
                'type' => 'long_running',
                'severity' => 'info',
                'message' => "{$longRunners} workflow(s) running longer than 1 hour",
                'count' => $longRunners,
                'action' => 'Review workflow duration expectations',
            ];
        }

        // 3. Retry storms (workflows with 10+ exceptions in last hour)
        $hourAgo = $now->copy()
            ->subHour();
        $retryStorms = self::summaryQuery($namespace, $workflowTypes)
            ->where('status_bucket', 'running')
            ->where('updated_at', '>=', $hourAgo)
            ->where('exception_count', '>=', 10)
            ->count();

        if ($retryStorms > 0) {
            $alerts[] = [
                'type' => 'retry_storm',
                'severity' => 'error',
                'message' => "{$retryStorms} workflow(s) with 10+ exceptions in last hour",
                'count' => $retryStorms,
                'action' => 'Investigate activity failures and fix root cause',
            ];
        }

        // 4. High failure rate in last hour
        $recentFailed = self::summaryQuery($namespace, $workflowTypes)
            ->where('status', RunStatus::Failed->value)
            ->where('closed_at', '>=', $hourAgo)
            ->count();

        $recentCompleted = self::summaryQuery($namespace, $workflowTypes)
            ->where('status', RunStatus::Completed->value)
            ->where('closed_at', '>=', $hourAgo)
            ->count();

        $recentTotal = $recentFailed + $recentCompleted;
        if ($recentTotal >= 10) { // Only alert if we have enough data
            $failureRate = ($recentFailed / $recentTotal) * 100;
            if ($failureRate > 20) { // More than 20% failure rate
                $alerts[] = [
                    'type' => 'high_failure_rate',
                    'severity' => 'error',
                    'message' => sprintf(
                        '%.0f%% failure rate in last hour (%d/%d)',
                        $failureRate,
                        $recentFailed,
                        $recentTotal
                    ),
                    'count' => $recentFailed,
                    'action' => 'Review failed workflows for common patterns',
                ];
            }
        }

        // A long wait can be planned or indefinite. Require a recorded problem,
        // an elapsed operation deadline, or scheduled work overdue for dispatch.
        // Timer fire times are resume boundaries, so allow dispatch grace.
        $dispatchOverdueThreshold = $now->copy()
            ->subMinutes(30);
        $longWaits = self::summaryQuery($namespace, $workflowTypes)
            ->where('status_bucket', 'running')
            ->whereNotNull('wait_started_at')
            ->where(static function ($query) use ($now, $dispatchOverdueThreshold): void {
                $query->where('task_problem', true)
                    ->orWhere('repair_attention', true)
                    ->orWhere(static function ($deadline) use ($now): void {
                        $deadline->whereIn('wait_kind', ['activity', 'child', 'signal', 'condition', 'update'])
                            ->where('wait_deadline_at', '<=', $now);
                    })
                    ->orWhere(static function ($ready) use ($dispatchOverdueThreshold): void {
                        $ready->where('next_task_status', 'ready')
                            ->where('next_task_at', '<', $dispatchOverdueThreshold);
                    })
                    ->orWhere(static function ($timer) use ($dispatchOverdueThreshold): void {
                        $timer->where('wait_kind', 'timer')
                            ->where('wait_deadline_at', '<', $dispatchOverdueThreshold)
                            ->where(static function ($task): void {
                                $task->whereNull('next_task_status')
                                    ->orWhere('next_task_status', '!=', 'leased');
                            });
                    });
            })
            ->count();

        if ($longWaits > 0) {
            $alerts[] = [
                'type' => 'long_waits',
                'severity' => 'warning',
                'message' => "{$longWaits} workflow(s) with blocked or overdue waits",
                'count' => $longWaits,
                'action' => 'Inspect wait deadlines and task dispatch before recovering work',
            ];
        }

        return [
            'alerts' => $alerts,
            'total_alerts' => count($alerts),
            'has_critical' => collect($alerts)
                ->contains('severity', 'error'),
        ];
    }

    private static function totalFlows(?string $namespace, ?array $workflowTypes): int
    {
        return self::summaryQuery($namespace, $workflowTypes)->count();
    }

    private static function flowsPastHour(CarbonInterface $now, ?string $namespace, ?array $workflowTypes): int
    {
        $cutoff = $now->copy()
            ->subHour();

        return self::summaryQuery($namespace, $workflowTypes)
            ->where(static function ($query) use ($cutoff): void {
                $query->where('sort_timestamp', '>=', $cutoff)
                    ->orWhere(static function ($fallback) use ($cutoff): void {
                        $fallback->whereNull('sort_timestamp')
                            ->where('created_at', '>=', $cutoff);
                    });
            })
            ->count();
    }

    private static function exceptionsPastHour(CarbonInterface $now, ?string $namespace, ?array $workflowTypes): int
    {
        $query = self::failureModel()::query()
            ->where('created_at', '>=', $now->copy()->subHour())
            ->whereHas('run', static function ($run) use ($namespace, $workflowTypes): void {
                if ($namespace !== null) {
                    $run->where('namespace', $namespace);
                }
                if ($workflowTypes !== null) {
                    $run->whereIn('workflow_type', $workflowTypes);
                }
            });

        return $query->count();
    }

    private static function failedFlowsPastWeek(CarbonInterface $now, ?string $namespace, ?array $workflowTypes): int
    {
        return self::summaryQuery($namespace, $workflowTypes)
            ->where('status', RunStatus::Failed->value)
            ->where('updated_at', '>=', $now->copy()->subDays(7))
            ->count();
    }

    private static function maxWaitTimeWorkflow(?string $namespace, ?array $workflowTypes): ?WorkflowRunSummary
    {
        return self::summaryQuery($namespace, $workflowTypes)
            ->where('status_bucket', 'running')
            ->whereNotNull('wait_started_at')
            ->orderBy('wait_started_at')
            ->orderBy('sort_timestamp')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    private static function maxDurationWorkflow(?string $namespace, ?array $workflowTypes): ?WorkflowRunSummary
    {
        return self::summaryQuery($namespace, $workflowTypes)
            ->whereNotNull('duration_ms')
            ->orderByDesc('duration_ms')
            ->orderByDesc('sort_timestamp')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private static function maxExceptionsWorkflow(?string $namespace, ?array $workflowTypes): ?WorkflowRunSummary
    {
        return self::summaryQuery($namespace, $workflowTypes)
            ->where('exception_count', '>', 0)
            ->orderByDesc('exception_count')
            ->orderByDesc('sort_timestamp')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function modelArray(?WorkflowRunSummary $summary): ?array
    {
        return $summary?->toArray();
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

    private static function summaryQuery(?string $namespace, ?array $workflowTypes = null)
    {
        $model = self::summaryModel();
        $query = $model::query();

        if ($namespace !== null) {
            $query->where((new $model())->getTable() . '.namespace', $namespace);
        }
        if ($workflowTypes !== null) {
            $query->whereIn((new $model())->getTable() . '.workflow_type', $workflowTypes);
        }

        return $query;
    }

    private static function normalizeNamespace(?string $namespace): ?string
    {
        if ($namespace === null || trim($namespace) === '') {
            return null;
        }

        return trim($namespace);
    }

    /**
     * @return class-string<WorkflowFailure>
     */
    private static function failureModel(): string
    {
        /** @var class-string<WorkflowFailure> $model */
        $model = config('workflows.v2.failure_model', WorkflowFailure::class);

        return $model;
    }
}
