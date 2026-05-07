<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Workflow;
use function Workflow\V2\localActivity;

final class TestLocalActivityWorkflow extends Workflow
{
    /**
     * @return array<string, mixed>
     */
    public function handle(string $name): array
    {
        return [
            'greeting' => localActivity(TestGreetingActivity::class, $name),
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
