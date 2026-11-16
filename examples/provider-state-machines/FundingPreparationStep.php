<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Execution\ExecutionReceipt;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Transition\AssessedTransitionStep;
use Workflow\V2\StateMachine\Transition\TransitionContext;
use Workflow\V2\Workflow;

final class FundingPreparationStep extends AssessedTransitionStep
{
    public function __construct(
        private readonly Machine $machine
    ) {
    }

    public function execute(TransitionContext $context): ExecutionReceipt
    {
        if ($context->transition->key !== 'prepare') {
            throw new LogicException('Financial preparation cannot execute a fulfillment transition.');
        }
        $init = $this->machine->initialization;
        $stock = $this->machine->scope->register(new Obligation('effect', 'provider.effect', '1', $init['descriptor']));
        $child = $init['children'][0];
        $identity = BindingIdentity::fromArray($child['binding']);
        if (! $identity->parentExecution->equals($context->execution()) || $identity->slot !== 'payment') {
            throw new LogicException('Financial preparation must retain its original state-entry binding.');
        }
        $reservation = $this->machine->scope->reserveChild(
            'payment',
            ScopeIdentity::fromArray($child['scope']),
            $identity
        );
        $started = $reservation->start(...$child['input']);
        $binding = new ChildBinding($identity, $started);
        $this->awaitMilestone(
            $binding,
            FundingCatalogue::requiresAuthorization($init['recipe']) ? 'prepare' : 'confirm-receipt'
        );
        $reserved = $stock->execute();
        $this->awaitStock();
        if ($init['recipe'] === FundingCatalogue::AFTER_STOCK) {
            Workflow::activity(FundingHandoffActivity::class, 'request-capture', [
                'binding' => $binding->toArray(),
            ]);
            $this->awaitMilestone($binding, 'confirm-receipt');
        }
        return new ExecutionReceipt($context->execution(), ['original-provider:' . $reserved['operation'],
            'original-child:' . $started->childRunId]);
    }

    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void
    {
        (new RetainedStep($this->machine->scope, $this->machine->identity, $this->machine->initialization))
            ->compensate($context, $originalExecution);
    }

    private function awaitMilestone(ChildBinding $binding, string $stage): void
    {
        for ($wake = 0; $wake < 32; ++$wake) {
            $value = Workflow::activity(FundingHandoffActivity::class, 'milestone', [
                'binding' => $binding->toArray(),
                'stage' => $stage,
            ]);
            if ($value['confirmed'] && ($stage !== 'prepare' || ! FundingCatalogue::requiresAuthorization(
                $this->machine->initialization['recipe']
            ) || $value['waitSeconds'] > 0)) {
                return;
            }
            if ($value['waitSeconds'] === 0) {
                Workflow::activity(FundingHandoffActivity::class, 'expire', []);
                throw new BusinessCancellationRequested('The original authorization deadline expired.');
            }
            if (! $value['originOpen']) {
                throw new LogicException('The original Payment closed without the required business publication.');
            }
            Workflow::await('funding-milestone', $value['waitSeconds']);
        }
        throw new LogicException('The bounded original financial milestone wait remains unresolved.');
    }

    private function awaitStock(): void
    {
        for ($wake = 0; $wake < 32; ++$wake) {
            $value = Workflow::activity(FundingHandoffActivity::class, 'stock', []);
            if ($value['refused']) {
                Workflow::activity(FundingHandoffActivity::class, 'refuse-stock', []);
                throw new BusinessCancellationRequested('The original stock reservation was definitively refused.');
            }
            if ($value['waitSeconds'] === 0) {
                Workflow::activity(FundingHandoffActivity::class, 'expire', []);
                throw new BusinessCancellationRequested('The original authorization expired while stock reconciled.');
            }
            if ($value['confirmed']) {
                return;
            }
            Workflow::await('reconcile-stock', $value['waitSeconds']);
        }
        throw new LogicException('The bounded original reservation wait remains unresolved.');
    }
}
