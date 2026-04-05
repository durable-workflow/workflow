<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;
use function Workflow\V2\continueAsNew;

#[Type('test-query-continue-as-new-workflow')]
final class TestQueryContinueAsNewWorkflow extends Workflow
{
    private int $count = 0;

    public function execute(int $count, int $target): Generator
    {
        $this->count = $count;

        if ($this->count < $target) {
            ++$this->count;

            yield continueAsNew($this->count, $target);
        }

        return [
            'count' => $this->count,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[QueryMethod]
    public function currentCount(): int
    {
        return $this->count;
    }
}
