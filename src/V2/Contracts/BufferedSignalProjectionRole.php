<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

/** Optional bounded projection for a signal buffered behind an existing one. */
interface BufferedSignalProjectionRole extends HistoryProjectionRole
{
    public function bufferedSignalSummary(WorkflowRun $run): ?WorkflowRunSummary;

    public function projectBufferedSignal(
        WorkflowRun $run,
        WorkflowHistoryEvent $event,
        WorkflowRunSummary $summary,
    ): WorkflowRunSummary;
}
