<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-parent-waiting-on-child-workflow')]
final class TestParentWaitingOnChildWorkflow extends Workflow
{
    public function execute(int $seconds): Generator
    {
        $child = yield child(TestTimerWorkflow::class, $seconds);

        return [
            'parent_workflow_id' => $this->workflowId(),
            'parent_run_id' => $this->runId(),
            'child' => $child,
        ];
    }
}
