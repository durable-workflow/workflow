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
    public function handle(
        bool $stepStable,
        int $iterations = 20,
        ?int $rolledOverAt = null,
        ?int $eventThreshold = null,
    ): mixed {
        if ($rolledOverAt !== null) {
            return [
                'rolled_over_at' => $rolledOverAt,
                'run_id' => $this->runId(),
            ];
        }

        for ($i = 0; $i < $iterations; $i++) {
            activity(TestGreetingActivity::class, "iteration-{$i}");

            // An explicit threshold lets cold replay prove the rule without package config.
            $recommended = match (true) {
                $eventThreshold !== null && $stepStable => $this->historyBudgetAtStep()['history_event_count'] >= $eventThreshold,
                $eventThreshold !== null => $this->historyLength() >= $eventThreshold,
                $stepStable => $this->shouldContinueAsNewAtStep(),
                default => $this->shouldContinueAsNew(),
            };
            if ($recommended) {
                return continueAsNew($stepStable, $iterations, $i, $eventThreshold);
            }
        }

        return [
            'rolled_over_at' => null,
            'run_id' => $this->runId(),
        ];
    }
}
