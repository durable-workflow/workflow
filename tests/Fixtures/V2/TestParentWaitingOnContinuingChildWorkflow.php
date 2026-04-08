<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-parent-waiting-on-continuing-child-workflow')]
final class TestParentWaitingOnContinuingChildWorkflow extends Workflow
{
    public function execute(int $count = 0, int $max = 1): Generator
    {
        $child = yield child(TestContinueAsNewWorkflow::class, $count, $max);

        return [
            'parent_workflow_id' => $this->workflowId(),
            'parent_run_id' => $this->runId(),
            'child' => $child,
        ];
    }
}
