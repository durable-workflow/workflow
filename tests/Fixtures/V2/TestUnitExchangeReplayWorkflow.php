<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use DurableWorkflow\Examples\ProviderStateMachines\ExchangeWait;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

$examples = dirname(__DIR__, 3) . '/examples';
require_once $examples . '/provider-state-machines/ExchangeBoundaryActivity.php';
require_once $examples . '/provider-state-machines/ExchangeWait.php';

/** Replays the provider example's same-price exchange inspection and replacement delivery waits. */
#[Type('test-unit-exchange-replay-workflow')]
#[Signal('exchange-milestone')]
final class TestUnitExchangeReplayWorkflow extends Workflow
{
    public function handle(): array
    {
        $plan = ExchangeWait::inspection();
        if ($plan['reserve'] === null) {
            return [
                'replacement' => false,
                'discrepancy' => $plan['discrepancyUnits'],
            ];
        }
        $delivery = ExchangeWait::delivery();
        return [
            'replacement' => true,
            'delivery' => $delivery['action'],
            'restock' => $plan['restock'] !== null,
        ];
    }
}
