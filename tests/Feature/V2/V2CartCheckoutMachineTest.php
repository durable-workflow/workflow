<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ProviderStateMachines\CartStore;
use Illuminate\Support\Facades\DB;
use Tests\Support\CartCheckoutMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Native source scenarios; the fixture clock is controlled and this is not installed acceptance. */
final class V2CartCheckoutMachineTest extends TestCase
{
    use CartCheckoutMachineFixture;

    public function testCartPrecedesTheFormalOrderAndPaidCheckoutKeepsTheOriginalLeaseAndNativeOwner(): void
    {
        $origin = $this->startCart();
        $this->assertSame(0, DB::table('provider_cart_orders')->count(), $this->progress());
        $this->assertSame(['reserve'], $this->effects(), $this->progress());
        $before = (new ScopeRepository())->inspect(
            $this->store->authority->plan($this->terms->subjectKey())
                ->rootScope()
        );
        $this->assertSame($origin->run()->id, $before['original_run_id']);
        $this->assertSame(
            ['owner', 'cart.initial', 'cart.late', 'payment'],
            array_column(array_column($before['obligations'], 'descriptor'), 'key')
        );
        $this->pay('original-cart-payment');
        $this->assertSame(1, DB::table('provider_cart_orders')->count(), $this->progress());
        $order = app(CartStore::class)->order($this->terms->subjectKey());
        $this->assertSame($origin->run()->id, $order['rootRun']);
        $this->assertSame('initial', $order['selected']);
        $this->assertSame(
            $this->cart->initial->reserve->identity->toArray(),
            Request::fromArray($order['reservation'])->identity->toArray()
        );
        $this->assertSame(
            'ready',
            $this->store->authority->subject($this->terms->subjectKey(), 'order')
->state,
            $this->progress()
        );
        $this->assertSame(['reserve'], $this->effects());
        $after = (new ScopeRepository())->inspect(
            $this->store->authority->plan($this->terms->subjectKey())
                ->rootScope()
        );
        $this->assertSame(array_column($before['obligations'], 'id'), array_column($after['obligations'], 'id'));
        $this->assertSame('open', $after['status']);
        $origin->withRequestId('fulfill-original-cart')
            ->signal('fulfill');
        $this->pump();
        $this->physical('handed-over');
        $this->physical('delivered');
        $this->assertSame(
            'fulfilled',
            $this->store->authority->subject($this->terms->subjectKey(), 'order')
->state,
            $this->progress()
        );
        $this->assertSame(['reserve', 'ship-book', 'deduct-stock'], $this->effects());
    }

    public function testLateConfirmedPaymentCreatesANewReservationAfterTheOriginalActualRelease(): void
    {
        $origin = $this->startCart();
        $initialKey = $this->cart->initial->reserve->identity->key();
        $this->expireCart();
        $this->assertSame(['reserve', 'release'], $this->effects(), $this->progress());
        $this->assertSame(0, DB::table('provider_cart_orders')->count());
        $this->assertSame(
            'open',
            (new ScopeRepository())->metadata(
                $this->store->authority->plan($this->terms->subjectKey())
                    ->rootScope()
            )['status']
        );
        $this->assertTrue($this->provider->cartInventory()->inspect($this->cart->initial)['released']);
        $this->pay('late-original-cart-payment');
        $this->assertSame(1, DB::table('provider_cart_orders')->count(), $this->progress());
        $order = app(CartStore::class)->order($this->terms->subjectKey());
        $this->assertSame($origin->run()->id, $order['rootRun']);
        $this->assertSame('late', $order['selected']);
        $this->assertNotSame($initialKey, Request::fromArray($order['reservation'])->identity->key());
        $this->assertSame(
            $this->cart->late->reserve->identity->key(),
            Request::fromArray($order['reservation'])->identity->key()
        );
        $this->assertSame(['reserve', 'release', 'reserve'], $this->effects());
        $this->assertTrue($this->provider->cartInventory()->inspect($this->cart->initial)['released']);
    }
}
