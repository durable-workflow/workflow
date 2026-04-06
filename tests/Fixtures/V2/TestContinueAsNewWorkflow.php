<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\continueAsNew;
use Workflow\V2\Workflow;

#[Type('test-continue-as-new-workflow')]
final class TestContinueAsNewWorkflow extends Workflow
{
    public function execute(int $count = 0, int $max = 2): Generator
    {
        $result = yield activity(TestContinueAsNewActivity::class, $count);

        if ($count >= $max) {
            return [
                'count' => $result,
                'workflow_id' => $this->workflowId(),
                'run_id' => $this->runId(),
            ];
        }

        return yield continueAsNew($count + 1, $max);
    }
}
