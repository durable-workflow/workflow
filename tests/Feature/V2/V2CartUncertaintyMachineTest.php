<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\CartStore;
use Illuminate\Support\Facades\DB;
use Tests\Support\CartCheckoutMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

final class V2CartUncertaintyMachineTest extends TestCase
{
    use CartCheckoutMachineFixture;

    public function testUnknownOriginalHoldWaitsForFactualResolutionWithoutChangingItsOwnerOrRefundingMoney(): void
    {
        $this->provider->plan($this->cart->initial->reserve, Status::Unknown);
        $origin = $this->startCart();
        $this->assertSame('waiting', $origin->refresh()->run()->status->value, $this->progress());
        $this->pay('paid-while-original-stock-unknown');
        $this->assertSame('waiting', $origin->refresh()->run()->status->value, $this->progress());
        $this->assertSame([], $this->effects());
        $this->assertSame(0, DB::table('provider_cart_orders')->count());
        $scope = $this->store->authority->plan($this->terms->subjectKey())
            ->rootScope();
        $before = (new ScopeRepository())->inspect($scope);
        $this->assertSame('open', $before['status']);
        $this->provider->resolve($this->cart->initial->reserve, Status::Confirmed);
        $origin->withRequestId('original-cart-stock-confirmed')
            ->signal('reconcile-stock');
        $this->pump();
        $order = app(CartStore::class)->order($this->terms->subjectKey());
        $this->assertSame('initial', $order['selected']);
        $this->assertSame($origin->runId(), $order['rootRun']);
        $this->assertSame(['reserve'], $this->effects());
        $after = (new ScopeRepository())->inspect($scope);
        $this->assertSame(array_column($before['obligations'], 'id'), array_column($after['obligations'], 'id'));
        $this->assertSame('open', $after['status']);
    }

    public function testUnknownLateHoldReconcilesTheSameNewReservationBeforeCreatingTheOrder(): void
    {
        $origin = $this->startCart(Status::Unknown);
        $this->expireCart();
        $this->pay('late-paid-while-stock-unknown');
        $this->assertSame('waiting', $origin->refresh()->run()->status->value, $this->progress());
        $this->assertSame(['reserve', 'release'], $this->effects());
        $this->assertSame(0, DB::table('provider_cart_orders')->count());
        $this->assertSame(Status::Unknown, $this->provider->lookup($this->cart->late->reserve)->status);
        $scope = $this->store->authority->plan($this->terms->subjectKey())
            ->rootScope();
        $before = (new ScopeRepository())->inspect($scope);
        $this->provider->resolve($this->cart->late->reserve, Status::Confirmed);
        $origin->withRequestId('late-cart-stock-confirmed')
            ->signal('reconcile-stock');
        $this->pump();
        $order = app(CartStore::class)->order($this->terms->subjectKey());
        $this->assertSame('late', $order['selected']);
        $this->assertSame($origin->runId(), $order['rootRun']);
        $this->assertSame(['reserve', 'release', 'reserve'], $this->effects());
        $after = (new ScopeRepository())->inspect($scope);
        $this->assertSame(array_column($before['obligations'], 'id'), array_column($after['obligations'], 'id'));
        $this->assertTrue($this->provider->cartInventory()->inspect($this->cart->initial)['released']);
    }
}
