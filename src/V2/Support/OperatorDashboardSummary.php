<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowRunSummary;

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
            'operator_metrics' => OperatorMetrics::snapshot($now),
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
