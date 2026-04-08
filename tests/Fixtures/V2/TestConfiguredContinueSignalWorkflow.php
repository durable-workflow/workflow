<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use function Workflow\V2\awaitSignal;
use function Workflow\V2\continueAsNew;
use Workflow\V2\Workflow;

#[Signal('name-provided', [
    ['name' => 'name', 'type' => 'string'],
])]
final class TestConfiguredContinueSignalWorkflow extends Workflow
{
    public function execute(int $count = 0): Generator
    {
        if ($count === 0) {
            return yield continueAsNew($count + 1);
        }

        $name = yield awaitSignal('name-provided');

        return [
            'name' => $name,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[UpdateMethod('mark-approved')]
    public function approve(bool $approved): array
    {
        return ['approved' => $approved];
    }
}
