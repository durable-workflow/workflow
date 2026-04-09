<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;
use function Workflow\V2\activity;
use function Workflow\V2\awaitSignal;

#[Type('test-version-after-signal-workflow')]
#[Signal('go')]
final class TestVersionBeforeSignalWorkflow extends Workflow
{
    public function execute(): Generator
    {
        $gate = yield awaitSignal('go');

        $result = yield activity(TestVersionedActivityV1::class);

        return [
            'gate' => $gate,
            'version' => WorkflowStub::DEFAULT_VERSION,
            'result' => $result,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
