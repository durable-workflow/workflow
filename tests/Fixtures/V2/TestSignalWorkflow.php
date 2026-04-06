<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\awaitSignal;
use Workflow\V2\Workflow;

#[Type('test-signal-workflow')]
final class TestSignalWorkflow extends Workflow
{
    public function execute(): Generator
    {
        $name = yield awaitSignal('name-provided');
        $greeting = yield activity(TestGreetingActivity::class, $name);

        return [
            'name' => $name,
            'greeting' => $greeting,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
