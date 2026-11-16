<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use Illuminate\Support\Carbon;
use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;

/** Whole physical graph is owned before booking, deduction, return or restocking can touch the provider. */
final readonly class ShipmentAuthority
{
    public function __construct(
        private Authority $authority
    ) {
    }

    public function rows(WorkflowRun $actual): array
    {
        [$actor, , $plan] = $this->authority->actor($actual);
        if ($actual->workflow_class !== FullShipment::class || $actor->role !== 'shipment'
            || (! FinancialCatalogue::selected($plan->recipe) && ! FundingCatalogue::selected($plan->recipe))) {
            throw new LogicException('Physical provider access requires its original full Shipment actor.');
        }
        [, $terms] = $this->authority->assignment($actor->root_key);
        $graph = ShipmentDescriptor::fromArray($terms->physicalDescriptor());
        $obligations = [
            new Obligation('owner', 'provider.owner', '1', [
                'key' => $actor->root_key,
                'rootRun' => $plan->rootRun,
                'nativeRun' => $actual->id,
                'role' => 'shipment',
            ]),
            new Obligation('shipment.fulfillment', 'provider.shipment.full', '1', $graph->toArray()),
        ];
        $ledger = (new ScopeRepository())->inspect($plan->childScope('shipment'));
        if ($ledger['original_run_id'] !== $actual->id || count($ledger['obligations']) !== 2) {
            throw new LogicException('The genuine owner and physical obligation must precede provider access.');
        }
        foreach ($obligations as $position => $obligation) {
            $row = $ledger['obligations'][$position];
            if ((int) $row['position'] !== $position + 1 || $row['fingerprint'] !== $obligation->fingerprint()
                || Obligation::fromArray($row['descriptor'])->fingerprint() !== $obligation->fingerprint()) {
                throw new LogicException('The original physical responsibility or compensation order changed.');
            }
        }
        return [$actor, $plan, $terms, $graph, $ledger['obligations'][1]];
    }

    public function operation(OperationContext $context, string $mode): void
    {
        if (! in_array($mode, ['forward', 'compensate'], true)) {
            throw new LogicException('The physical operation mode is unavailable.');
        }
        $this->authority->authorizeScope($context->scope, $this->authority->principal, $mode);
        $actual = WorkflowRun::query()->findOrFail($context->originalRunId);
        [$actor, $plan, , $graph, $row] = $this->rows($actual);
        if ($context->scope->toArray() !== $plan->childScope('shipment')->toArray()
            || $context->operationId !== $row['id'] || $context->obligation->fingerprint() !== $row['fingerprint']
            || PortableDescriptor::encode($context->obligation->input) !== PortableDescriptor::encode(
                $graph->toArray()
            )) {
            throw new LogicException('The physical handler must retain its original native obligation and graph.');
        }
        if ($mode === 'forward') {
            $this->forward($actor->root_key);
        }
    }

    public function forward(string $key): void
    {
        $plan = $this->authority->plan($key);
        [, $terms] = $this->authority->assignment($key);
        if ($terms->fulfillmentDeadline === null || Carbon::now()->getTimestamp() >= $terms->fulfillmentDeadline
            || (in_array($plan->recipe, [FinancialCatalogue::AFTER_DELIVERY, FundingCatalogue::AFTER_DELIVERY], true)
                && ! $terms->authorization->canAdmitCaptureAt(Carbon::now()->getTimestamp()))) {
            throw new LogicException('The original physical or financial admission deadline expired.');
        }
        foreach ([$plan->rootScope(), $plan->childScope('shipment')] as $scope) {
            if ((new ScopeRepository())->metadata($scope)['status'] !== 'open') {
                throw new LogicException('The original retained owner closed physical forward admission.');
            }
        }
        foreach (['order', 'shipment'] as $role) {
            if ((bool) $this->authority->subject($key, $role)->cancellation_requested) {
                throw new LogicException('The original business owner closed physical forward admission.');
            }
        }
    }

    public function stock(string $key): void
    {
        if ($this->authority->cart->selected($key) && ! app(CartStore::class)->stockHeld($key)) {
            throw new LogicException(
                'Cart fulfillment requires its original paid association and live bound stock hold.'
            );
        }
        $this->authority->assignment($key);
        $plan = $this->authority->plan($key);
        $ledger = (new ScopeRepository())->inspect($plan->rootScope());
        $expected = $this->authority->cart->stockObligation($key);
        foreach ($ledger['obligations'] as $row) {
            if ($row['descriptor']['key'] === $expected->key && $row['fingerprint'] === $expected->fingerprint()
                && Obligation::fromArray($row['descriptor'])->fingerprint() === $expected->fingerprint()
                && $row['forward_status'] === 'observed' && $ledger['original_run_id'] === $plan->rootRun) {
                return;
            }
        }
        throw new LogicException('Physical fulfillment requires the original recorded reservation responsibility.');
    }
}
