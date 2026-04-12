<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-history-budget-workflow')]
final class TestHistoryBudgetWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        $greeting = activity(TestGreetingActivity::class, $name);

        return [
            'greeting' => $greeting,
            'history_length' => $this->historyLength(),
            'history_size' => $this->historySize(),
            'should_continue_as_new' => $this->shouldContinueAsNew(),
        ];
    }
}
