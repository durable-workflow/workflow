<?php

declare(strict_types=1);

namespace Tests\Support;

use Workflow\V2\Contracts\BufferedSignalProjectionRole;
use Workflow\V2\Contracts\HistoryProjectionMaintenanceRole;
use Workflow\V2\Contracts\WorkflowTaskRepairProjectionRole;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultHistoryProjectionRole;
use Workflow\V2\Support\WorkflowExecutionProfile;

/** Test-only delegate preserving the existing history/projection contracts. */
final class ContinuationHistoryProfile implements HistoryProjectionMaintenanceRole, BufferedSignalProjectionRole, WorkflowTaskRepairProjectionRole
{
    public function __construct(
        private readonly DefaultHistoryProjectionRole $delegate
    ) {
    }

    public function projectRun(WorkflowRun $run): WorkflowRunSummary
    {
        return $this->measure(fn () => $this->delegate->projectRun($run));
    }

    public function recordActivityStarted(
        WorkflowRun $run,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        WorkflowTask $task
    ): WorkflowRunSummary {
        return $this->measure(fn () => $this->delegate->recordActivityStarted($run, $execution, $attempt, $task));
    }

    public function projectRepairedWorkflowTask(WorkflowRun $run, WorkflowTask $task): WorkflowRunSummary
    {
        return $this->measure(fn () => $this->delegate->projectRepairedWorkflowTask($run, $task));
    }

    public function bufferedSignalSummary(WorkflowRun $run): ?WorkflowRunSummary
    {
        return $this->delegate->bufferedSignalSummary($run);
    }

    public function projectBufferedSignal(
        WorkflowRun $run,
        WorkflowHistoryEvent $event,
        WorkflowRunSummary $summary
    ): WorkflowRunSummary {
        return $this->delegate->projectBufferedSignal($run, $event, $summary);
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $projectionModel
     * @param list<string> $seenProjectionIds
     */
    public function pruneStaleProjectionRowsForRun(
        string $projectionModel,
        string $runId,
        array $seenProjectionIds
    ): void {
        $this->delegate->pruneStaleProjectionRowsForRun($projectionModel, $runId, $seenProjectionIds);
    }

    /**
     * @param list<string> $runIds
     * @return array{
     *     run_summaries: int,
     *     run_waits: int,
     *     run_timeline_entries: int,
     *     run_timer_entries: int,
     *     run_lineage_entries: int
     * }
     */
    public function pruneStaleProjections(array $runIds = [], ?string $instanceId = null, bool $dryRun = false): array
    {
        return $this->delegate->pruneStaleProjections($runIds, $instanceId, $dryRun);
    }

    /**
     * @param callable(): WorkflowRunSummary $operation
     */
    private function measure(callable $operation): WorkflowRunSummary
    {
        $sample = WorkflowExecutionProfile::begin('history_projection');
        try {
            return $operation();
        } finally {
            WorkflowExecutionProfile::finish($sample);
        }
    }
}
