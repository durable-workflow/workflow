<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use Closure;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CartInventory;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CartReservationDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CartReservationTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\LostAcknowledgement;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

require_once __DIR__ . '/../../../../examples/configured-state-machines/providers/bootstrap.php';

final class CartReservationProviderTest extends TestCase
{
    private PDO $db;

    private Journal $provider;

    private CartInventory $stock;

    private int $now = 1000;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->provider = new Journal($this->db, 'shop-a', fn (): int => $this->now);
        $this->provider->install();
        $this->stock = $this->provider->cartInventory();
        $this->stock->install();
    }

    public function testAnUnavailableSkuIsNotKnownZeroStockOrAnAvailabilityPromise(): void
    {
        $cart = $this->cart();
        $before = $this->stock->inspect($cart);
        $this->assertFalse($before['inventoryKnown']);
        $this->assertNull($before['availableQuantity']);
        $this->assertFalse($before['eligibleForFulfillment']);
        $this->provider->plan($cart->reserve, Status::Confirmed);
        $this->assertSame(Status::NotExecuted, $this->provider->attempt($cart->reserve)->status);
        $this->stock->receive('sku-a', 'known-empty', 0);
        $this->provider->plan($cart->reserve, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($cart->reserve)->status);
        $after = $this->stock->inspect($cart);
        $this->assertTrue($after['inventoryKnown']);
        $this->assertSame(0, $after['availableQuantity']);
        $this->assertSame(0, $this->effects());
    }

    public function testWarehouseReceiptReplayCannotCreateMoreStockOrChangeItsSku(): void
    {
        $this->stock->receive('sku-a', 'receipt-1', 2);
        $this->stock->receive('sku-a', 'receipt-1', 2);
        $this->assertSame(2, $this->stock->inspect($this->cart())['availableQuantity']);
        $this->refuses(fn () => $this->stock->receive('sku-b', 'receipt-1', 2));
        $this->refuses(fn () => $this->stock->receive('sku-a', 'receipt-1', 3));
        $this->assertSame(
            1,
            (int) $this->db->query('SELECT COUNT(*) FROM provider_cart_stock_receipts')
                ->fetchColumn()
        );
    }

    public function testReceiptOverflowRollsBackWithoutLeavingAReplayMarker(): void
    {
        $this->stock->receive('sku-a', 'maximum', PHP_INT_MAX);
        $this->refuses(fn () => $this->stock->receive('sku-a', 'overflow', 1));
        $this->assertSame(PHP_INT_MAX, $this->stock->inspect($this->cart())['availableQuantity']);
        $this->assertSame(
            1,
            (int) $this->db->query('SELECT COUNT(*) FROM provider_cart_stock_receipts')
                ->fetchColumn()
        );
    }

    public function testLostAcknowledgementsAndRepeatedCommandsKeepOneHoldAndOneRelease(): void
    {
        $cart = $this->cart();
        $this->stock->receive('sku-a', 'receipt', 2);
        foreach ([$cart->reserve, $cart->release] as $request) {
            $this->provider->plan($request, Status::Confirmed, true);
            try {
                $this->provider->attempt($request);
                $this->fail('The committed provider acknowledgement must be lost.');
            } catch (LostAcknowledgement) {
                $this->assertSame(Status::Confirmed, $this->provider->lookup($request)->status);
            }
            $this->assertSame(Status::Confirmed, $this->provider->attempt($request)->status);
            $this->assertSame($request === $cart->reserve ? 0 : 2, $this->stock->inspect($cart)['availableQuantity']);
        }
        $this->assertSame(2, $this->effects());
        $this->assertTrue($this->stock->inspect($cart)['released']);
        $this->assertFalse($this->stock->inspect($cart)['eligibleForFulfillment']);
    }

    public function testExpiryPermanentlyClosesNewAdmissionEvenIfTheClockMovesBack(): void
    {
        $cart = $this->cart();
        $this->stock->receive('sku-a', 'receipt', 2);
        $this->now = 1060;
        $this->provider->plan($cart->reserve, Status::Confirmed);
        $this->assertSame(Status::NotExecuted, $this->provider->attempt($cart->reserve)->status);
        $this->now = 1000;
        $this->provider->plan($cart->reserve, Status::Confirmed);
        $this->assertSame(Status::NotExecuted, $this->provider->attempt($cart->reserve)->status);
        $this->assertTrue($this->stock->inspect($cart)['admissionClosed']);
        $this->assertSame(2, $this->stock->inspect($cart)['availableQuantity']);
        $this->assertSame(0, $this->effects());
    }

    public function testAnAdmittedUnknownHoldCannotBeReleasedUntilItsActualLateOutcome(): void
    {
        $cart = $this->cart();
        $this->stock->receive('sku-a', 'receipt', 2);
        $this->provider->plan($cart->reserve, Status::Unknown);
        $this->assertSame(Status::Unknown, $this->provider->attempt($cart->reserve)->status);
        $this->provider->closeAdmission($cart->reserve);
        $this->now = 2000;
        $this->provider->plan($cart->release, Status::Confirmed);
        $this->assertSame(Status::Unknown, $this->provider->attempt($cart->release)->status);
        $this->refuses(fn () => $this->provider->resolve($cart->release, Status::Confirmed));
        $this->assertSame(0, $this->stock->inspect($cart)['availableQuantity']);
        $this->assertSame(Status::Confirmed, $this->provider->resolve($cart->reserve, Status::Confirmed)->status);
        $this->assertFalse($this->stock->inspect($cart)['eligibleForFulfillment']);
        $this->assertSame(Status::Confirmed, $this->provider->resolve($cart->release, Status::Confirmed)->status);
        $this->assertSame(2, $this->stock->inspect($cart)['availableQuantity']);
        $this->assertSame(2, $this->effects());
    }

    public function testDefinitiveNonExecutionReturnsTentativeStockWithoutInventingAnEffect(): void
    {
        $cart = $this->cart();
        $this->stock->receive('sku-a', 'receipt', 2);
        $this->provider->plan($cart->reserve, Status::Processing);
        $this->provider->attempt($cart->reserve);
        $this->provider->closeAdmission($cart->reserve);
        $this->assertSame(Status::NotExecuted, $this->provider->resolve($cart->reserve, Status::NotExecuted)->status);
        $this->assertSame(2, $this->stock->inspect($cart)['availableQuantity']);
        $this->assertSame(0, $this->effects());
        $this->refuses(fn () => $this->provider->resolve($cart->reserve, Status::Confirmed));
    }

    public function testAnUncertainReleaseRetainsStockUntilConfirmedOnce(): void
    {
        $cart = $this->cart();
        $this->stock->receive('sku-a', 'receipt', 2);
        $this->confirm($cart->reserve);
        $this->provider->plan($cart->release, Status::Processing);
        $this->provider->attempt($cart->release);
        $this->assertSame(0, $this->stock->inspect($cart)['availableQuantity']);
        $this->assertFalse($this->stock->inspect($cart)['eligibleForFulfillment']);
        $this->provider->resolve($cart->release, Status::Confirmed);
        $this->provider->attempt($cart->release);
        $this->assertSame(2, $this->stock->inspect($cart)['availableQuantity']);
        $this->assertSame(2, $this->effects());
    }

    public function testExpiryAloneCannotAdmitADistinctLateReservation(): void
    {
        $cart = $this->cart();
        $this->stock->receive('sku-a', 'receipt', 4);
        $this->confirm($cart->reserve);
        $this->now = 1060;
        $next = $this->cart('cart-a', 'purchase-a', 'g1', 'late', 1060, 1120, $cart->reserve->identity);
        $this->provider->plan($next->reserve, Status::Confirmed);
        $this->assertSame(Status::NotExecuted, $this->provider->attempt($next->reserve)->status);
        $this->assertSame(2, $this->stock->inspect($cart)['availableQuantity']);
        $this->confirm($cart->release);
        $this->confirm($next->reserve);
        $this->assertNotSame($cart->reserve->identity->key(), $next->reserve->identity->key());
        $this->assertTrue($this->stock->inspect($next)['eligibleForFulfillment']);
        $this->assertSame(2, $this->stock->inspect($next)['availableQuantity']);
        $this->assertSame(1060, $cart->terms->expiresAt);
        $this->assertFalse($this->stock->inspect($cart)['eligibleForFulfillment']);
    }

    public function testAClosedNeverAdmittedLeaseAllowsOneDeclaredSuccessor(): void
    {
        $cart = $this->cart();
        $this->stock->receive('sku-a', 'receipt', 2);
        $this->assertSame(Status::NotExecuted, $this->provider->closeAdmission($cart->reserve)->status);
        $this->now = 1060;
        $next = $this->cart('cart-a', 'purchase-a', 'g1', 'late', 1060, 1120, $cart->reserve->identity);
        $this->confirm($next->reserve);
        $other = $this->cart('cart-a', 'purchase-a', 'g1', 'other', 1060, 1120, $cart->reserve->identity);
        $this->provider->plan($other->reserve, Status::Confirmed);
        $this->refuses(fn () => $this->provider->attempt($other->reserve));
        $this->assertSame(Status::Unknown, $this->provider->lookup($other->reserve)->status);
        $this->assertSame(1, $this->effects());
    }

    public function testAStaleAvailabilityReadCannotOversellStockToTheLateReservation(): void
    {
        $cart = $this->cart();
        $this->stock->receive('sku-a', 'receipt', 2);
        $this->confirm($cart->reserve);
        $this->now = 1060;
        $this->confirm($cart->release);
        $next = $this->cart('cart-a', 'purchase-a', 'g1', 'late', 1060, 1120, $cart->reserve->identity);
        $this->assertSame(2, $this->stock->inspect($next)['availableQuantity']);
        $competitor = $this->cart('cart-b', 'purchase-b', 'g1', 'original', 1060, 1120);
        $this->confirm($competitor->reserve);
        $this->provider->plan($next->reserve, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($next->reserve)->status);
        $this->assertSame(0, $this->stock->inspect($next)['availableQuantity']);
        $this->assertFalse($this->stock->inspect($next)['eligibleForFulfillment']);
        $this->assertSame(3, $this->effects());
    }

    public function testTheOriginalCartCannotBeTransferredToAnotherPurchaseOrGeneration(): void
    {
        $this->stock->receive('sku-a', 'receipt', 2);
        $cart = $this->cart();
        $this->confirm($cart->reserve);
        foreach ([['purchase-b', 'g1'], ['purchase-a', 'g2']] as [$subject, $generation]) {
            $foreign = $this->cart('cart-a', $subject, $generation);
            $this->provider->plan($foreign->reserve, Status::Confirmed);
            $this->refuses(fn () => $this->provider->attempt($foreign->reserve));
        }
        $this->assertSame(1, $this->effects());
        $this->assertSame(0, $this->stock->inspect($cart)['availableQuantity']);
    }

    public function testAReleaseCannotReplaceTheOriginalLeaseTerms(): void
    {
        $cart = $this->cart();
        $terms = new CartReservationTerms('cart-a', 'sku-a', 2, 1000, 1061, 'original-release');
        $changed = new Request($cart->release->identity, 'release', $terms->toArray(), $cart->reserve->identity);
        $this->expectException(InvalidArgumentException::class);
        new CartReservationDescriptor($cart->reserve, $changed);
    }

    public function testRetryCannotExtendTheOriginalDeadline(): void
    {
        $cart = $this->cart();
        $this->provider->plan($cart->reserve, Status::NotExecuted);
        $this->provider->attempt($cart->reserve);
        $changed = $this->cart('cart-a', 'purchase-a', 'g1', 'original', 1000, 1120);
        $this->refuses(fn () => $this->provider->plan($changed->reserve, Status::Confirmed));
        $this->assertSame(1060, $this->stock->inspect($cart)['original']['reserve']['terms']['expiresAt']);
    }

    public function testLostOriginalEvidenceCannotBeRebuiltToAllocateStockAgain(): void
    {
        $cart = $this->cart();
        $this->stock->receive('sku-a', 'receipt', 4);
        $this->confirm($cart->reserve);
        $this->db->exec('DELETE FROM provider_operations');
        $this->db->exec('DELETE FROM provider_cart_reservations');
        $this->provider->plan($cart->reserve, Status::Confirmed);
        $this->refuses(fn () => $this->provider->attempt($cart->reserve));
        $this->assertSame(2, (int) $this->db->query('SELECT available FROM provider_cart_stock')->fetchColumn());
    }

    public function testAnAdmittedSuccessorCannotRebuildItsLostEvidence(): void
    {
        $cart = $this->cart();
        $this->stock->receive('sku-a', 'receipt', 4);
        $this->confirm($cart->reserve);
        $this->now = 1060;
        $this->confirm($cart->release);
        $next = $this->cart('cart-a', 'purchase-a', 'g1', 'late', 1060, 1120, $cart->reserve->identity);
        $this->confirm($next->reserve);
        foreach (['provider_operations', 'provider_cart_reservations'] as $table) {
            $statement = $this->db->prepare('DELETE FROM ' . $table . ' WHERE id = ?');
            $statement->execute([$next->reserve->identity->key()]);
        }
        $this->provider->plan($next->reserve, Status::Confirmed);
        $this->refuses(fn () => $this->provider->attempt($next->reserve));
        $this->assertSame(2, (int) $this->db->query('SELECT available FROM provider_cart_stock')->fetchColumn());
    }

    public function testARehashedForeignOperationCannotBecomeCartInspectionEvidence(): void
    {
        $cart = $this->cart();
        $this->stock->receive('sku-a', 'receipt', 2);
        $this->confirm($cart->reserve);
        $foreign = $this->cart('cart-b', 'purchase-b')
->reserve;
        $payload = PortableDescriptor::encode($foreign->toArray());
        $statement = $this->db->prepare('UPDATE provider_operations SET request = ?, fingerprint = ? WHERE id = ?');
        $statement->execute([$payload, hash('sha256', $payload), $cart->reserve->identity->key()]);
        $this->refuses(fn () => $this->stock->inspect($cart));
    }

    public function testMissingEffectEvidenceCannotAuthorizeFulfillmentOrAReleaseReplay(): void
    {
        $cart = $this->cart();
        $this->stock->receive('sku-a', 'receipt', 2);
        $this->confirm($cart->reserve);
        $this->db->exec('DELETE FROM provider_effects');
        $this->refuses(fn () => $this->stock->inspect($cart));
        $this->refuses(fn () => $this->provider->lookup($cart->reserve));
        $this->refuses(fn () => $this->provider->attempt($cart->reserve));
        $this->provider->plan($cart->release, Status::Confirmed);
        $this->refuses(fn () => $this->provider->attempt($cart->release));
        $this->assertSame(0, (int) $this->db->query('SELECT available FROM provider_cart_stock')->fetchColumn());
    }

    public static function physicalStatuses(): array
    {
        return [[Status::Confirmed], [Status::Processing], [Status::Unknown]];
    }

    #[DataProvider('physicalStatuses')]
    public function testAnAdmittedPhysicalDeductionCannotBecomeSaleableStockOnLeaseRelease(Status $status): void
    {
        $cart = $this->cart();
        $this->stock->receive('sku-a', 'receipt', 2);
        $this->confirm($cart->reserve);
        $id = static fn (string $operation): Identity => new Identity('shop-a', 'purchase-a', 'g1', $operation);
        $terms = (new ShipmentTerms('sku-a', 2, 'carrier'))->toArray();
        $booking = $id('booking');
        $return = $id('return');
        $shipment = new ShipmentDescriptor(
            new Request($booking, 'ship-book', $terms),
            new Request($id('cancel-booking'), 'ship-cancel-booking', $terms, $booking),
            new Request($return, 'request-return', $terms, $booking),
            new Request($id('restock'), 'restock', $terms, $return),
            new Request($id('deduct'), 'deduct-stock', $terms, $booking)
        );
        $this->provider->shipments()
            ->install();
        $this->provider->shipments()
            ->register($shipment);
        $this->confirm($shipment->booking);
        $this->provider->shipments()
            ->record($shipment, 'carrier-handover', 'handed-over', 2);
        $this->provider->plan($shipment->deduction, $status);
        $this->assertSame($status, $this->provider->attempt($shipment->deduction)->status);
        $this->provider->plan($cart->release, Status::Confirmed);
        $this->assertSame(Status::Unknown, $this->provider->attempt($cart->release)->status);
        $this->refuses(fn () => $this->provider->resolve($cart->release, Status::Confirmed));
        $this->assertSame(0, $this->stock->inspect($cart)['availableQuantity']);
        $this->assertFalse($this->stock->inspect($cart)['released']);
    }

    public function testClientIsolationPrecedesAnInventoryObservation(): void
    {
        $foreign = new CartInventory($this->db, 'shop-b');
        $this->refuses(fn () => $foreign->inspect($this->cart()));
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM provider_cart_reservations')->fetchColumn());
    }

    public function testARehashedSuccessorCannotReplaceTheOriginalLeaseAndAdmitAThirdReservation(): void
    {
        $cart = $this->cart();
        $this->stock->receive('sku-a', 'receipt', 2);
        $this->confirm($cart->reserve);
        $this->now = 1060;
        $this->confirm($cart->release);
        $next = $this->cart('cart-a', 'purchase-a', 'g1', 'late', 1060, 1120, $cart->reserve->identity);
        $this->confirm($next->reserve);
        $this->now = 1120;
        $this->confirm($next->release);
        $payload = PortableDescriptor::encode($next->reserve->toArray());
        $statement = $this->db->prepare('UPDATE provider_operations SET request = ?, fingerprint = ? WHERE id = ?');
        $statement->execute([$payload, hash('sha256', $payload), $cart->reserve->identity->key()]);
        $third = $this->cart('cart-a', 'purchase-a', 'g1', 'third', 1120, 1180, $cart->reserve->identity);
        $this->provider->plan($third->reserve, Status::Confirmed);
        $this->refuses(fn () => $this->provider->attempt($third->reserve));
        $this->assertSame(2, (int) $this->db->query('SELECT available FROM provider_cart_stock')->fetchColumn());
        $this->assertSame(Status::Unknown, $this->provider->lookup($third->reserve)->status);
    }

    public function testLegacyReserveFixturesNeedNoCartSchemaAndKeepTheirWire(): void
    {
        $db = new PDO('sqlite::memory:');
        $provider = new Journal($db, 'shop-a');
        $provider->install();
        $request = new Request(new Identity('shop-a', 'purchase-a', 'g1', 'reserve'), 'reserve', [
            'sku' => 'sku-a',
        ]);
        $provider->plan($request, Status::Confirmed);
        $this->assertSame(Status::Confirmed, $provider->attempt($request)->status);
        $this->assertSame([
            'sku' => 'sku-a',
        ], $request->terms);
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(4, $tables);
    }

    private function cart(
        string $cartId = 'cart-a',
        string $subject = 'purchase-a',
        string $generation = 'g1',
        string $step = 'original',
        int $createdAt = 1000,
        int $expiresAt = 1060,
        ?Identity $previous = null,
    ): CartReservationDescriptor {
        $reserve = new Identity('shop-a', $subject, $generation, $step . '-reserve');
        $release = new Identity('shop-a', $subject, $generation, $step . '-release');
        $terms = (new CartReservationTerms($cartId, 'sku-a', 2, $createdAt, $expiresAt, $release->operation, $previous))
            ->toArray();
        return new CartReservationDescriptor(new Request($reserve, 'reserve', $terms), new Request(
            $release,
            'release',
            $terms,
            $reserve
        ));
    }

    private function confirm(Request $request): void
    {
        $this->provider->plan($request, Status::Confirmed);
        $this->assertSame(Status::Confirmed, $this->provider->attempt($request)->status);
    }

    private function effects(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM provider_effects')
            ->fetchColumn();
    }

    private function refuses(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Missing or substituted original evidence must be refused.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }
}
