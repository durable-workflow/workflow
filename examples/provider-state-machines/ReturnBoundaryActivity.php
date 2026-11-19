<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.provider.return.boundary.v1')]
final class ReturnBoundaryActivity extends Activity
{
    public function handle(string $operation, array $input): array
    {
        $expected = match ($operation) {
            'initialize' => ['arguments'],
            'observe', 'publish' => [],
            default => throw new LogicException('The return boundary is unavailable.'),
        };
        if (array_keys($input) !== $expected) {
            throw new LogicException('The return boundary input is malformed.');
        }
        $store = app(ReturnStore::class);
        return match ($operation) {
            'initialize' => $store->initialize($this->run, $input['arguments']),
            'observe' => $store->observe($this->run),
            'publish' => $store->publish($this->run),
        };
    }
}
