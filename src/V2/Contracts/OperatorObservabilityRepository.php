<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Carbon\CarbonInterface;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

interface OperatorObservabilityRepository
{
    /**
     * @return array<string, mixed>
     */
    public function runDetail(WorkflowRun $run): array;

    /**
     * Project a summary row into the typed list-item contract.
     *
     * @return array<string, mixed>
     */
    public function listItem(WorkflowRunSummary $summary): array;

    /**
     * @return array<string, mixed>
     */
    public function runHistoryExport(
        WorkflowRun $run,
        ?CarbonInterface $exportedAt = null,
        HistoryExportRedactor|callable|null $redactor = null,
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function dashboardSummary(?CarbonInterface $now = null): array;

    /**
     * @return array<string, mixed>
     */
    public function metrics(?CarbonInterface $now = null): array;
}
