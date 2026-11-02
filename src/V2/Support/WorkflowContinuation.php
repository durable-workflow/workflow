<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Workflow;

/** @internal A suspended authoring frame, without a job, task or transaction. */
final class WorkflowContinuation
{
    /**
     * @var list<string>
     */
    public array $prefix = [];

    public string $guard = '';

    public string $scope = '';

    public int $retainedAt = 0;

    public function __construct(
        public readonly Workflow $workflow,
        public readonly WorkflowExecution $execution,
        public mixed $current,
        public int $sequence = 1,
    ) {
    }
}
