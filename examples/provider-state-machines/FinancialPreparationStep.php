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

final class FinancialPreparationStep extends AssessedTransitionStep
{
    private readonly bool $commonFunding;

    public function __construct(
        private readonly Machine $machine
    ) {
        $this->commonFunding = FundingCatalogue::selected($machine->initialization['recipe']);
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
        $stage = $this->commonFunding ? 'prepare' : 'authorize';
        if ($this->commonFunding && ! FundingCatalogue::requiresAuthorization($init['recipe'])) {
            $stage = 'confirm-receipt';
        }
        $this->awaitMilestone($binding, $stage);
        $reserved = $stock->execute();
        $this->awaitStock();
        if (in_array($init['recipe'], [FinancialCatalogue::AFTER_STOCK, FundingCatalogue::AFTER_STOCK], true)) {
            Workflow::activity($this->handoff(), 'request-capture', [
                'binding' => $binding->toArray(),
            ]);
            $this->awaitMilestone($binding, $this->commonFunding ? 'confirm-receipt' : 'capture');
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
            $value = Workflow::activity($this->handoff(), 'milestone', [
                'binding' => $binding->toArray(),
                'stage' => $stage,
            ]);
            if ($value['confirmed'] && (! in_array(
                $stage,
                ['authorize', 'prepare'],
                true
            ) || $value['waitSeconds'] > 0)) {
                return;
            }
            if ($value['waitSeconds'] === 0) {
                Workflow::activity($this->handoff(), 'expire', []);
                throw new BusinessCancellationRequested('The original authorization deadline expired.');
            }
            if (! $value['originOpen']) {
                throw new LogicException('The original Payment closed without the required business publication.');
            }
            Workflow::await($this->commonFunding ? 'funding-milestone' : 'financial-milestone', $value['waitSeconds']);
        }
        throw new LogicException('The bounded original financial milestone wait remains unresolved.');
    }

    private function awaitStock(): void
    {
        for ($wake = 0; $wake < 32; ++$wake) {
            $value = Workflow::activity($this->handoff(), 'stock', []);
            if ($value['refused']) {
                Workflow::activity($this->handoff(), 'refuse-stock', []);
                throw new BusinessCancellationRequested('The original stock reservation was definitively refused.');
            }
            if ($value['waitSeconds'] === 0) {
                Workflow::activity($this->handoff(), 'expire', []);
                throw new BusinessCancellationRequested('The original authorization expired while stock reconciled.');
            }
            if ($value['confirmed']) {
                return;
            }
            Workflow::await('reconcile-stock', $value['waitSeconds']);
        }
        throw new LogicException('The bounded original reservation wait remains unresolved.');
    }

    private function handoff(): string
    {
        return $this->commonFunding ? FundingHandoffActivity::class : FinancialHandoffActivity::class;
    }
}
