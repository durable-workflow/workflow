<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\continueAsNew;
use Workflow\V2\Workflow;

/**
 * #859: checks the history budget inside a loop within one run, the case that must replay identically.
 */
#[Type('test-history-budget-loop-workflow')]
final class TestHistoryBudgetLoopWorkflow extends Workflow
{
    public function handle(bool $stepStable, int $iterations = 20, ?int $rolledOverAt = null): mixed
    {
        if ($rolledOverAt !== null) {
            return [
                'rolled_over_at' => $rolledOverAt,
                'run_id' => $this->runId(),
            ];
        }

        for ($i = 0; $i < $iterations; $i++) {
            activity(TestGreetingActivity::class, "iteration-{$i}");

            $recommended = $stepStable ? $this->shouldContinueAsNewAtStep() : $this->shouldContinueAsNew();
            if ($recommended) {
                return continueAsNew($stepStable, $iterations, $i);
            }
        }

        return [
            'rolled_over_at' => null,
            'run_id' => $this->runId(),
        ];
    }
}
