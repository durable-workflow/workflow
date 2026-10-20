<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Workflow;

/** @internal One task's dispatch state. Never retain this context in the continuation cache. */
final class WorkflowTaskExecution
{
    /**
     * @param class-string<Workflow> $workflowClass
     */
    public function __construct(
        public readonly WorkflowRun $run,
        public readonly WorkflowTask $task,
        public readonly WorkflowContinuation $frame,
        public readonly VersionDecisions $versionDecisions,
        public readonly string $workflowClass,
        public readonly int $historySequenceAtTaskStart,
    ) {
    }
}
