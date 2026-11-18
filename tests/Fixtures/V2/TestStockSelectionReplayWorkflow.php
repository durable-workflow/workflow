<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use DurableWorkflow\Examples\ProviderStateMachines\StockSelectionWait;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

$examples = dirname(__DIR__, 3) . '/examples';
require_once $examples . '/business-state-machines/BusinessCancellationRequested.php';
require_once $examples . '/provider-state-machines/BoundaryActivity.php';
require_once $examples . '/provider-state-machines/StockSelectionWait.php';

/** Replays the provider example's partial-stock wait loop through the production fiber runner. */
#[Type('test-stock-selection-replay-workflow')]
#[Signal('reconcile-stock')]
final class TestStockSelectionReplayWorkflow extends Workflow
{
    public function handle(?int $deadline): array
    {
        try {
            $plan = StockSelectionWait::plan($deadline);
        } catch (BusinessCancellationRequested) {
            return [
                'outcome' => 'cancelled',
            ];
        }
        return [
            'outcome' => 'fulfil',
            'units' => $plan['fulfillUnits'],
        ];
    }
}
