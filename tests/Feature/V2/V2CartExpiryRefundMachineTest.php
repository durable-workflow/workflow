<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use Illuminate\Support\Facades\DB;
use Tests\Support\CartCheckoutMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

final class V2CartExpiryRefundMachineTest extends TestCase
{
    use CartCheckoutMachineFixture;

    public function testUnpaidExpiryReleasesStockWithoutClosingTheDistinctPaymentWindow(): void
    {
        $this->startCart();
        $this->expireCart();
        $scope = (new ScopeRepository())->metadata(
            $this->store->authority->plan($this->terms->subjectKey())
                ->rootScope()
        );
        $this->assertSame('open', $scope['status'], $this->progress());
        $this->assertFalse(
            (bool) $this->store->authority->subject($this->terms->subjectKey(), 'order')
->cancellation_requested
        );
        $this->assertSame(['reserve', 'release'], $this->effects());
        $stock = $this->provider->cartInventory()
            ->inspect($this->cart->initial);
        $this->assertTrue($stock['released']);
        $this->assertSame(2, $stock['availableQuantity']);
        $this->assertSame(0, DB::table('provider_cart_orders')->count());
        $this->assertSame(Status::Unknown, $this->provider->lookup($this->cart->late->reserve)->status);
    }

    public function testDefiniteLateStockRefusalRefundsTheOriginalPaymentWithoutCreatingAnOrder(): void
    {
        $this->startCart(Status::Refused);
        $this->expireCart();
        $this->pay('late-money-no-stock');
        $scope = (new ScopeRepository())->inspect(
            $this->store->authority->plan($this->terms->subjectKey())
                ->rootScope()
        );
        $this->assertSame('verified', $scope['status'], $this->progress());
        $this->assertSame(0, DB::table('provider_cart_orders')->count());
        $this->assertSame(['reserve', 'release', 'refund-incoming'], $this->effects());
        $this->assertSame(Status::Refused, $this->provider->lookup($this->cart->late->reserve)->status);
        $this->assertSame(2, $this->provider->cartInventory()->inspect($this->cart->initial)['availableQuantity']);
        $this->assertTrue(
            (bool) $this->store->authority->subject($this->terms->subjectKey(), 'order')
->cancellation_requested
        );
    }
}
