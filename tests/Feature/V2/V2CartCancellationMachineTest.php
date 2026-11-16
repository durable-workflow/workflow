<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\CartStore;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\CartCheckoutMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Cancellation retains the original purchase and factual refund-before-release ordering. */
final class V2CartCancellationMachineTest extends TestCase
{
    use CartCheckoutMachineFixture;

    public function testCancellationBeforeOrderCreationClosesOriginalPaymentAdmissionAndReleasesStock(): void
    {
        $origin = $this->startCart();
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        app(CancellationStore::class)->request($this->terms->subjectKey(), $origin->runId(), 'cancel-unpaid-cart');
        $this->pump();
        $scope = (new ScopeRepository())->metadata($plan->rootScope());
        $this->assertSame('verified', $scope['status'], $this->progress());
        $this->assertSame($origin->runId(), $scope['original_run_id']);
        $this->assertSame(0, DB::table('provider_cart_orders')->count());
        $this->assertSame(['reserve', 'release'], $this->effects());
        $this->assertSame(2, $this->provider->cartInventory()->inspect($this->cart->initial)['availableQuantity']);
        try {
            $this->incoming->receipts->record($this->terms->incomingPayment, new IncomingPaymentReceipt(
                $this->terms->incomingPayment->identity->key(),
                'after-cart-cancellation',
                1499,
                'USD',
                now()
                    ->getTimestamp()
            ));
            $this->fail('A positively fenced original reference cannot accept new money.');
        } catch (LogicException $error) {
            $this->assertSame(
                'The original incoming reference rejects new transfers after its provider fence.',
                $error->getMessage()
            );
        }
        $this->assertSame(0, $this->incoming->receipts->inspect($this->terms->incomingPayment)['receivedAmountMinor']);
    }

    public function testCancellationAfterOrderCreationWaitsForTheOriginalRefundBeforeReleasingStock(): void
    {
        $origin = $this->startCart();
        $this->pay('paid-cart-cancellation', Status::Processing);
        $key = $this->terms->subjectKey();
        $plan = $this->store->authority->plan($key);
        $order = app(CartStore::class)->order($key);
        $this->assertSame('initial', $order['selected']);
        $this->assertSame(1, DB::table('provider_cart_orders')->count());
        $receipt = IncomingPaymentReceipt::fromArray(
            $this->incoming->receipts->inspect($this->terms->incomingPayment)['receipts'][0]
        );
        $refund = IncomingPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.incoming',
            $this->terms->incomingPayment,
            $receipt
        );
        $cancellation = app(CancellationStore::class);
        $cancellation->request($key, $origin->runId(), 'cancel-paid-cart');
        $this->pump();
        $this->assertSame(
            'requested',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $this->assertSame(['reserve'], $this->effects());
        $this->assertSame(0, $this->provider->cartInventory()->inspect($this->cart->initial)['availableQuantity']);
        $this->assertSame('pending', $cancellation->finish($key, $origin->runId(), 'cancel-paid-cart')['status']);
        $this->provider->resolve($refund, Status::Confirmed);
        $cancellation->request($key, $origin->runId(), 'original-cart-refund-confirmed');
        $this->pump();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $this->assertSame(['reserve', 'refund-incoming', 'release'], $this->effects());
        $this->assertSame($order, app(CartStore::class)->order($key));
        $this->assertSame($origin->runId(), (new ScopeRepository())->metadata($plan->rootScope())['original_run_id']);
        $cancellation->finish($key, $origin->runId(), 'cancel-paid-cart');
        $this->pump();
        $this->assertSame('cancelled', $this->store->authority->subject($key, 'order')->state, $this->progress());
        $this->assertSame(2, $this->provider->cartInventory()->inspect($this->cart->initial)['availableQuantity']);
    }
}
