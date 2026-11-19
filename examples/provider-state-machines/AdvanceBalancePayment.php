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
#[Type('example.provider.payment.advance-balance.v1')]
#[Signal('incoming-observed')]
#[Signal('cod-observed')]
final class AdvanceBalancePayment extends Workflow
{
    use FundingPaymentExecution;

    public function handle(string $key, string $rootRun, array $entry, array $scope): array
    {
        $machine = new Machine($this, self::activity(BoundaryActivity::class, 'initialize', [
            'arguments' => [$key, $rootRun, $entry, $scope],
        ]));
        try {
            $machine->registerOwner();
            $terms = \DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AdvanceBalanceTerms::fromArray(
                $machine->initialization['descriptor']
            );
            $duties = [];
            // Register all three before provider I/O. Recovery refunds carrier balance, then bank advance.
            foreach ([
                'merchant' => ['payment.cod.merchant', 'provider.cod.merchant', [
                    'original' => $terms->balance->toArray(),
                    'admissionContract' => $terms->balanceAdmissionContract,
                ]],
                'incoming' => ['payment.incoming', 'provider.incoming', [
                    'original' => $terms->advance->toArray(),
                    'admissionContract' => $terms->advanceAdmissionContract,
                ]],
                'payer' => ['payment.cod.payer', 'provider.cod.payer', [
                    'original' => $terms->balance->toArray(),
                    'admissionContract' => $terms->balanceAdmissionContract,
                ]],
            ] as $stage => [$dutyKey, $handler, $input]) {
                $duties[$stage] = $machine->scope->register(new Obligation($dutyKey, $handler, '1', $input));
            }
            $step = new FundingStep($machine->initialization['recipe'], $duties, $terms);
            return $this->finishFunding($machine, $step);
        } catch (WorkflowCancellationRequestedException|BusinessCancellationRequested $error) {
            return $this->compensateFunding($machine);
        }
    }
}
