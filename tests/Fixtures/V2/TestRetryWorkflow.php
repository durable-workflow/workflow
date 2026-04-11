<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Workflow;

use function Workflow\V2\activity;

final class TestRetryWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        $result = activity(TestRetryActivity::class, $name);

        return [
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
            'activity' => $result,
        ];
    }
}
