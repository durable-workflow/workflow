<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use DurableWorkflow\Examples\ProviderStateMachines\IncidentWait;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

$examples = dirname(__DIR__, 3) . '/examples';
require_once $examples . '/provider-state-machines/IncidentBoundaryActivity.php';
require_once $examples . '/provider-state-machines/IncidentWait.php';

/**
 * Replays the provider example's explicit incident remedy decision wait and the bounded replacement delivery
 * wait that ends as unresolved instead of failing.
 */
#[Type('test-transit-settlement-replay-workflow')]
#[Signal('incident-milestone')]
final class TestTransitSettlementReplayWorkflow extends Workflow
{
    public function handle(bool $delivery): array
    {
        return $delivery ? IncidentWait::delivery() : IncidentWait::settlement();
    }
}
