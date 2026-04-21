<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-execute-entry-workflow')]
final class TestExecuteEntryWorkflow extends Workflow
{
    public function execute(string $name): array
    {
        return [
            'greeting' => "Hello, {$name}!",
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[QueryMethod('greeting')]
    public function greeting(): ?string
    {
        return null;
    }
}
