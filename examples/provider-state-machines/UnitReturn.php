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
 * Separate native customer return of explicit delivered units. It owns its own retained scope and
 * provider identities; the fulfilled Order and its original delivery history stay unchanged.
 */
#[Type('example.provider.return.units.v1')]
#[Signal('return-milestone')]
final class UnitReturn extends Workflow
{
    public function handle(string $key, string $returnKey): array
    {
        $initial = self::activity(ReturnBoundaryActivity::class, 'initialize', [
            'arguments' => [$key, $returnKey],
        ]);
        if ($initial['nativeRun'] !== $this->runId() || $initial['key'] !== $key || $initial['returnKey'] !== $returnKey) {
            throw new LogicException('The recorded return initialization belongs to another original return.');
        }
        $scope = new DurableScope($this, ScopeIdentity::fromArray($initial['scope']), $initial['principal']);
        $requested = $scope->register(Obligation::fromArray($initial['request']))->execute();
        if ($requested['status'] === 'confirmed') {
            $plan = ReturnWait::inspection();
            // Registered before dispatch: restocking first, then the refund of exactly the same units.
            foreach (['restock', 'refund'] as $stage) {
                if ($plan[$stage] !== null) {
                    $scope->register(Obligation::fromArray($plan[$stage]))->execute();
                }
            }
        }
        return self::activity(ReturnBoundaryActivity::class, 'publish', []);
    }
}
