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
use function Workflow\V2\getVersion;

#[Type('test-version-after-signal-workflow')]
#[Signal('go')]
final class TestVersionAfterSignalWorkflow extends Workflow
{
    public function execute(): Generator
    {
        $gate = yield awaitSignal('go');
        $version = yield getVersion('step-2', WorkflowStub::DEFAULT_VERSION, 1);

        $result = $version === WorkflowStub::DEFAULT_VERSION
            ? yield activity(TestVersionedActivityV1::class)
            : yield activity(TestVersionedActivityV2::class);

        return [
            'gate' => $gate,
            'version' => $version,
            'result' => $result,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
