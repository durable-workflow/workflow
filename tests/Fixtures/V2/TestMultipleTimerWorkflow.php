<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

#[Type('test-multiple-timer-workflow')]
final class TestMultipleTimerWorkflow extends Workflow
{
    public function handle(int $firstSeconds, int $secondSeconds): array
    {
        timer($firstSeconds);
        timer($secondSeconds);

        return [
            'timers_completed' => true,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
