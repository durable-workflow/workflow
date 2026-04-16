<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Type('test-signal-payload-workflow')]
#[Signal('payload-provided')]
final class TestSignalPayloadWorkflow extends Workflow
{
    public function handle(): array
    {
        $payload = signal('payload-provided');

        return [
            'payload' => $payload,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
