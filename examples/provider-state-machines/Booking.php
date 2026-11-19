<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

/** Shipment booking only: technical completion never claims handover or delivery. */
#[Type('example.provider.booking.v1')]
final class Booking extends Workflow
{
    public function handle(string $key, string $rootRun, array $entry, array $scope): array
    {
        $machine = new Machine($this, self::activity(BoundaryActivity::class, 'initialize', [
            'arguments' => [$key, $rootRun, $entry, $scope],
        ]));
        $machine->registerOwner();
        return $machine->outcome($machine->transition('book'));
    }
}
