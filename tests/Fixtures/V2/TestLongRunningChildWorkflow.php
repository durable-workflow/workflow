<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Type('test-long-running-child')]
#[Signal('finish')]
final class TestLongRunningChildWorkflow extends Workflow
{
    public function handle(): array
    {
        $signal = signal('finish');

        return [
            'signal' => $signal,
            'workflow_id' => $this->workflowId(),
        ];
    }
}
