<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\awaitSignal;
use Workflow\V2\Workflow;

#[Type('test-signal-ordering-workflow')]
final class TestSignalOrderingWorkflow extends Workflow
{
    public function execute(): Generator
    {
        $first = yield awaitSignal('message');
        $second = yield awaitSignal('message');

        return [
            'messages' => [$first, $second],
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
