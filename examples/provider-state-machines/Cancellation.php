<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

/** This workflow publishes verified original cancellation; it owns no provider operation or new scope. */
#[Type('example.provider.cancellation.v1')]
final class Cancellation extends Workflow
{
    public function handle(string $key, string $rootRun, string $requestKey): array
    {
        $machine = new Machine($this, self::activity(BoundaryActivity::class, 'initialize-finalizer', [
            'arguments' => [$key, $rootRun, $requestKey],
        ]));
        return $machine->transition('cancel')
            ->toArray();
    }
}
