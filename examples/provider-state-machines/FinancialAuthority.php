<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\FinancialDescriptor;
use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;

/** The host relates financial descriptors to the same original business and native owners as the child binding. */
final readonly class FinancialAuthority
{
    public function __construct(
        private Authority $authority
    ) {
    }

    public function inspect(WorkflowRun $actual, ScopeIdentity $scope, FinancialDescriptor $descriptor): void
    {
        $this->originalRows($actual, $scope, $descriptor);
    }

    public function forwardStates(WorkflowRun $actual, ScopeIdentity $scope, FinancialDescriptor $descriptor): array
    {
        $rows = $this->originalRows($actual, $scope, $descriptor);
        return [
            'authorize' => $rows['payment.authorize']['forward_status'],
            'capture' => $rows['payment.capture']['forward_status'],
        ];
    }

    public function operation(OperationContext $context, string $mode, string $stage): void
    {
        if (! in_array($mode, ['forward', 'compensate'], true) || ! in_array($stage, ['authorize', 'capture'], true)) {
            throw new LogicException('The trusted financial operation boundary is unavailable.');
        }
        $this->authority->authorizeScope($context->scope, $this->authority->principal, $mode);
        $actual = WorkflowRun::query()->findOrFail($context->originalRunId);
        $unit = $stage === 'capture' && $this->authority->unitMoney->authorized(explode('.', $context->scope->key)[0]);
        if ($unit !== ($context->obligation->handlerType === 'provider.financial.unit-capture')) {
            throw new LogicException('The financial handler differs from the original selected money policy.');
        }
        $rows = $this->originalRows(
            $actual,
            $context->scope,
            FinancialDescriptor::fromArray(
                $unit ? $context->obligation->input['financial'] : $context->obligation->input
            )
        );
        $row = $rows['payment.' . $stage];
        if ($context->operationId !== $row['id']
            || $context->obligation->fingerprint() !== $row['fingerprint']) {
            throw new LogicException('The financial operation must target its original registered stage.');
        }
        if ($mode === 'forward') {
            $key = explode('.', $context->scope->key)[0];
            foreach (['order', 'payment'] as $role) {
                if ((bool) $this->authority->subject($key, $role)->cancellation_requested) {
                    throw new LogicException('The original business owner has closed financial forward admission.');
                }
            }
        }
    }

    private function originalRows(WorkflowRun $actual, ScopeIdentity $scope, FinancialDescriptor $descriptor): array
    {
        [$actor, , $plan] = $this->authority->actor($actual);
        $recipe = $this->authority->recipe($actor->root_key);
        if ($actor->role !== 'payment' || ! in_array(
            $recipe,
            [FinancialCatalogue::AFTER_STOCK, FinancialCatalogue::AFTER_DELIVERY],
            true
        ) || $scope->toArray() !== $plan->childScope('payment')
            ->toArray()
            || $this->authority->profile($actor->root_key, 'payment')
                ->fingerprint()
                !== FinancialCatalogue::profile($recipe)->fingerprint()) {
            throw new LogicException('The original financial actor, binding or selected policy was substituted.');
        }
        [, $terms] = $this->authority->assignment($actor->root_key);
        $expected = FinancialDescriptor::fromArray($terms->financialDescriptor());
        if (! hash_equals($expected->fingerprint(), $descriptor->fingerprint())) {
            throw new LogicException('The financial requests differ from the original protected business assignment.');
        }
        $obligations = [
            new Obligation('owner', 'provider.owner', '1', [
                'key' => $actor->root_key,
                'rootRun' => $plan->rootRun,
                'nativeRun' => $actual->id,
                'role' => 'payment',
            ]),
            new Obligation('payment.authorize', 'provider.financial.authorize', '1', $expected->toArray()),
            $this->authority->unitMoney->authorized($actor->root_key)
                ? $this->authority->unitMoney->obligation($actor->root_key)
                : new Obligation('payment.capture', 'provider.financial.capture', '1', $expected->toArray()),
        ];
        $ledger = (new ScopeRepository())->inspect($scope);
        if ($ledger['original_run_id'] !== $actual->id || count($ledger['obligations']) !== count($obligations)) {
            throw new LogicException('The original owner and both financial obligations must precede provider access.');
        }
        $rows = [];
        foreach ($obligations as $position => $obligation) {
            $row = $ledger['obligations'][$position];
            if ((int) $row['position'] !== $position + 1 || $row['fingerprint'] !== $obligation->fingerprint()
                || Obligation::fromArray($row['descriptor'])->fingerprint() !== $obligation->fingerprint()) {
                throw new LogicException('The original financial obligation graph or compensation ordering changed.');
            }
            $rows[$obligation->key] = $row;
        }
        return $rows;
    }
}
