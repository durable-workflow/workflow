<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.provider.exchange.boundary.v1')]
final class ExchangeBoundaryActivity extends Activity
{
    public function handle(string $operation, array $input): array
    {
        $expected = match ($operation) {
            'initialize' => ['arguments'],
            'inspect', 'track', 'publish' => [],
            default => throw new LogicException('The exchange boundary is unavailable.'),
        };
        if (array_keys($input) !== $expected) {
            throw new LogicException('The exchange boundary input is malformed.');
        }
        $store = app(ExchangeStore::class);
        return match ($operation) {
            'initialize' => $store->initialize($this->run, $input['arguments']),
            'inspect' => $store->inspect($this->run),
            'track' => $store->track($this->run),
            'publish' => $store->publish($this->run),
        };
    }
}
