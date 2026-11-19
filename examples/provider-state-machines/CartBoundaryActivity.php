<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.provider.cart-boundary.v1')]
final class CartBoundaryActivity extends Activity
{
    public function handle(string $operation, array $input): array
    {
        $expected = match ($operation) {
            'plan', 'select-late', 'verify-prepared' => [],
            'observe', 'expire' => ['binding'],
            'associate' => ['binding', 'step'],
            'cancel' => ['reason'],
            default => throw new LogicException('The original cart host boundary is unavailable.'),
        };
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== $expected) {
            throw new LogicException('The recorded cart host input is malformed.');
        }
        $store = app(CartStore::class);
        return match ($operation) {
            'plan' => $store->planValue($this->run),
            'observe' => $store->observe($this->run, $input['binding']),
            'expire' => $store->expire($this->run, $input['binding']),
            'select-late' => $store->selectLate($this->run),
            'associate' => $store->associate($this->run, $input['binding'], $input['step']),
            'cancel' => $store->cancel($this->run, $input['reason']),
            'verify-prepared' => $store->verifyPrepared($this->run),
        };
    }
}
