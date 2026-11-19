<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\CompensationScopes;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Workflow;

#[Type('example.compensation.shipment')]
final class ShipmentWorkflow extends Workflow
{
    public function handle(array $identity, string $reference): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), OrderCompensation::PRINCIPAL);
        $scope->execute(new Obligation('book-carrier', 'carrier.book', '1', [
            'reference' => $reference,
        ]));

        return [
            'shipment' => 'booked',
        ];
    }
}
