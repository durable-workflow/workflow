<?php

declare(strict_types=1);

namespace Workflow\V2;

use Workflow\Traits\ResolvesMethodDependencies;
use Workflow\V2\Models\WorkflowRun;

abstract class Workflow
{
    use ResolvesMethodDependencies;

    public ?string $connection = null;

    public ?string $queue = null;

    final public function __construct(
        public readonly WorkflowRun $run,
    ) {
    }

    public function workflowId(): string
    {
        return $this->run->workflow_instance_id;
    }

    public function runId(): string
    {
        return $this->run->id;
    }
}
