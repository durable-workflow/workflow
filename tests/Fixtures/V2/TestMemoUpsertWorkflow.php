<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-memo-upsert-workflow')]
final class TestMemoUpsertWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        upsertMemo([
            'customer_name' => $name,
            'status' => 'processing',
            'tags' => ['greeting', 'test'],
        ]);

        $greeting = activity(TestGreetingActivity::class, $name);

        upsertMemo([
            'status' => 'completed',
            'result_summary' => $greeting,
        ]);

        return [
            'greeting' => $greeting,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
