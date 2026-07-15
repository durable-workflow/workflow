<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\localActivity;
use Workflow\V2\Workflow;

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
