<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-parallel-child-replay-workflow')]
final class TestParallelChildReplayWorkflow extends Workflow
{
    public function handle(string $firstName, string $secondName): array
    {
        $children = all([
            static fn () => child(TestChildGreetingWorkflow::class, $firstName),
            static fn () => child(TestChildGreetingWorkflow::class, $secondName),
        ]);

        return [
            'children' => $children,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
