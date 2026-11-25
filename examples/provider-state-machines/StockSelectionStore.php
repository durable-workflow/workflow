<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundCommand;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundGateway;
use Illuminate\Support\Carbon;
use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;

/**
 * Host boundary for the selected partial-stock policy. Every warehouse read and plan decision is a
 * recorded activity result; replay never consults the warehouse, the clock or the refund provider.
 */
final readonly class StockSelectionStore
{
    public function __construct(
        private Authority $authority,
        private Journal $provider,
        private StockWarehouse $warehouse,
        private UnitRefundGateway $refunds,
    ) {
        $refunds->assertProvider($provider);
    }

    /**
     * Fulfil and cancel plans are final and immutable. A wait observation is not persisted.
     */
    public function plan(WorkflowRun $actual, bool $expire): array
    {
        [$key, $plan, $original] = $this->root($actual);
        $recorded = $this->authority->stock->recorded($key);
        if ($recorded !== null) {
            return $this->requestCancellation($key, $plan->rootRun, $recorded);
        }
        $policy = $this->authority->stock->policy($key);
        if ((bool) $this->authority->subject($key, 'order')->cancellation_requested) {
            // Cancellation already began: no plan is recorded and no unit is allocated or fulfilled.
            return [
                ...StockAllocation::fromArray($this->warehouse->inspect($original)['allocation'])
                    ->decide($original, StockAllocation::CANCEL),
                'policy' => $policy,
                'reason' => 'business-cancellation-requested',
            ];
        }
        if ($policy === StockAllocation::WAIT) {
            $this->warehouse->allocate($original);
        }
        $snapshot = $this->warehouse->inspect($original);
        $allocation = StockAllocation::fromArray($snapshot['allocation']);
        $decision = $allocation->decide($original, $policy);
        $decision['allocationRevision'] = $snapshot['revision'];
        $decision['reason'] = 'original-allocation';
        if ($decision['action'] === 'wait') {
            [, $terms] = $this->authority->assignment($key);
            if (! $expire) {
                return $decision;
            }
            if ($terms->fulfillmentDeadline === null || Carbon::now()->getTimestamp() < $terms->fulfillmentDeadline) {
                throw new LogicException('The original complete-order waiting deadline has not expired.');
            }
            $decision = [
                ...$allocation->decide($original, StockAllocation::CANCEL),
                'policy' => $policy,
                'allocationRevision' => $snapshot['revision'],
                'reason' => 'complete-order-deadline-expired',
            ];
        }
        if ($this->authority->recipe($key) === Catalogue::COMMON_RESERVE_FIRST) {
            // Reserve-first plans before capture: there is no paid unit to repay.
            $decision['requiredRefundUnits'] = [];
        }
        return $this->requestCancellation($key, $plan->rootRun, $this->record($key, $actual, $decision));
    }

    /**
     * The shortage repayment registered by this original Order, or its later authoritative observation.
     */
    public function shortage(WorkflowRun $actual, bool $observe): array
    {
        [$key, $plan] = $this->root($actual);
        $expected = $this->authority->stock->shortage($key);
        if ($expected === null) {
            throw new LogicException('The recorded original stock plan owes no shortage repayment.');
        }
        if (! $observe) {
            return $expected->toArray();
        }
        $this->registered($plan->rootScope(), $expected, $actual->id);
        $command = UnitRefundCommand::fromObligation($expected->input);
        $result = $this->refunds->lookup($command);
        return [
            'confirmed' => $result['confirmedUnits'] === $command->units,
            'status' => $result['status'],
            'confirmedUnits' => $result['confirmedUnits'],
        ];
    }

    public function operation(OperationContext $context, string $mode): void
    {
        $this->authority->authorizeOperation($context, $mode);
    }

    public function refund(OperationContext $context, string $mode): void
    {
        $this->authority->authorizeScope($context->scope, $this->authority->principal, $mode);
        $key = explode('.', $context->scope->key)[0];
        $plan = $this->authority->plan($key);
        $expected = $this->authority->stock->shortage($key);
        $actor = $this->authority->db->table('provider_machine_actors')
            ->where('id', Authority::subjectId($key, 'order'))->sole();
        if (! in_array($mode, ['forward', 'compensate'], true) || $expected === null
            || $context->scope->toArray() !== $plan->rootScope()
                ->toArray()
            || $context->obligation->fingerprint() !== $expected->fingerprint()
            || $actor->root_key !== $key || $actor->native_run !== $context->originalRunId
            || $actor->native_run !== $plan->rootRun
            || $actor->scope !== PortableDescriptor::encode($context->scope->toArray())) {
            throw new LogicException('The shortage repayment belongs to another original Order or stock plan.');
        }
        $root = $this->authority->subject($key, 'order');
        if ($mode === 'forward' && (bool) $root->cancellation_requested) {
            throw new LogicException('Original business cancellation has closed shortage repayment admission.');
        }
        $this->registered($context->scope, $expected, $actor->native_run, $context->operationId);
    }

    /**
     * Tests and operators can confirm which original reservation a plan addresses.
     */
    public function reservation(string $key): Request
    {
        return Request::fromArray($this->authority->cart->stockObligation($key)->input['forward']);
    }

    private function root(WorkflowRun $actual): array
    {
        [$actor, $row, $plan] = $this->authority->actor($actual);
        $key = $actor->root_key;
        if ($actual->workflow_class !== CheckoutOrder::class || $actor->role !== 'order'
            || ! $this->authority->stock->selected($key) || $row->state !== 'received'
            || (string) $row->revision !== '1') {
            throw new LogicException('Stock selection belongs to the original received checkout Order.');
        }
        $original = (new OriginalStock($this->authority, $this->provider))->request($key, $plan);
        if ($original === null || (new OriginalStock($this->authority, $this->provider))->confirmed(
            $key,
            $plan
        ) === null) {
            throw new LogicException('Stock selection requires the confirmed original reservation.');
        }
        return [$key, $plan, $original];
    }

    private function record(string $key, WorkflowRun $actual, array $decision): array
    {
        $payload = PortableDescriptor::encode($decision);
        $this->authority->db->transaction(function () use ($key, $actual, $payload): void {
            $root = $this->authority->db->table('example_machine_subjects')
                ->where('id', Authority::subjectId($key, 'order'))
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($root, $key, 'order');
            $assignment = $this->authority->db->table('provider_machine_assignments')
                ->where('id', $key)
                ->lockForUpdate()
                ->sole();
            if ($assignment->root_run !== $actual->id || $root->state !== 'received'
                || (string) $root->revision !== '1') {
                throw new LogicException('The original received Order changed before its stock plan.');
            }
            if ($assignment->stock_plan !== null) {
                return;
            }
            // Cancellation wins: a fulfilment plan cannot reopen an Order whose cancellation began.
            if ((bool) $root->cancellation_requested) {
                throw new LogicException('Original business cancellation has closed stock fulfilment.');
            }
            $this->authority->db->table('provider_machine_assignments')
                ->where('id', $key)
                ->update([
                    'stock_plan' => $payload,
                ]);
        });
        $recorded = $this->authority->stock->recorded($key);
        if ($recorded === null) {
            throw new LogicException('The original stock plan was not retained.');
        }
        return $recorded;
    }

    private function requestCancellation(string $key, string $rootRun, array $plan): array
    {
        if ($plan['action'] === 'cancel') {
            app(CancellationStore::class)->request($key, $rootRun, 'stock-plan-' . $plan['reason']);
        }
        return $plan;
    }

    private function registered(
        \Workflow\V2\Compensation\Definition\ScopeIdentity $scope,
        Obligation $expected,
        string $run,
        ?string $operationId = null
    ): void {
        $ledger = (new ScopeRepository())->inspect($scope);
        $rows = array_values(array_filter($ledger['obligations'], static fn (array $row): bool =>
            ($row['descriptor']['key'] ?? null) === $expected->key));
        if ($ledger['original_run_id'] !== $run || count($rows) !== 1
            || ($operationId !== null && $rows[0]['id'] !== $operationId)
            || $rows[0]['fingerprint'] !== $expected->fingerprint()
            || Obligation::fromArray($rows[0]['descriptor'])->fingerprint() !== $expected->fingerprint()) {
            throw new LogicException('The shortage repayment requires its exact original registered obligation.');
        }
    }
}
