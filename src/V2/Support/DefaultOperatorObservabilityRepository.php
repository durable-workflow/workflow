<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Contracts\HistoryExportRedactor;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

final class DefaultOperatorObservabilityRepository implements OperatorObservabilityRepository
{
    /**
     * @return array<string, mixed>
     */
    public function runDetail(WorkflowRun $run, ?int $timelineLimit = null): array
    {
        return RunDetailView::forRun($run, $timelineLimit);
    }

    /**
     * @return array<string, mixed>
     */
    public function listItem(WorkflowRunSummary $summary): array
    {
        return RunListItemView::fromSummary($summary);
    }

    /**
     * @return array<string, mixed>
     */
    public function runHistoryExport(
        WorkflowRun $run,
        ?CarbonInterface $exportedAt = null,
        HistoryExportRedactor|callable|null $redactor = null,
    ): array {
        return HistoryExport::forRun($run, $exportedAt, $redactor);
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardSummary(?CarbonInterface $now = null, ?string $namespace = null): array
    {
        return OperatorDashboardSummary::snapshot($now, $namespace);
    }

    /**
     * Dashboard reads omit the fleet-wide history audit. Audit-derived counts
     * stay unknown; the ordinary metrics method retains its complete audit.
     *
     * @return array<string, mixed>
     */
    public function boundedDashboardSummary(?CarbonInterface $now = null, ?string $namespace = null): array
    {
        return OperatorDashboardSummary::snapshot($now, $namespace, includeHistoryAudits: false);
    }

    /**
     * Filter workflow volume in SQL while retaining namespace-wide operational
     * metrics. An empty type list means no matching workflows.
     *
     * @param list<string> $workflowTypes
     * @return array<string, mixed>
     */
    public function workflowTypeDashboardSummary(
        array $workflowTypes,
        ?CarbonInterface $now = null,
        ?string $namespace = null,
    ): array {
        return OperatorDashboardSummary::snapshot(
            $now,
            $namespace,
            includeHistoryAudits: false,
            workflowTypes: $workflowTypes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function metrics(?CarbonInterface $now = null, ?string $namespace = null): array
    {
        return OperatorMetrics::snapshot($now, $namespace);
    }
}
