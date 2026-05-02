<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\HistoryProjectionMaintenanceRole;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;

/**
 * Adapter used when the configured HistoryProjectionRole binding does not also
 * implement the maintenance contract. Projection-write methods route to the
 * configured role; maintenance methods route to the supplied fallback.
 */
final class HistoryProjectionMaintenanceFallback implements HistoryProjectionMaintenanceRole
{
    public function __construct(
        private readonly HistoryProjectionRole $delegate,
        private readonly HistoryProjectionMaintenanceRole $maintenance,
    ) {
    }

    public function projectRun(WorkflowRun $run): WorkflowRunSummary
    {
        return $this->delegate->projectRun($run);
    }

    public function recordActivityStarted(
        WorkflowRun $run,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        WorkflowTask $task,
    ): WorkflowRunSummary {
        return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
    }

    public function pruneStaleProjections(array $runIds = [], ?string $instanceId = null, bool $dryRun = false): array
    {
        return $this->maintenance->pruneStaleProjections($runIds, $instanceId, $dryRun);
    }

    public function pruneStaleProjectionRowsForRun(
        string $projectionModel,
        string $runId,
        array $seenProjectionIds,
    ): void {
        $this->maintenance->pruneStaleProjectionRowsForRun($projectionModel, $runId, $seenProjectionIds);
    }
}
