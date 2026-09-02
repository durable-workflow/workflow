<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Type('test-signal-workflow')]
#[Signal('name-provided')]
final class TestSignalWorkflow extends Workflow
{
    public function handle(): array
    {
        $name = signal('name-provided');
        $greeting = activity(TestGreetingActivity::class, $name);

        return [
            'name' => $name,
            'greeting' => $greeting,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
