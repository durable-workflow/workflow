<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Workflow;

/**
 * Separate native same-price exchange of one delivered unit for another SKU. It returns the original unit
 * under its own return identity, then reserves and books a replacement under its own identities. Its scope
 * compensates only its own operations; the fulfilled Order and its original delivery history stay unchanged.
 */
#[Type('example.provider.exchange.unit.v1')]
#[Signal('exchange-milestone')]
final class UnitExchange extends Workflow
{
    public function handle(string $key, string $exchangeKey): array
    {
        $initial = self::activity(ExchangeBoundaryActivity::class, 'initialize', [
            'arguments' => [$key, $exchangeKey],
        ]);
        if ($initial['nativeRun'] !== $this->runId() || $initial['key'] !== $key
            || $initial['exchangeKey'] !== $exchangeKey) {
            throw new LogicException('The recorded exchange initialization belongs to another original exchange.');
        }
        $scope = new DurableScope($this, ScopeIdentity::fromArray($initial['scope']), $initial['principal']);
        $requested = $scope->register(Obligation::fromArray($initial['request']))->execute();
        if ($requested['status'] === 'confirmed') {
            $plan = ExchangeWait::inspection();
            if ($plan['restock'] !== null) {
                $scope->register(Obligation::fromArray($plan['restock']))->execute();
            }
            if ($plan['reserve'] !== null) {
                // An unknown reservation outcome is reconciled by the same operation; only refusal is unavailability.
                $reserved = $scope->register(Obligation::fromArray($plan['reserve']))->execute();
                if ($reserved['status'] === 'confirmed') {
                    $booked = $scope->register(Obligation::fromArray($plan['book']))->execute();
                    if ($booked['status'] === 'confirmed') {
                        ExchangeWait::delivery();
                    } else {
                        // The carrier refused the replacement: undo only this exchange's own operations.
                        $this->compensate();
                        $scope->verifyCompensation();
                    }
                } elseif ($reserved['status'] === 'refused') {
                    // Selected policy: a definitively unavailable replacement refunds the original unit amount.
                    $scope->register(Obligation::fromArray($plan['refund']))->execute();
                }
            }
        }
        return self::activity(ExchangeBoundaryActivity::class, 'publish', []);
    }
}
