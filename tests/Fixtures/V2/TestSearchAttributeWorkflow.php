<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\upsertSearchAttributes;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-search-attribute-workflow')]
final class TestSearchAttributeWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        upsertSearchAttributes([
            'status' => 'processing',
            'customer' => $name,
        ]);

        $greeting = activity(TestGreetingActivity::class, $name);

        upsertSearchAttributes([
            'status' => 'completed',
            'result' => 'success',
        ]);

        return [
            'greeting' => $greeting,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
