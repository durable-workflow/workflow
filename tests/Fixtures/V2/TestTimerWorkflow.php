<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

final class TestTimerWorkflow extends Workflow
{
    public function execute(int $seconds): Generator
    {
        yield timer($seconds);

        return [
            'waited' => true,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
