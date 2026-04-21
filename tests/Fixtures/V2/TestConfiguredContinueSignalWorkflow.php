<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use function Workflow\V2\continueAsNew;
use function Workflow\V2\signal;
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

    private bool $approved = false;

    public function handle(int $count = 0): mixed
    {
        $this->count = $count;

        if ($count === 0) {
            return continueAsNew($count + 1);
        }

        $name = signal('name-provided');

        return [
            'name' => $name,
            'approved' => $this->approved,
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
        $this->approved = $approved;

        return [
            'approved' => $approved,
            'count' => $this->count,
        ];
    }

    #[QueryMethod('current-approval')]
    public function currentApproval(): array
    {
        return [
            'approved' => $this->approved,
            'count' => $this->count,
        ];
    }
}
