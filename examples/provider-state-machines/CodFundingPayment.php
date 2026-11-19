<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\Workflow;

/** A separate public workflow type preserves the previous funding signal/definition schema. */
#[Type('example.provider.payment.cod.v1')]
#[Signal('cod-observed')]
final class CodFundingPayment extends Workflow
{
    use FundingPaymentExecution;

    public function handle(string $key, string $rootRun, array $entry, array $scope): array
    {
        $machine = new Machine($this, self::activity(BoundaryActivity::class, 'initialize', [
            'arguments' => [$key, $rootRun, $entry, $scope],
        ]));
        try {
            $machine->registerOwner();
            $input = $machine->initialization['descriptor'];
            $duties = [];
            // Reverse compensation reimburses the payer first; carrier/merchant obligations remain independent.
            foreach (['merchant', 'payer'] as $stage) {
                $duties[$stage] = $machine->scope->register(new Obligation(
                    'payment.cod.' . $stage,
                    'provider.cod.' . $stage,
                    '1',
                    $input
                ));
            }
            $step = new FundingStep($machine->initialization['recipe'], $duties);
            return $this->finishFunding($machine, $step);
        } catch (WorkflowCancellationRequestedException|BusinessCancellationRequested $error) {
            return $this->compensateFunding($machine);
        }
    }
}
