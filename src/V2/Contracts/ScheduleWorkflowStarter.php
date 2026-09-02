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
 *
 * @internal Accepts the {@see WorkflowSchedule} Eloquent model directly. The
 *           model snapshot is expected to have fully hydrated `action`,
 *           `overlap_policy`, `memo`, `search_attributes`, `visibility_labels`,
 *           and `namespace` fields. This contract is only meaningful to hosts
 *           that already depend on the workflow PHP package and is not part of
 *           the stable v2 cross-language API. Standalone and polyglot consumers
 *           that cannot load Eloquent models should drive schedule starts
 *           through the control plane HTTP surface instead.
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
