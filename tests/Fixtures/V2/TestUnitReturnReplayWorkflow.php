<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use DurableWorkflow\Examples\ProviderStateMachines\ReturnWait;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

$examples = dirname(__DIR__, 3) . '/examples';
require_once $examples . '/provider-state-machines/ReturnBoundaryActivity.php';
require_once $examples . '/provider-state-machines/ReturnWait.php';

/** Replays the provider example's customer unit-return inspection wait through the production fiber runner. */
#[Type('test-unit-return-replay-workflow')]
#[Signal('return-milestone')]
final class TestUnitReturnReplayWorkflow extends Workflow
{
    public function handle(): array
    {
        $plan = ReturnWait::inspection();
        return [
            'saleable' => $plan['saleableUnits'],
            'discrepancy' => $plan['discrepancyUnits'],
            'restock' => $plan['restock'] !== null,
            'refund' => $plan['refund'] !== null,
        ];
    }
}
