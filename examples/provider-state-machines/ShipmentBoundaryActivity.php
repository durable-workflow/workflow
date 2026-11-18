<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.provider.physical-boundary.v1')]
final class ShipmentBoundaryActivity extends Activity
{
    public function handle(string $operation, array $input = []): array
    {
        $expected = $operation === 'milestone' ? ['binding'] : [];
        if (array_keys($input) !== $expected) {
            throw new LogicException('The physical boundary fields are malformed.');
        }
        $store = app(ShipmentBoundaryStore::class);
        return match ($operation) {
            'register' => $store->register($this->run),
            'deduct' => $store->deduct($this->run),
            'notify' => $store->notify($this->run),
            'expire' => $store->expire($this->run),
            'milestone' => $store->milestone($this->run, $input['binding']),
            'incident' => app(IncidentStore::class)->admit($this->run),
            'remedy' => app(IncidentStore::class)->remedy($this->run),
            default => throw new LogicException('The physical boundary operation is unavailable.'),
        };
    }
}
