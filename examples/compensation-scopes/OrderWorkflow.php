<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\CompensationScopes;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\Workflow;

/**
 * Uses only the compensation API planned for promotion: DurableScope, Obligation, ScopeIdentity and participate().
 */
#[Type('example.compensation.order')]
#[Signal('finish')]
final class OrderWorkflow extends Workflow
{
    public function handle(array $order, array $shipment, string $reference): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($order), OrderCompensation::PRINCIPAL);
        try {
            $scope->execute(new Obligation('reserve-stock', 'stock.reserve', '1', [
                'reference' => $reference,
            ]));
            // Register the child as a participant before starting it, so it is compensated first.
            $scope->participate('shipment', ScopeIdentity::fromArray($shipment));
            $booked = self::child(ShipmentWorkflow::class, $shipment, $reference);
            // The Order stays open until fulfilment finishes; cancellation can arrive meanwhile or later.
            self::await('finish');

            return [
                'order' => 'fulfilled',
                'shipment' => $booked['shipment'],
            ];
        } catch (WorkflowCancellationRequestedException) {
            self::cancellationShield(fn () => $this->compensate());
            // Returns only when every obligation, including the Shipment's, is verified;
            // otherwise its activity fails. The run then closes as cancelled.
            $verified = self::cancellationShield(static fn () => $scope->verifyCompensation());

            return [
                'order' => 'compensated',
                'scope' => $verified,
            ];
        }
    }
}
