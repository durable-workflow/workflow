<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;

#[Type('test-activity-options-workflow')]
final class TestActivityOptionsWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        $defaultResult = activity(TestGreetingActivity::class, $name);

        $customResult = activity(
            TestGreetingActivity::class,
            new ActivityOptions(
                connection: 'custom-conn',
                queue: 'high-priority',
                maxAttempts: 5,
                backoff: [1, 5, 15],
            ),
            $name,
        );

        return [
            'default' => $defaultResult,
            'custom' => $customResult,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
