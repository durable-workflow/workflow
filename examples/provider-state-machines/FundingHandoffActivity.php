<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.provider.funding-handoff.v1')]
final class FundingHandoffActivity extends Activity
{
    public function handle(string $operation, array $input): array
    {
        $keys = array_keys($input);
        sort($keys);
        $expected = match ($operation) {
            'milestone' => ['binding', 'stage'], 'notify' => ['stage'], 'request-capture' => ['binding'],
            'read-capture', 'expire', 'stock', 'refuse-stock', 'binding' => [],
            default => throw new LogicException('The financial handoff operation is unavailable.'),
        };
        if ($keys !== $expected) {
            throw new LogicException('The original financial handoff input is malformed.');
        }
        $store = app(FundingHandoffStore::class);
        return match ($operation) {
            'milestone' => $store->milestone($this->run, $input['binding'], $input['stage']),
            'notify' => $store->notify($this->run, $input['stage']),
            'request-capture' => $store->requestCapture($this->run, $input['binding']),
            'read-capture' => $store->readCapture($this->run, $this->activityId()),
            'expire' => $store->expire($this->run),
            'stock' => $store->stock($this->run),
            'refuse-stock' => $store->refuseStock($this->run),
            'binding' => $store->binding($this->run),
        };
    }
}
