<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\FinancialDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentDescriptor;
use Illuminate\Support\Carbon;
use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;

/** The host relates the selected policy, original payment assignment and complete native obligation graph. */
final readonly class FundingAuthority
{
    public function __construct(
        private Authority $authority
    ) {
    }

    /**
     * @return array{object, BindingPlan, OrderTerms, array<string, array>}
     */
    public function rows(WorkflowRun $actual, bool $allowCodPrefix = false): array
    {
        [$actor, , $plan] = $this->authority->actor($actual);
        $cod = CodFundingPolicy::selected($plan->recipe) || AdvanceBalancePolicy::selected($plan->recipe);
        $class = match (true) {
            AdvanceBalancePolicy::selected($plan->recipe) => AdvanceBalancePayment::class,
            CodFundingPolicy::selected($plan->recipe) => CodFundingPayment::class,
            default => FundingPayment::class,
        };
        if ($actual->workflow_class !== $class || $actor->role !== 'payment' || ! FundingCatalogue::selected(
            $plan->recipe
        )) {
            throw new LogicException('Funding access requires its original policy Payment actor.');
        }
        [, $terms] = $this->authority->assignment($actor->root_key);
        $expected = $this->expected($actual, $actor, $plan, $terms);
        $scope = $plan->childScope('payment');
        $ledger = (new ScopeRepository())->inspect($scope);
        $count = count($ledger['obligations']);
        $prefix = $cod && $allowCodPrefix && $count >= 1 && $count <= count($expected);
        if ($ledger['original_run_id'] !== $actual->id || (! $prefix && $count !== count($expected))) {
            throw new LogicException('The original complete funding responsibilities must precede provider access.');
        }
        $rows = [];
        foreach (array_slice($expected, 0, $count) as $position => $obligation) {
            $row = $ledger['obligations'][$position];
            if ((int) $row['position'] !== $position + 1 || $row['fingerprint'] !== $obligation->fingerprint()
                || Obligation::fromArray($row['descriptor'])->fingerprint() !== $obligation->fingerprint()) {
                throw new LogicException('The original funding responsibility, input or ordering was substituted.');
            }
            $rows[$obligation->key] = $row;
        }
        return [$actor, $plan, $terms, $rows];
    }

    public function inspectIncoming(
        WorkflowRun $actual,
        ScopeIdentity $scope,
        IncomingPaymentDescriptor $descriptor
    ): void {
        [, $plan, $terms] = $this->rows($actual);
        if (($plan->recipe !== FundingCatalogue::BANK && ! AdvanceBalancePolicy::selected($plan->recipe))
            || $scope->toArray() !== $plan->childScope('payment')
                ->toArray()
            || IncomingPaymentDescriptor::fromArray($terms->incomingDescriptor()['original'])->fingerprint()
                !== $descriptor->fingerprint()) {
            throw new LogicException('Incoming facts must retain their original business assignment and native owner.');
        }
    }

    public function inspectCod(
        WorkflowRun $actual,
        ScopeIdentity $scope,
        CodDescriptor $descriptor,
        string $contract
    ): void {
        [, $plan, $terms] = $this->rows($actual);
        $original = $terms->codDescriptor();
        if ((! CodFundingPolicy::selected($plan->recipe) && ! AdvanceBalancePolicy::selected($plan->recipe))
            || $scope->toArray() !== $plan->childScope('payment')
                ->toArray()
            || CodDescriptor::fromArray($original['original'])->fingerprint() !== $descriptor->fingerprint()
            || $original['admissionContract'] !== $contract) {
            throw new LogicException(
                'COD facts must retain their original business, carrier contract and native owner.'
            );
        }
    }

    public function cod(OperationContext $context, string $mode, string $stage): void
    {
        if (! in_array($stage, ['merchant', 'payer'], true)) {
            throw new LogicException('The original COD responsibility is unavailable.');
        }
        [$actor, $plan] = $this->context($context, $mode, 'payment.cod.' . $stage, $mode === 'compensate');
        if (! CodFundingPolicy::selected($plan->recipe) && ! AdvanceBalancePolicy::selected($plan->recipe)) {
            throw new LogicException('Only the original COD policy owns this money responsibility.');
        }
        if ($mode === 'forward') {
            $this->assertForward($actor->root_key, 'payment');
        }
    }

    public function inspect(WorkflowRun $actual, ScopeIdentity $scope, FinancialDescriptor $descriptor): void
    {
        [, $plan, $terms] = $this->rows($actual);
        if (! FundingCatalogue::requiresAuthorization($plan->recipe)
            || $scope->toArray() !== $plan->childScope('payment')
                ->toArray()
            || FinancialDescriptor::fromArray(
                $terms->financialDescriptor()
            )->fingerprint() !== $descriptor->fingerprint()) {
            throw new LogicException('Financial facts must retain their original funding assignment and native owner.');
        }
    }

    public function incoming(OperationContext $context, string $mode): void
    {
        [$actor, $plan] = $this->context($context, $mode, 'payment.incoming', $mode === 'compensate');
        if ($plan->recipe !== FundingCatalogue::BANK && ! AdvanceBalancePolicy::selected($plan->recipe)) {
            throw new LogicException('Only the original bank policy owns this incoming-money duty.');
        }
        if ($mode === 'forward') {
            $this->assertForward($actor->root_key, 'payment');
        }
    }

    public function operation(OperationContext $context, string $mode, string $stage): void
    {
        if (! in_array($stage, ['authorize', 'capture'], true)) {
            throw new LogicException('The trusted funding financial stage is unavailable.');
        }
        [$actor, $plan] = $this->context($context, $mode, 'payment.' . $stage);
        if (! FundingCatalogue::requiresAuthorization($plan->recipe)) {
            throw new LogicException('This original funding policy has no card authorization.');
        }
        if ($mode === 'forward') {
            // Provider admission enforces expiry; an uncertain original attempt may still reconcile its old result.
            $this->assertForward($actor->root_key, 'payment');
        }
    }

    public function effect(OperationContext $context, string $mode): void
    {
        if ($this->authority->cart->selected(explode('.', $context->scope->key)[0])) {
            throw new LogicException('Cart stock must retain its predeclared lease responsibility.');
        }
        if (! in_array($mode, ['forward', 'compensate'], true)) {
            throw new LogicException('The funding operation mode is unavailable.');
        }
        $this->authority->authorizeOperation($context, $mode);
        $key = explode('.', $context->scope->key)[0];
        if (! FundingCatalogue::selected($this->authority->recipe($key))) {
            throw new LogicException('The original generic effect has another policy generation.');
        }
        $role = $context->scope->key === $key ? 'order' : 'payment';
        if ($role === 'payment') {
            [, $plan] = $this->context($context, $mode, 'effect');
            if ($plan->recipe !== FundingCatalogue::IMMEDIATE) {
                throw new LogicException('Only immediate card funding owns the generic capture operation.');
            }
        }
        if ($mode === 'forward') {
            $this->assertForward($key, $role);
            [, $terms] = $this->authority->assignment($key);
            if ($role === 'order' && FundingCatalogue::requiresAuthorization($this->authority->recipe($key))
                && ! $context->reconcileBeforeForward && ! $terms->authorization->canAdmitCaptureAt(
                    Carbon::now()->getTimestamp()
                )) {
                throw new LogicException('The original authorization expired before stock admission.');
            }
        }
    }

    public function assertForward(string $key, string $role): void
    {
        if (! in_array($role, ['order', 'payment', 'shipment'], true)) {
            throw new LogicException('The original funding actor role is unavailable.');
        }
        $plan = $this->authority->plan($key);
        if (! FundingCatalogue::selected($plan->recipe)) {
            throw new LogicException('The original funding policy is unavailable.');
        }
        foreach (array_unique(['order', $role]) as $subjectRole) {
            if ((bool) $this->authority->subject($key, $subjectRole)->cancellation_requested) {
                throw new LogicException('The original business owner closed funding forward admission.');
            }
            $scope = $subjectRole === 'order' ? $plan->rootScope() : $plan->childScope($subjectRole);
            if ((new ScopeRepository())->metadata($scope)['status'] !== 'open') {
                throw new LogicException('The original retained owner closed funding forward admission.');
            }
        }
    }

    /**
     * @return non-empty-list<Obligation>
     */
    private function expected(WorkflowRun $actual, object $actor, BindingPlan $plan, OrderTerms $terms): array
    {
        $cod = CodFundingPolicy::selected($plan->recipe);
        $expected = [new Obligation('owner', 'provider.owner', '1', [
            'key' => $actor->root_key,
            'rootRun' => $plan->rootRun,
            'nativeRun' => $actual->id,
            'role' => 'payment',
        ])];
        if (AdvanceBalancePolicy::selected($plan->recipe)) {
            $expected[] = new Obligation('payment.cod.merchant', 'provider.cod.merchant', '1', $terms->codDescriptor());
            $expected[] = new Obligation('payment.incoming', 'provider.incoming', '1', $terms->incomingDescriptor());
            $expected[] = new Obligation('payment.cod.payer', 'provider.cod.payer', '1', $terms->codDescriptor());
        } elseif ($cod) {
            foreach (['merchant', 'payer'] as $stage) {
                $expected[] = new Obligation(
                    'payment.cod.' . $stage,
                    'provider.cod.' . $stage,
                    '1',
                    $terms->codDescriptor()
                );
            }
        } elseif ($plan->recipe === FundingCatalogue::BANK) {
            $expected[] = new Obligation('payment.incoming', 'provider.incoming', '1', $terms->incomingDescriptor());
        } elseif (FundingCatalogue::requiresAuthorization($plan->recipe)) {
            foreach (['authorize', 'capture'] as $stage) {
                $expected[] = new Obligation(
                    'payment.' . $stage,
                    'provider.financial.' . $stage,
                    '1',
                    $terms->financialDescriptor()
                );
            }
        } else {
            $expected[] = new Obligation('effect', 'provider.effect', '1', $terms->descriptor('payment'));
        }
        return $expected;
    }

    /**
     * @return array{object, BindingPlan, OrderTerms, array<string, array>}
     */
    private function context(OperationContext $context, string $mode, string $key, bool $allowCodPrefix = false): array
    {
        if (! in_array($mode, ['forward', 'compensate'], true)) {
            throw new LogicException('The funding operation mode is unavailable.');
        }
        $this->authority->authorizeScope($context->scope, $this->authority->principal, $mode);
        $actual = WorkflowRun::query()->findOrFail($context->originalRunId);
        $value = $this->rows($actual, $allowCodPrefix);
        $row = $value[3][$key] ?? null;
        if ($row === null || $context->scope->toArray() !== $value[1]->childScope('payment')->toArray()
            || $context->operationId !== $row['id'] || $context->obligation->key !== $key
            || $context->obligation->fingerprint() !== $row['fingerprint']) {
            throw new LogicException('Funding recovery cannot substitute the original native operation or descriptor.');
        }
        return $value;
    }
}
