<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

/** The immediate-capture Payment boundary; retained refunds remain owned by this original scope. */
#[Type('example.provider.payment.v1')]
final class Payment extends Workflow
{
    public function handle(string $key, string $rootRun, array $entry, array $scope): array
    {
        $machine = new Machine($this, self::activity(BoundaryActivity::class, 'initialize', [
            'arguments' => [$key, $rootRun, $entry, $scope],
        ]));
        $machine->registerOwner();
        return $machine->outcome($machine->transition('capture'));
    }
}
