<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-scheduled-workflow')]
final class TestScheduledWorkflow extends Workflow
{
    public function handle(string $batchId = 'default'): array
    {
        return [
            'batch_id' => $batchId,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
