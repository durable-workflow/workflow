<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use LogicException;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\Workflow;

#[Type('example.provider.payment.funding.v1')]
#[Signal('capture-request')]
#[Signal('incoming-observed')]
#[Signal('reconcile-funding')]
final class FundingPayment extends Workflow
{
    use FundingPaymentExecution;

    public function handle(string $key, string $rootRun, array $entry, array $scope): array
    {
        $machine = new Machine($this, self::activity(BoundaryActivity::class, 'initialize', [
            'arguments' => [$key, $rootRun, $entry, $scope],
        ]));
        try {
            $machine->registerOwner();
            $recipe = $machine->initialization['recipe'];
            $input = $machine->initialization['descriptor'];
            $duties = match ($recipe) {
                FundingCatalogue::BANK => [
                    'incoming' => ['payment.incoming', 'provider.incoming'],
                ],
                FundingCatalogue::IMMEDIATE => [
                    'capture' => ['effect', 'provider.effect'],
                ],
                default => [
                    'authorize' => ['payment.authorize', 'provider.financial.authorize'],
                    'capture' => ['payment.capture', 'provider.financial.capture'],
                ],
            };
            $registered = [];
            foreach ($duties as $stage => [$duty, $handler]) {
                $registered[$stage] = $machine->scope->register(new Obligation($duty, $handler, '1', $input));
            }
            $step = new FundingStep($recipe, $registered);
            return $this->finishFunding($machine, $step, function (string $transition) use ($recipe): void {
                if ($transition === 'collect' && FundingCatalogue::requiresAuthorization($recipe)) {
                    $this->awaitCapture($recipe);
                }
            });
        } catch (WorkflowCancellationRequestedException|BusinessCancellationRequested $error) {
            return $this->compensateFunding($machine);
        }
    }

    private function awaitCapture(string $recipe): void
    {
        $trigger = $recipe === FundingCatalogue::AFTER_STOCK
            ? 'original_stock_reserved' : 'original_recipient_delivered';
        for ($wake = 0; $wake < 32; ++$wake) {
            $value = self::activity(FundingHandoffActivity::class, 'read-capture', []);
            if ($value['values'][$trigger]) {
                return;
            }
            if ($value['waitSeconds'] === 0) {
                self::activity(FundingHandoffActivity::class, 'expire', []);
                throw new BusinessCancellationRequested('The original funding deadline expired before capture.');
            }
            self::await('capture-request', $value['waitSeconds']);
        }
        throw new LogicException('The bounded original capture-instruction wait remains unresolved.');
    }
}
