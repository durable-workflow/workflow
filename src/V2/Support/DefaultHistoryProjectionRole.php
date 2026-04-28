<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;

final class DefaultHistoryProjectionRole implements HistoryProjectionRole
{
    public function projectRun(WorkflowRun $run): WorkflowRunSummary
    {
        return RunSummaryProjector::project($run);
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

        return $this->projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']) ?? $run);
    }
}
