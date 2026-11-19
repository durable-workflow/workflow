<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.provider.incident.boundary.v1')]
final class IncidentBoundaryActivity extends Activity
{
    public function handle(string $operation, array $input): array
    {
        $expected = match ($operation) {
            'initialize' => ['arguments'],
            'settle', 'unresolved', 'track', 'inspect', 'publish' => [],
            default => throw new LogicException('The incident boundary is unavailable.'),
        };
        if (array_keys($input) !== $expected) {
            throw new LogicException('The incident boundary input is malformed.');
        }
        $store = app(IncidentStore::class);
        return match ($operation) {
            'initialize' => $store->initialize($this->run, $input['arguments']),
            'settle' => $store->settle($this->run),
            'unresolved' => $store->unresolved($this->run),
            'track' => $store->track($this->run),
            'inspect' => $store->inspect($this->run),
            'publish' => $store->publish($this->run),
        };
    }
}
