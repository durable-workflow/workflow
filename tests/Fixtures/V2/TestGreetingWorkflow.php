<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-greeting-workflow')]
final class TestGreetingWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        $greeting = activity(TestGreetingActivity::class, $name);

        return [
            'greeting' => $greeting,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
