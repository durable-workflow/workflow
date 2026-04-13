<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use function Workflow\V2\awaitSignal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-long-running-child')]
#[Signal('finish')]
final class TestLongRunningChildWorkflow extends Workflow
{
    public function handle(): array
    {
        $signal = awaitSignal('finish');

        return [
            'signal' => $signal,
            'workflow_id' => $this->workflowId(),
        ];
    }
}
