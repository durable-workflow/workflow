<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowWorkerCompatibilityHeartbeat;

final class OperatorDashboardSummary
{
    /**
     * @return array<string, mixed>
     */
    public static function snapshot(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $flowsPastHour = self::flowsPastHour($now);

        return [
            'flows' => self::totalFlows(),
            'flows_per_minute' => $flowsPastHour / 60,
            'flows_past_hour' => $flowsPastHour,
            'exceptions_past_hour' => self::exceptionsPastHour($now),
            'failed_flows_past_week' => self::failedFlowsPastWeek($now),
            'max_wait_time_workflow' => self::modelArray(self::maxWaitTimeWorkflow()),
            'max_duration_workflow' => self::modelArray(self::maxDurationWorkflow()),
            'max_exceptions_workflow' => self::modelArray(self::maxExceptionsWorkflow()),
            'fleet_overview' => self::fleetOverview($now),
            'workflow_type_health' => self::workflowTypeHealth($now),
            'needs_attention' => self::needsAttention($now),
            'operator_metrics' => OperatorMetrics::snapshot($now),
        ];
    }

    /**
     * Fleet overview with status breakdown and trends.
     *
     * @return array<string, mixed>
     */
    private static function fleetOverview(CarbonInterface $now): array
    {
        $hourAgo = $now->copy()->subHour();
        $dayAgo = $now->copy()->subDay();
        $weekAgo = $now->copy()->subWeek();

        // Current counts by status bucket
        $currentCounts = self::summaryModel()::query()
            ->select('status_bucket', DB::raw('COUNT(*) as count'))
            ->where('status_bucket', '!=', 'completed') // Only active statuses
            ->groupBy('status_bucket')
            ->pluck('count', 'status_bucket')
            ->toArray();

        // Trend over last hour (completed in last hour)
        $hourTrend = self::summaryModel()::query()
            ->select('status_bucket', DB::raw('COUNT(*) as count'))
            ->where('closed_at', '>=', $hourAgo)
            ->whereIn('status_bucket', ['completed', 'failed'])
            ->groupBy('status_bucket')
            ->pluck('count', 'status_bucket')
            ->toArray();

        // Trend over last day
        $dayTrend = self::summaryModel()::query()
            ->select('status_bucket', DB::raw('COUNT(*) as count'))
            ->where('closed_at', '>=', $dayAgo)
            ->whereIn('status_bucket', ['completed', 'failed'])
            ->groupBy('status_bucket')
            ->pluck('count', 'status_bucket')
            ->toArray();

        // Trend over last week
        $weekTrend = self::summaryModel()::query()
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
    private static function workflowTypeHealth(CarbonInterface $now): array
    {
        $weekAgo = $now->copy()->subWeek();

        // Get top 10 workflow types by volume in the last week
        $types = self::summaryModel()::query()
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
            $statusCounts = self::summaryModel()::query()
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

            // Get median duration for completed runs
            $durations = self::summaryModel()::query()
                ->where('workflow_type', $workflowType)
                ->where('status', RunStatus::Completed->value)
                ->where('created_at', '>=', $weekAgo)
                ->whereNotNull('duration_ms')
                ->pluck('duration_ms')
                ->sort()
                ->values();

            $medianDuration = $durations->count() > 0
                ? $durations->get((int) ($durations->count() / 2))
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
    private static function needsAttention(CarbonInterface $now): array
    {
        $alerts = [];

        // 1. Stuck workers (no heartbeat in last 5 minutes)
        $staleHeartbeatThreshold = $now->copy()->subMinutes(5);
        $stuckWorkers = WorkflowWorkerCompatibilityHeartbeat::query()
            ->where('last_heartbeat_at', '<', $staleHeartbeatThreshold)
            ->where('last_heartbeat_at', '>', $now->copy()->subHour()) // Still recently active
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
        $longRunningThreshold = $now->copy()->subHour();
        $longRunners = self::summaryModel()::query()
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
        $hourAgo = $now->copy()->subHour();
        $retryStorms = self::summaryModel()::query()
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
        $recentFailed = self::summaryModel()::query()
            ->where('status', RunStatus::Failed->value)
            ->where('closed_at', '>=', $hourAgo)
            ->count();

        $recentCompleted = self::summaryModel()::query()
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
                    'message' => sprintf('%.0f%% failure rate in last hour (%d/%d)', $failureRate, $recentFailed, $recentTotal),
                    'count' => $recentFailed,
                    'action' => 'Review failed workflows for common patterns',
                ];
            }
        }

        // 5. Workflows waiting too long (wait > 30 minutes)
        $longWaitThreshold = $now->copy()->subMinutes(30);
        $longWaits = self::summaryModel()::query()
            ->where('status_bucket', 'running')
            ->whereNotNull('wait_started_at')
            ->where('wait_started_at', '<', $longWaitThreshold)
            ->count();

        if ($longWaits > 0) {
            $alerts[] = [
                'type' => 'long_waits',
                'severity' => 'warning',
                'message' => "{$longWaits} workflow(s) waiting longer than 30 minutes",
                'count' => $longWaits,
                'action' => 'Check if signals/updates are being delivered',
            ];
        }

        return [
            'alerts' => $alerts,
            'total_alerts' => count($alerts),
            'has_critical' => collect($alerts)->contains('severity', 'error'),
        ];
    }

    private static function totalFlows(): int
    {
        return self::summaryModel()::query()->count();
    }

    private static function flowsPastHour(CarbonInterface $now): int
    {
        $cutoff = $now->copy()
            ->subHour();

        return self::summaryModel()::query()
            ->where(static function ($query) use ($cutoff): void {
                $query->where('sort_timestamp', '>=', $cutoff)
                    ->orWhere(static function ($fallback) use ($cutoff): void {
                        $fallback->whereNull('sort_timestamp')
                            ->where('created_at', '>=', $cutoff);
                    });
            })
            ->count();
    }

    private static function exceptionsPastHour(CarbonInterface $now): int
    {
        return self::failureModel()::query()
            ->where('created_at', '>=', $now->copy()->subHour())
            ->count();
    }

    private static function failedFlowsPastWeek(CarbonInterface $now): int
    {
        return self::summaryModel()::query()
            ->where('status', RunStatus::Failed->value)
            ->where('updated_at', '>=', $now->copy()->subDays(7))
            ->count();
    }

    private static function maxWaitTimeWorkflow(): ?WorkflowRunSummary
    {
        return self::summaryModel()::query()
            ->where('status_bucket', 'running')
            ->whereNotNull('wait_started_at')
            ->orderBy('wait_started_at')
            ->orderBy('sort_timestamp')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    private static function maxDurationWorkflow(): ?WorkflowRunSummary
    {
        return self::summaryModel()::query()
            ->whereNotNull('duration_ms')
            ->orderByDesc('duration_ms')
            ->orderByDesc('sort_timestamp')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private static function maxExceptionsWorkflow(): ?WorkflowRunSummary
    {
        return self::summaryModel()::query()
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
