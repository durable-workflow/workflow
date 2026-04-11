<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\awaitSignal;
use Workflow\V2\Workflow;

#[Type('test-command-target-workflow')]
#[Signal('approved-by', [
    ['name' => 'actor', 'type' => 'string'],
])]
#[Signal('rejected-by')]
final class TestCommandTargetWorkflow extends Workflow
{
    public function handle(): array
    {
        awaitSignal('approved-by');

        return [
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[QueryMethod('approval-stage')]
    public function approvalStage(): string
    {
        return 'waiting-for-approval';
    }

    #[QueryMethod]
    public function approvalMatches(string $stage): bool
    {
        return $stage === 'waiting-for-approval';
    }

    #[UpdateMethod('mark-approved')]
    public function approve(bool $approved): array
    {
        return ['approved' => $approved];
    }
}
