<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use DurableWorkflow\Examples\ProviderStateMachines\IncidentWait;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

$examples = dirname(__DIR__, 3) . '/examples';
require_once $examples . '/provider-state-machines/ShipmentBoundaryActivity.php';
require_once $examples . '/provider-state-machines/IncidentBoundaryActivity.php';
require_once $examples . '/provider-state-machines/IncidentWait.php';

/** Replays the provider example's transit remedy, replacement delivery and damaged-goods inspection waits. */
#[Type('test-transit-incident-replay-workflow')]
#[Signal('physical-milestone')]
#[Signal('incident-milestone')]
final class TestTransitIncidentReplayWorkflow extends Workflow
{
    public function handle(bool $replacement, bool $damaged): array
    {
        $remedy = IncidentWait::remedy();
        $delivery = $replacement ? IncidentWait::delivery()['action'] : null;
        $plan = $damaged ? IncidentWait::inspection() : null;
        return [
            'remedy' => $remedy['remedyId'],
            'delivery' => $delivery,
            'restock' => $plan !== null && $plan['restock'] !== null,
        ];
    }
}
