<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;

/** Optional bounded projection path for redispatching an existing workflow task. */
interface WorkflowTaskRepairProjectionRole
{
    public function projectRepairedWorkflowTask(WorkflowRun $run, WorkflowTask $task): WorkflowRunSummary;
}
