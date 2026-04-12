<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\continueAsNew;
use function Workflow\V2\upsertMemo;
use function Workflow\V2\upsertSearchAttributes;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-continue-as-new-metadata-workflow')]
final class TestContinueAsNewMetadataWorkflow extends Workflow
{
    public function handle(int $iteration = 0, int $max = 1): array
    {
        $greeting = activity(TestGreetingActivity::class, 'run-' . $iteration);

        upsertSearchAttributes([
            'iteration' => (string) $iteration,
            'status' => 'processing',
        ]);

        upsertMemo([
            'last_greeting' => $greeting,
            'iteration' => $iteration,
        ]);

        if ($iteration < $max) {
            return continueAsNew($iteration + 1, $max);
        }

        return [
            'greeting' => $greeting,
            'iteration' => $iteration,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
