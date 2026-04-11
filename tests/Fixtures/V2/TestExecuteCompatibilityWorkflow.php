<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-execute-compatibility-workflow')]
final class TestExecuteCompatibilityWorkflow extends Workflow
{
    private ?string $greeting = null;

    public function execute(string $name): array
    {
        $this->greeting = activity(TestExecuteCompatibilityActivity::class, $name);

        return [
            'greeting' => $this->greeting,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[QueryMethod('greeting')]
    public function greeting(): ?string
    {
        return $this->greeting;
    }
}
