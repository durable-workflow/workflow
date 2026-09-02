<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-sequential-child-replay-workflow')]
final class TestSequentialChildReplayWorkflow extends Workflow
{
    public function handle(string $firstName, string $secondName): array
    {
        $first = child(TestChildGreetingWorkflow::class, $firstName);
        $second = child(TestChildGreetingWorkflow::class, $secondName);

        return [
            'children' => [$first, $second],
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
