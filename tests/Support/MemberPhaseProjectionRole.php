<?php

declare(strict_types=1);

namespace Tests\Support;

use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\LifecycleEventDispatcher;
use Workflow\V2\Support\ParallelChildGroup;

/**
 * Records member history exactly like the default role but skips the
 * operator-visible projection refresh, so a scaling probe can complete
 * hundreds of fan-out members between the workflow tasks it measures.
 *
 * Each member task still rebuilds its run's summary from the whole run
 * (#1231 follow-up). The probe projects the run once before the final task.
 */
final class MemberPhaseProjectionRole implements HistoryProjectionRole
{
    public function projectRun(WorkflowRun $run): WorkflowRunSummary
    {
        /** @var WorkflowRunSummary|null $summary */
        $summary = WorkflowRunSummary::query()->find($run->id);

        return $summary ?? new WorkflowRunSummary([
            'id' => $run->id,
        ]);
    }

    public function recordActivityStarted(
        WorkflowRun $run,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        WorkflowTask $task,
    ): WorkflowRunSummary {
        $parallelMetadataPath = ParallelChildGroup::metadataPathForSequence($run, (int) $execution->sequence);
        $parallelMetadata = ParallelChildGroup::payloadForPath($parallelMetadataPath);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityStarted, array_merge([
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attempt->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'attempt_number' => $attempt->attempt_number,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ], $parallelMetadata ?? []), $task);

        LifecycleEventDispatcher::activityStarted(
            $run,
            (string) $execution->id,
            (string) ($execution->activity_type ?? $execution->activity_class),
            (string) $execution->activity_class,
            (int) $execution->sequence,
            (int) $attempt->attempt_number,
        );

        return $this->projectRun($run);
    }
}
