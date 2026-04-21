<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Carbon\CarbonInterface;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

/**
 * Repository contract for operator-facing observability payloads (Waterline
 * detail, list projection, history export, dashboard, metrics).
 *
 * @internal Accepts Eloquent models ({@see WorkflowRun}, {@see WorkflowRunSummary})
 *           as arguments and is coupled to the package's own model hierarchy.
 *           Intended for Waterline and v2 polyglot-server integrations that
 *           already run on top of the workflow package's PHP runtime; not a
 *           stable cross-language contract and not subject to the v2
 *           backwards-compatibility guarantee. Use the HTTP surfaces
 *           (Waterline API, `/webhooks/...`) for external consumers instead.
 */
interface OperatorObservabilityRepository
{
    /**
     * @return array<string, mixed>
     */
    public function runDetail(WorkflowRun $run, ?int $timelineLimit = null): array;

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
    public function dashboardSummary(?CarbonInterface $now = null, ?string $namespace = null): array;

    /**
     * @return array<string, mixed>
     */
    public function metrics(?CarbonInterface $now = null, ?string $namespace = null): array;
}
