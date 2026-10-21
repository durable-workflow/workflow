<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Models\WorkflowTask;

/** @internal Results accumulated during one parallel command dispatch. */
final class WorkflowParallelDispatch
{
    /**
     * @var list<WorkflowTask>
     */
    public array $scheduledTasks = [];

    public bool $pending = false;

    /**
     * @var array<int, mixed>
     */
    public array $results = [];

    public ?array $failure = null;

    /**
     * @var array<int, \Throwable>
     */
    public array $failures = [];

    public ?CarbonInterface $successTime = null;

    public function __construct(
        public readonly int $baseSequence
    ) {
    }
}
