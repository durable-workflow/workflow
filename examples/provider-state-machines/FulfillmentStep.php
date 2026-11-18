<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use LogicException;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Execution\ExecutionReceipt;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Transition\AssessedTransitionStep;
use Workflow\V2\StateMachine\Transition\TransitionContext;
use Workflow\V2\Workflow;

final class FulfillmentStep extends AssessedTransitionStep
{
    public function __construct(
        private readonly Machine $machine
    ) {
    }

    public function execute(TransitionContext $context): ExecutionReceipt
    {
        $child = $this->machine->initialization['children'][1];
        $identity = BindingIdentity::fromArray($child['binding']);
        if ($identity->slot !== 'shipment' || ! $identity->parentExecution->equals($context->execution())) {
            throw new LogicException('Fulfillment must use its original physical state-entry binding.');
        }
        $reservation = $this->machine->scope->reserveChild(
            'shipment',
            ScopeIdentity::fromArray($child['scope']),
            $identity
        );
        $started = $reservation->start(...$child['input']);
        $binding = new ChildBinding($identity, $started);
        for ($wake = 0; $wake < 32; ++$wake) {
            $value = Workflow::activity(ShipmentBoundaryActivity::class, 'milestone', [
                'binding' => $binding->toArray(),
            ]);
            if ($value['confirmed']) {
                if (CodFundingPolicy::selected($this->machine->initialization['recipe'])
                    || AdvanceBalancePolicy::selected($this->machine->initialization['recipe'])) {
                    $this->settlePaymentAfterDelivery(false);
                } elseif (in_array(
                    $this->machine->initialization['recipe'],
                    [FinancialCatalogue::AFTER_DELIVERY, FundingCatalogue::AFTER_DELIVERY],
                    true
                )) {
                    $this->settlePaymentAfterDelivery();
                }
                return new ExecutionReceipt($context->execution(), [
                    'original-delivery:' . $value['publication']['id'],
                ]);
            }
            if ($value['incident'] ?? false) {
                // Opted-in remedy policy: the carrier confirmed loss or damage of the original parcel.
                throw new TransitIncidentObserved('The original parcel has a confirmed carrier incident.');
            }
            if (! $value['originOpen']) {
                throw new LogicException('The original full Shipment closed without recipient delivery.');
            }
            $remaining = $this->machine->initialization['fulfillmentDeadline'] - Workflow::now()->getTimestamp();
            if ($remaining <= 0) {
                Workflow::activity(ShipmentBoundaryActivity::class, 'expire');
                throw new BusinessCancellationRequested('The original physical fulfillment deadline expired.');
            }
            Workflow::await('physical-milestone', $remaining);
        }
        throw new LogicException('The bounded original recipient-delivery wait remains unresolved.');
    }

    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void
    {
        (new RetainedStep($this->machine->scope, $this->machine->identity, $this->machine->initialization))
            ->compensate($context, $originalExecution);
    }

    private function settlePaymentAfterDelivery(bool $requestCapture = true): void
    {
        $common = FundingCatalogue::selected($this->machine->initialization['recipe']);
        $handoff = $common ? FundingHandoffActivity::class : FinancialHandoffActivity::class;
        $binding = Workflow::activity($handoff, 'binding', []);
        if ($requestCapture) {
            Workflow::activity($handoff, 'request-capture', [
                'binding' => $binding,
            ]);
        }
        for ($wake = 0; $wake < 32; ++$wake) {
            $value = Workflow::activity($handoff, 'milestone', [
                'binding' => $binding,
                'stage' => $common ? 'confirm-receipt' : 'capture',
            ]);
            if ($value['confirmed']) {
                return;
            }
            $remaining = $this->paymentWait($value, $requestCapture, $handoff);
            if (! $value['originOpen']) {
                throw new LogicException('The original Payment closed without the required capture publication.');
            }
            Workflow::await($common ? 'funding-milestone' : 'financial-milestone', $remaining);
        }
        throw new LogicException('The bounded original delivery-capture wait remains unresolved.');
    }

    private function paymentWait(array $value, bool $requestCapture, string $handoff): ?int
    {
        if (! $requestCapture) {
            return $value['remittanceWaitSeconds'] ?: null;
        }
        if ($value['waitSeconds'] === 0) {
            Workflow::activity($handoff, 'expire', []);
            throw new BusinessCancellationRequested(
                'The original authorization expired before delivery capture settled.'
            );
        }
        return $value['waitSeconds'];
    }
}
