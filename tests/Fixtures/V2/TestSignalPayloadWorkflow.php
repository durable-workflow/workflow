<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Workflow;

#[Type('test-signal-payload-workflow')]
#[Signal('payload-provided')]
final class TestSignalPayloadWorkflow extends Workflow
{
    public function handle(): array
    {
        $payload = await('payload-provided');

        return [
            'payload' => $payload,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
