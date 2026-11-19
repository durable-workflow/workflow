<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Workflow;

#[Type('test-signal-external-target-workflow')]
#[Signal('approve')]
final class TestSignalExternalTargetWorkflow extends Workflow
{
    public function handle(): array
    {
        return [
            'approved' => await('approve'),
            'workflow_id' => $this->workflowId(),
        ];
    }
}
