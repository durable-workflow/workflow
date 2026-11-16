<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Closure;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StateMachine\Binding\ChildBinding;

/** Recorded host boundary. A purchase execution projection may exist while its formal Order does not. */
final readonly class CartStore
{
    public function __construct(
        private Authority $authority,
        private Journal $provider,
        private ?IncomingPaymentProvider $incoming,
    ) {
    }

    /**
     * Internal part of the same host transaction that creates the immutable purchase assignment.
     */
    public function seedRow(OrderTerms $terms, CartPlan $plan, string $assignmentHash): void
    {
        $key = $terms->subjectKey();
        if ($this->authority->db->transactionLevel() !== 1 || ! $this->authority->cart(
            $key
        ) || $this->incoming === null) {
            throw new LogicException('Cart assignment requires its owned purchase bootstrap transaction and provider.');
        }
        $plan->assertOrder($terms);
        $payload = PortableDescriptor::encode($plan->toArray());
        $this->authority->db->table('provider_cart_purchases')
            ->insert([
                'id' => $key,
                'principal' => $this->authority->principal,
                'authority_version' => $this->authority->grantFor($key, 'order'),
                'assignment_sha256' => $assignmentHash,
                'plan' => $payload,
                'plan_sha256' => hash('sha256', $payload),
            ]);
    }

    public function assertTerms(string $key, OrderTerms $terms, string $assignmentHash): void
    {
        [$row, $plan] = $this->row($key);
        $plan->assertOrder($terms);
        if ($row->assignment_sha256 !== $assignmentHash) {
            throw new LogicException('The original cart purchase terms were substituted.');
        }
    }

    public function plan(string $key): CartPlan
    {
        $this->authority->assignment($key);
        return $this->row($key)[1];
    }

    public function stockObligation(string $key): Obligation
    {
        $plan = $this->plan($key);
        [$row] = $this->row($key);
        if ($row->order_id !== null) {
            $this->order($key);
        }
        return $plan->obligation($row->selected ?? 'initial');
    }

    public function stockHeld(string $key): bool
    {
        [$row, $plan] = $this->row($key);
        if ($row->order_id === null) {
            return false;
        }
        $order = $this->order($key);
        [, $terms] = $this->authority->assignment($key);
        $lease = $plan->lease($order['selected']);
        $stock = $this->provider->cartInventory()
            ->inspect($lease);
        $booking = ShipmentDescriptor::fromArray($terms->physicalDescriptor())->booking;
        return $stock['reservationStatus'] === Status::Confirmed->value && ! $stock['released']
            && $stock['heldQuantity'] === $terms->quantity && $stock['fulfillmentBooking'] === $booking->identity->key();
    }

    public function planValue(WorkflowRun $actual): array
    {
        [$key] = $this->actor($actual, true);
        return $this->plan($key)
            ->toArray();
    }

    /**
     * Resolve the host-authorized original plan before the scoped handler performs any provider SQL.
     */
    public function operation(OperationContext $context, string $mode): CartPlan
    {
        if (! in_array($mode, ['forward', 'compensate'], true)) {
            throw new LogicException('The cart operation mode is unavailable.');
        }
        $actual = WorkflowRun::query()->findOrFail($context->originalRunId);
        [$key, $binding] = $this->actor($actual, $mode === 'forward');
        $plan = $this->plan($key);
        if ($context->scope->toArray() !== $binding->rootScope()->toArray()) {
            throw new LogicException('The cart operation substituted its original native purchase scope.');
        }
        $rows = $this->duties($actual, $binding, $plan, $mode === 'compensate');
        foreach (['initial', 'late'] as $step) {
            if ($context->obligation->fingerprint() !== $plan->obligation($step)->fingerprint()
                || $context->operationId !== $rows[$step]['id']) {
                continue;
            }
            if ($mode === 'forward' && $step === 'late') {
                [$purchase] = $this->row($key);
                if ($purchase->selected !== 'late' || $purchase->expiry_receipt === null) {
                    throw new LogicException('Late stock needs the original recorded expiry and selected successor.');
                }
            }
            return $plan;
        }
        throw new LogicException('The cart duty is outside its original recorded purchase assignment.');
    }

    public function observe(WorkflowRun $actual, array $value): array
    {
        [$key, $binding] = $this->actor($actual, true);
        $plan = $this->plan($key);
        $this->duties($actual, $binding, $plan);
        $payment = app(FundingHandoffStore::class)->milestone($actual, $value, 'confirm-receipt');
        $initial = $this->provider->cartInventory()
            ->inspect($plan->initial);
        $late = $this->provider->cartInventory()
            ->inspect($plan->late);
        $paidOnTime = false;
        if ($payment['confirmed']) {
            [, $terms] = $this->authority->assignment($key);
            $receipts = $this->incoming->receipts->inspect($terms->incomingPayment);
            $amount = 0;
            foreach ($receipts['receipts'] as $receipt) {
                if ($receipt['receivedAt'] < $plan->initial->terms->expiresAt) {
                    $amount += $receipt['amountMinor'];
                }
            }
            $paidOnTime = $amount >= $terms->amountMinor;
        }
        return [
            'initial' => $initial,
            'late' => $late,
            'payment' => $payment,
            'paidBeforeInitialExpiry' => $paidOnTime,
            'observationDeadline' => $plan->paymentObservationDeadline,
        ];
    }

    /**
     * Business expiry releases only this lease. It never requests compensation of the whole purchase scope.
     */
    public function expire(WorkflowRun $actual, array $value): array
    {
        $facts = $this->observe($actual, $value);
        [$key] = $this->actor($actual, true);
        [$row, $plan] = $this->row($key);
        if (! $facts['initial']['deadlinePassed'] || ($facts['paidBeforeInitialExpiry']
            && ! $facts['initial']['admissionClosed'] && ! $facts['initial']['released'])) {
            throw new LogicException('The original cart has not expired unpaid.');
        }
        $original = $this->provider->closeAdmission($plan->initial->reserve);
        $released = in_array(
            $original->status,
            [Status::NotExecuted, Status::Refused],
            true
        ) && $original->effectId === null;
        $release = null;
        if ($original->status === Status::Confirmed && $original->effectId !== null) {
            $release = $this->provider->attempt($plan->initial->release);
            $released = $release->status === Status::Confirmed && $release->effectId !== null;
        }
        if (! $released) {
            return [
                'released' => false,
                'originalStatus' => $original->status->value,
            ];
        }
        $payload = PortableDescriptor::encode([
            'scope' => $this->authority->plan($key)
                ->rootScope()
                ->toArray(),
            'original' => $plan->initial->toArray(),
            'originalStatus' => $original->status->value,
            'originalEffect' => $original->effectId,
            'releaseEffect' => $release?->effectId,
        ]);
        $this->locked($key, $row, function (object $current) use ($key, $payload): void {
            if ($current->expiry_receipt !== null && $current->expiry_receipt !== $payload) {
                throw new LogicException('The original cart expiry receipt changed.');
            }
            $this->authority->db->table('provider_cart_purchases')
                ->where('id', $key)
                ->update([
                    'expiry_receipt' => $payload,
                    'expiry_sha256' => hash('sha256', $payload),
                ]);
        });
        return [
            'released' => true,
        ];
    }

    public function selectLate(WorkflowRun $actual): array
    {
        [$key] = $this->actor($actual, true);
        [$row, $plan] = $this->row($key);
        $state = $this->provider->cartInventory()
            ->inspect($plan->initial);
        $noEffect = $state['admissionClosed'] && $state['heldQuantity'] === 0
            && in_array($state['reservationStatus'], [Status::NotExecuted->value, Status::Refused->value], true);
        if ($row->expiry_receipt === null || (! $state['released'] && ! $noEffect)) {
            throw new LogicException(
                'A late reservation needs the positive original expiry release or non-admission fence.'
            );
        }
        $this->locked($key, $row, function (object $current) use ($key): void {
            if ($current->selected !== null && $current->selected !== 'late') {
                throw new LogicException('The original cart has already selected another checkout lease.');
            }
            $this->authority->db->table('provider_cart_purchases')
                ->where('id', $key)
                ->update([
                    'selected' => 'late',
                ]);
        });
        return [
            'selected' => 'late',
        ];
    }

    public function associate(WorkflowRun $actual, array $value, string $step): array
    {
        [$key, $binding] = $this->actor($actual, false);
        [$row, $plan] = $this->row($key);
        if ($row->order_id !== null) {
            $order = $this->order($key);
            if ($order['selected'] !== $step || $order['paymentBinding'] !== PortableDescriptor::snapshot($value)) {
                throw new LogicException('Checkout retry changed its original stock or Payment association.');
            }
            return $order;
        }
        $this->actor($actual, true);
        $facts = $this->observe($actual, $value);
        $lease = $plan->lease($step);
        $stock = $facts[$step];
        if (! $facts['payment']['confirmed'] || $stock['reservationStatus'] !== Status::Confirmed->value
            || $stock['heldQuantity'] !== $lease->terms->quantity || $stock['released'] || $stock['admissionClosed']
            || ($step === 'initial' && ! $facts['paidBeforeInitialExpiry'])
            || ($step === 'late' && ($row->selected !== 'late' || $row->expiry_receipt === null))) {
            throw new LogicException(
                'Formal Order creation requires its original paid publication and admitted selected stock.'
            );
        }
        [, $terms] = $this->authority->assignment($key);
        $graph = ShipmentDescriptor::fromArray($terms->physicalDescriptor());
        $this->provider->cartInventory()
            ->bindFulfillment($lease, $graph, $terms->incomingPayment);
        $publication = $facts['payment']['publication'];
        $paymentBinding = ChildBinding::fromArray($value);
        $payload = PortableDescriptor::encode([
            'orderReference' => $plan->orderReference,
            'cartId' => $plan->initial->terms->cartId,
            'purchaseKey' => $key,
            'rootRun' => $actual->id,
            'scope' => $binding->rootScope()
                ->toArray(),
            'planFingerprint' => $plan->fingerprint(),
            'assignmentFingerprint' => $row->assignment_sha256,
            'selected' => $step,
            'reservation' => $lease->reserve->toArray(),
            'paymentBinding' => $paymentBinding->toArray(),
            'paymentPublication' => $publication,
        ]);
        $id = hash('sha256', PortableDescriptor::encode([$terms->client, $plan->orderReference]));
        $this->locked($key, $row, function (object $current) use ($key, $id, $payload, $publication, $step): void {
            if ($current->selected !== null && $current->selected !== $step) {
                throw new LogicException('Checkout changed its originally selected stock lease.');
            }
            $paid = $this->authority->db->table('example_machine_publications')
                ->where('id', $publication['id'])->lockForUpdate()->sole();
            $expected = $publication;
            unset($expected['id']);
            if ($paid->subject_id !== Authority::subjectId($key, 'payment')
                || PortableDescriptor::encode(
                    json_decode($paid->payload, true, flags: JSON_THROW_ON_ERROR)
                ) !== PortableDescriptor::encode($expected)) {
                throw new LogicException('The original paid publication changed before Order association.');
            }
            $existing = $this->authority->db->table('provider_cart_orders')
                ->where('id', $id)
                ->first();
            if ($existing !== null && ($existing->purchase_key !== $key || $existing->receipt !== $payload)) {
                throw new LogicException('The formal Order reference belongs to another original purchase.');
            }
            if ($existing === null) {
                $this->authority->db->table('provider_cart_orders')
                    ->insert([
                        'id' => $id,
                        'purchase_key' => $key,
                        'receipt' => $payload,
                        'receipt_sha256' => hash('sha256', $payload),
                    ]);
            }
            $this->authority->db->table('provider_cart_purchases')
                ->where('id', $key)
                ->update([
                    'selected' => $step,
                    'order_id' => $id,
                ]);
        });
        return $this->order($key);
    }

    public function cancel(WorkflowRun $actual, string $reason): array
    {
        [$key, $binding] = $this->actor($actual, false);
        if (! in_array($reason, ['cart-cancelled', 'cart-payment-timeout', 'cart-stock-refused'], true)) {
            throw new LogicException('The cart cancellation reason is unavailable.');
        }
        return app(CancellationStore::class)->request($key, $binding->rootRun, $reason);
    }

    public function verifyPrepared(WorkflowRun $actual): array
    {
        [$key] = $this->actor($actual, true);
        $order = $this->order($key);
        if (! app(FundingHandoffStore::class)->prepared($actual)) {
            throw new LogicException('Cart adoption cannot recreate missing payment or stock preparation.');
        }
        return $order;
    }

    public function order(string $key): array
    {
        [$row, $plan] = $this->row($key);
        if ($row->order_id === null) {
            throw new LogicException('This cart has no formal Order association.');
        }
        $order = $this->authority->db->table('provider_cart_orders')
            ->where('id', $row->order_id)
            ->sole();
        if ($order->purchase_key !== $key || ! hash_equals($order->receipt_sha256, hash('sha256', $order->receipt))) {
            throw new LogicException('The original formal Order association is unavailable or changed.');
        }
        $value = json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR);
        $binding = $this->authority->plan($key);
        $expectedId = hash(
            'sha256',
            PortableDescriptor::encode([$plan->initial->reserve->identity->client, $plan->orderReference])
        );
        if ($value['selected'] !== $row->selected || $value['planFingerprint'] !== $plan->fingerprint()
            || $value['purchaseKey'] !== $key || $value['assignmentFingerprint'] !== $row->assignment_sha256
            || $row->order_id !== $expectedId || $value['orderReference'] !== $plan->orderReference
            || $value['rootRun'] !== $binding->rootRun || $value['scope'] !== PortableDescriptor::snapshot(
                $binding->rootScope()
                    ->toArray()
            )
            || $value['paymentBinding']['identity'] !== PortableDescriptor::snapshot(
                $binding->binding('payment')
                    ->toArray()
            )
            || $value['reservation'] !== PortableDescriptor::snapshot(
                $plan->lease($row->selected)
                    ->reserve->toArray()
            )) {
            throw new LogicException('The formal Order substituted its original owner, plan or reservation.');
        }
        return $value;
    }

    private function row(string $key): array
    {
        $this->authority->authorize($key, $this->authority->principal);
        if (! $this->authority->cart($key)) {
            throw new LogicException('This purchase has no trusted cart entry contract.');
        }
        $this->authority->subject($key, 'order');
        $row = $this->authority->db->table('provider_cart_purchases')
            ->where('id', $key)
            ->sole();
        if ($row->principal !== $this->authority->principal || $row->authority_version !== $this->authority->grantFor(
            $key,
            'order'
        )
            || ! hash_equals($row->plan_sha256, hash('sha256', $row->plan)) || ! in_array(
                $row->selected,
                [null, 'initial', 'late'],
                true
            )
            || (($row->expiry_receipt === null) !== ($row->expiry_sha256 === null))
            || ($row->expiry_receipt !== null && ! hash_equals(
                $row->expiry_sha256,
                hash('sha256', $row->expiry_receipt)
            ))) {
            throw new LogicException('The original cart authority, plan or lifecycle receipt changed.');
        }
        return [$row, CartPlan::fromArray(json_decode($row->plan, true, flags: JSON_THROW_ON_ERROR))];
    }

    private function actor(WorkflowRun $actual, bool $forward): array
    {
        [$actor, $root, $binding] = $this->authority->actor($actual);
        if ($actual->workflow_class !== CartCheckout::class || $actor->role !== 'order' || ! $this->authority->cart(
            $actor->root_key
        )) {
            throw new LogicException('Cart work requires its original native purchase actor.');
        }
        $scope = (new ScopeRepository())->metadata($binding->rootScope());
        if ($scope['original_run_id'] !== $actual->id || ($forward && ($scope['status'] !== 'open' || (bool) $root->cancellation_requested))) {
            throw new LogicException('The original cart owner closed forward admission.');
        }
        return [$actor->root_key, $binding];
    }

    private function duties(WorkflowRun $actual, BindingPlan $binding, CartPlan $plan, bool $allowPrefix = false): array
    {
        $ledger = (new ScopeRepository())->inspect($binding->rootScope());
        $owner = new Obligation('owner', 'provider.owner', '1', [
            'key' => $binding->terms->subjectKey(),
            'rootRun' => $actual->id,
            'nativeRun' => $actual->id,
            'role' => 'order',
        ]);
        $root = $ledger['obligations'][0] ?? null;
        if ($root === null || (int) $root['position'] !== 1 || $root['fingerprint'] !== $owner->fingerprint()
            || Obligation::fromArray($root['descriptor'])->fingerprint() !== $owner->fingerprint()
            || (! $allowPrefix && $root['forward_status'] !== 'observed')) {
            throw new LogicException(
                'Cart duties require their genuine original recorded owner before provider access.'
            );
        }
        $rows = [];
        foreach ([
            'initial' => 1,
            'late' => 2,
        ] as $step => $position) {
            $row = $ledger['obligations'][$position] ?? null;
            if ($allowPrefix && $step === 'late' && $row === null && count($ledger['obligations']) === 2) {
                break;
            }
            $expected = $plan->obligation($step);
            if ($ledger['original_run_id'] !== $actual->id || $row === null || (int) $row['position'] !== $position + 1
                || $row['fingerprint'] !== $expected->fingerprint()
                || Obligation::fromArray($row['descriptor'])->fingerprint() !== $expected->fingerprint()) {
                throw new LogicException(
                    'Both original cart lease duties must precede Payment admission and provider access.'
                );
            }
            $rows[$step] = $row;
        }
        return $rows;
    }

    private function locked(string $key, object $expected, Closure $body): void
    {
        $binding = $this->authority->plan($key);
        $this->authority->db->transaction(function () use ($key, $expected, $body, $binding): void {
            $root = $this->authority->db->table('example_machine_subjects')
                ->where('id', Authority::subjectId($key, 'order'))->lockForUpdate()->sole();
            $this->authority->assertGrant($root, $key, 'order');
            $assignment = $this->authority->db->table('provider_machine_assignments')
                ->where('id', $key)
                ->lockForUpdate()
                ->sole();
            $scope = (new ScopeRepository())->metadata($binding->rootScope());
            $current = $this->authority->db->table('provider_cart_purchases')
                ->where('id', $key)
                ->lockForUpdate()
                ->sole();
            if ((bool) $root->cancellation_requested || $current->principal !== $this->authority->principal
                || $assignment->root_run !== $binding->rootRun || $scope['original_run_id'] !== $binding->rootRun || $scope['status'] !== 'open'
                || $current->authority_version !== $this->authority->grantFor($key, 'order')
                || $current->plan_sha256 !== $expected->plan_sha256 || $current->assignment_sha256 !== $expected->assignment_sha256
                || $current->order_id !== $expected->order_id || $current->selected !== $expected->selected) {
                throw new LogicException('The original cart was cancelled or changed before its protected write.');
            }
            $body($current);
        });
    }
}
