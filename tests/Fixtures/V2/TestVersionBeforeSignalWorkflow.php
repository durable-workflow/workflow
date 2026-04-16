<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

#[Type('test-version-after-signal-workflow')]
#[Signal('go')]
final class TestVersionBeforeSignalWorkflow extends Workflow
{
    public function handle(): array
    {
        $gate = signal('go');

        $result = activity(TestVersionedActivityV1::class);

        return [
            'gate' => $gate,
            'version' => WorkflowStub::DEFAULT_VERSION,
            'result' => $result,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
