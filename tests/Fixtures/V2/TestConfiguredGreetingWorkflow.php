<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Workflow;

final class TestConfiguredGreetingWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        $greeting = activity(TestConfiguredGreetingActivity::class, $name);

        return [
            'greeting' => $greeting,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
