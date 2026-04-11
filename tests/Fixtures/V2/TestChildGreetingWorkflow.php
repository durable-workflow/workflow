<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-child-greeting-workflow')]
final class TestChildGreetingWorkflow extends Workflow
{
    public function execute(string $name): array
    {
        $greeting = activity(TestGreetingActivity::class, $name);

        return [
            'greeting' => $greeting,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
