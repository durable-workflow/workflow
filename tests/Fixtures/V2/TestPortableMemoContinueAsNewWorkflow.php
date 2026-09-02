<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\continueAsNew;
use Workflow\V2\Workflow;

#[Type('test-portable-memo-continue-as-new-workflow')]
final class TestPortableMemoContinueAsNewWorkflow extends Workflow
{
    public function handle(int $runNumber = 1): mixed
    {
        if ($runNumber === 1) {
            Workflow::upsertMemo(TestPortableMemoWorkflow::entries());

            return continueAsNew(2);
        }

        return [
            'continued' => true,
            'run_id' => $this->runId(),
        ];
    }
}
