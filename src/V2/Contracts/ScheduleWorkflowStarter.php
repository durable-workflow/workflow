<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use DateTimeInterface;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Support\ScheduleStartResult;

/**
 * Starts a workflow run on behalf of a schedule tick.
 *
 * The default implementation ({@see \Workflow\V2\Support\PhpClassScheduleStarter})
 * uses in-process PHP workflow classes via `WorkflowStub::make()`. Hosts that
 * run language-neutral workflows (e.g. the standalone server) bind their own
 * implementation that starts workflows by `workflow_type` through a control plane.
 */
interface ScheduleWorkflowStarter
{
    public function start(
        WorkflowSchedule $schedule,
        ?DateTimeInterface $occurrenceTime,
        string $outcome,
        ?string $effectiveOverlapPolicy = null,
    ): ScheduleStartResult;
}
