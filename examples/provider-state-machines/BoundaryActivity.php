<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.provider.boundary.v1')]
final class BoundaryActivity extends Activity
{
    public function handle(string $operation, array $input): array
    {
        $expected = match ($operation) {
            'initialize' => [],
            'execution' => ['key'],
            'observe' => ['binding', 'outcome'],
            'verify-step' => ['execution', 'key'],
            default => throw new LogicException('The provider machine boundary is unavailable.'),
        };
        $keys = array_keys($input);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new LogicException('The provider boundary input is malformed.');
        }
        $store = app(MachineStore::class);
        return match ($operation) {
            'initialize' => $store->initialize($this->run),
            'execution' => $store->execution($this->run, $input['key']),
            'observe' => $store->observe($this->run, $input['binding'], $input['outcome']),
            'verify-step' => $store->verifyStep($this->run, $input['execution'], $input['key']),
        };
    }
}
