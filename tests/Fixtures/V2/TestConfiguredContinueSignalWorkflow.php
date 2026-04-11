<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use function Workflow\V2\awaitSignal;
use function Workflow\V2\continueAsNew;
use Workflow\V2\Workflow;

#[Signal('name-provided', [
    [
        'name' => 'name',
        'type' => 'string',
    ],
])]
final class TestConfiguredContinueSignalWorkflow extends Workflow
{
    private int $count = 0;

    public function handle(int $count = 0): mixed
    {
        $this->count = $count;

        if ($count === 0) {
            return continueAsNew($count + 1);
        }

        $name = awaitSignal('name-provided');

        return [
            'name' => $name,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[QueryMethod('current-count')]
    public function currentCount(): int
    {
        return $this->count;
    }

    #[UpdateMethod('mark-approved')]
    public function approve(bool $approved): array
    {
        return [
            'approved' => $approved,
        ];
    }
}
