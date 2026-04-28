<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;

/**
 * Binding seam for the history/projection role.
 *
 * Claim and command paths use this contract when they must synchronously
 * record durable history side effects and refresh operator-visible
 * projections without hard-coding the in-process implementation.
 */
interface HistoryProjectionRole
{
    public function projectRun(WorkflowRun $run): WorkflowRunSummary;

    public function recordActivityStarted(
        WorkflowRun $run,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        WorkflowTask $task,
    ): WorkflowRunSummary;

    /**
     * @param list<string> $runIds
     * @return array{
     *     run_summaries_pruned: int,
     *     run_summaries_would_prune: int,
     *     run_waits_pruned: int,
     *     run_waits_would_prune: int,
     *     run_timeline_entries_pruned: int,
     *     run_timeline_entries_would_prune: int,
     *     run_timer_entries_pruned: int,
     *     run_timer_entries_would_prune: int,
     *     run_lineage_entries_pruned: int,
     *     run_lineage_entries_would_prune: int
     * }
     */
    public function pruneStaleProjections(
        array $runIds = [],
        ?string $instanceId = null,
        bool $dryRun = false,
    ): array;
}
