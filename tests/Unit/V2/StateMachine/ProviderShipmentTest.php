<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\LostAcknowledgement;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\OperationContext;

require_once __DIR__ . '/../../../../examples/configured-state-machines/providers/bootstrap.php';

final class ProviderShipmentTest extends TestCase
{
    private PDO $db;

    private Journal $provider;

    private ShipmentDescriptor $shipment;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->provider = new Journal($this->db, 'shop-a');
        $this->provider->install();
        $this->provider->shipments()
            ->install();
        $this->shipment = $this->descriptor();
        $this->provider->shipments()
            ->register($this->shipment);
    }

    public function testBookingAndReturnRequestAreNotDeliveryReceiptInspectionOrRestocking(): void
    {
        $this->confirm($this->shipment->booking);
        $facts = $this->facts();
        $this->assertTrue($facts['booking_confirmed']);
        $this->assertFalse($facts['original_recipient_delivered']);
        $this->fact('handed-over');
        $this->confirm($this->shipment->return);
        $facts = $this->facts();
        $this->assertTrue($facts['return_requested']);
        $this->assertSame(0, $facts['warehouse_received_quantity']);
        $this->assertFalse($facts['warehouse_inspected']);
        $this->assertSame(0, $facts['restocked_quantity']);
    }

    public function testDeliveryRemainsHistoricalAfterAcceptedReturnAndSeparateRestocking(): void
    {
        $this->delivered();
        $delivery = $this->provider->shipments()
            ->inspect($this->shipment)
->events['delivered'];
        $this->confirm($this->shipment->return);
        $this->fact('received');
        $this->assertFalse($this->facts()['warehouse_inspected']);
        $this->fact('inspected', 3, 3, 3);
        $this->assertTrue($this->facts()['full_return_accepted']);
        $this->assertSame(3, $this->facts()['saleable_quantity']);
        $this->assertSame(0, $this->facts()['restocked_quantity']);
        $this->confirm($this->shipment->restock);
        $this->provider->attempt($this->shipment->restock);
        $this->assertTrue($this->facts()['original_recipient_delivered']);
        $this->assertSame($delivery, $this->provider->shipments()->inspect($this->shipment)->events['delivered']);
        $this->assertSame(3, $this->facts()['restocked_quantity']);
        $this->assertSame(
            1,
            (int) $this->db->query(
                "SELECT COUNT(*) FROM provider_effects WHERE operation_id = '" . $this->shipment->restock->identity->key() . "'"
            )->fetchColumn()
        );
    }

    public function testAdmittedUncertainCancellationFencesHandoverBeforeItConfirms(): void
    {
        $this->confirm($this->shipment->booking);
        $this->provider->plan($this->shipment->cancel, Status::Processing);
        $this->assertSame(Status::Processing, $this->provider->attempt($this->shipment->cancel)->status);
        try {
            $this->fact('handed-over');
            $this->fail('An admitted cancellation must block new carrier handover.');
        } catch (LogicException) {
            $this->assertFalse($this->facts()['original_carrier_handover']);
        }
        $this->provider->resolve($this->shipment->cancel, Status::Confirmed);
        $this->assertSame(Status::Confirmed, $this->provider->lookup($this->shipment->cancel)->status);
    }

    public function testHandoverWinningTheRaceRefusesBookingCancellationAndKeepsReturnSeparate(): void
    {
        $this->confirm($this->shipment->booking);
        $this->fact('handed-over');
        $this->provider->plan($this->shipment->cancel, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($this->shipment->cancel)->status);
        $this->confirm($this->shipment->return);
        $this->assertFalse($this->facts()['full_return_accepted']);
        $this->assertSame(0, $this->facts()['restocked_quantity']);
    }

    public static function insufficientReturns(): array
    {
        return [['none', 0, 0, 0], ['receipt', 3, 0, 0], ['damaged', 3, 3, 0], ['partial', 1, 1, 1]];
    }

    #[DataProvider('insufficientReturns')]
    public function testMissingUninspectedDamagedOrPartialGoodsCannotRestockTheFullParcel(
        string $kind,
        int $quantity,
        int $accepted,
        int $saleable
    ): void {
        $this->delivered();
        $this->confirm($this->shipment->return);
        if ($quantity > 0) {
            $this->fact('received', $quantity);
        }
        if (in_array($kind, ['damaged', 'partial'], true)) {
            $this->fact('inspected', $quantity, $accepted, $saleable);
        }
        $this->provider->plan($this->shipment->restock, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($this->shipment->restock)->status);
        $this->assertSame(0, $this->facts()['restocked_quantity']);
        $this->assertTrue($this->facts()['original_recipient_delivered']);
        if ($kind === 'partial') {
            $this->assertFalse($this->facts()['full_return_accepted']);
            $this->assertSame(1, $this->facts()['accepted_quantity']);
        }
    }

    public function testConfirmedLossIsNotDeliveryOrReturnedInventory(): void
    {
        $this->confirm($this->shipment->booking);
        $this->fact('handed-over');
        $this->fact('lost');
        $this->assertTrue($this->facts()['carrier_loss_confirmed']);
        $this->assertFalse($this->facts()['original_recipient_delivered']);
        $this->provider->plan($this->shipment->return, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($this->shipment->return)->status);
        $this->expectException(LogicException::class);
        $this->fact('received');
    }

    public function testLaterLossDoesNotEraseAnAlreadyAcceptedReturnRequest(): void
    {
        $this->confirm($this->shipment->booking);
        $this->fact('handed-over');
        $this->confirm($this->shipment->return);
        $this->fact('lost');
        $facts = $this->facts();
        $this->assertTrue($facts['return_requested']);
        $this->assertTrue($facts['carrier_loss_confirmed']);
        $this->assertSame(0, $facts['warehouse_received_quantity']);
        $this->assertSame(0, $facts['restocked_quantity']);
        $this->provider->attempt($this->shipment->return);
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM provider_effects')->fetchColumn());
    }

    public function testDuplicatePhysicalFactsResumeWithoutRevisingTheOriginalEvent(): void
    {
        $this->delivered();
        $old = $this->provider->shipments()
            ->inspect($this->shipment)
->events['delivered'];
        $duplicate = $this->provider->shipments()
            ->record($this->shipment, 'duplicate-delivery-event', 'delivered', 3);
        $this->assertSame($old, $duplicate);
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM provider_shipment_events')->fetchColumn());
        $this->expectException(LogicException::class);
        $this->provider->shipments()
            ->record($this->shipment, 'event-delivered', 'delivered', 2);
    }

    public function testLostBookingAcknowledgementAndReplacementReaderKeepOneOriginalEffect(): void
    {
        $this->provider->plan($this->shipment->booking, Status::Confirmed, true);
        try {
            $this->provider->attempt($this->shipment->booking);
            $this->fail('The fixture must lose the committed booking acknowledgement.');
        } catch (LostAcknowledgement) {
            $fresh = new Journal($this->db, 'shop-a');
            $this->assertSame(Status::Confirmed, $fresh->lookup($this->shipment->booking)->status);
            $fresh->attempt($this->shipment->booking);
            $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM provider_effects')->fetchColumn());
        }
    }

    public function testAnotherReturnKeyCannotReplaceTheRegisteredOriginalGraph(): void
    {
        $this->delivered();
        $other = $this->descriptor('replacement-return');
        $this->provider->plan($other->return, Status::Confirmed);
        try {
            $this->provider->attempt($other->return);
            $this->fail('A same-owner replacement return is not the original operation.');
        } catch (LogicException) {
            $this->assertFalse($this->facts()['return_requested']);
        }
        $this->expectException(LogicException::class);
        $this->provider->shipments()
            ->register($other);
    }

    public function testForeignClientRefusesBeforeCreatingAnyShipmentAdmission(): void
    {
        $foreign = new Journal($this->db, 'other-client');
        try {
            $foreign->shipments()
                ->register($this->descriptor('foreign-return'));
            $this->fail('A different client cannot inspect or register this shipment.');
        } catch (LogicException) {
            $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM provider_shipments')->fetchColumn());
        }
    }

    public function testCachedConfirmationCannotReconstructALostPhysicalAdmission(): void
    {
        $this->confirm($this->shipment->booking);
        $this->db->exec('DELETE FROM provider_shipment_admissions');
        try {
            $this->provider->lookup($this->shipment->booking);
            $this->fail('A cached effect is not a replacement for its original admission.');
        } catch (LogicException) {
            $this->assertSame(
                0,
                (int) $this->db->query('SELECT COUNT(*) FROM provider_shipment_admissions')
                    ->fetchColumn()
            );
        }
    }

    public function testRegisteringTheSameGraphCannotReopenHandoverAfterCancellation(): void
    {
        $this->confirm($this->shipment->booking);
        $this->confirm($this->shipment->cancel);
        $this->provider->shipments()
            ->register(ShipmentDescriptor::fromArray($this->shipment->toArray()));
        $this->expectException(LogicException::class);
        $this->fact('handed-over');
    }

    public function testInspectionWithoutReceiptAndDeliveryWithoutHandoverRefuse(): void
    {
        $this->confirm($this->shipment->booking);
        foreach (['delivered', 'inspected'] as $kind) {
            try {
                $this->fact($kind, 3, $kind === 'inspected' ? 3 : 0);
                $this->fail('A later physical milestone cannot invent its predecessor.');
            } catch (LogicException) {
                $this->assertSame([], $this->provider->shipments()->inspect($this->shipment)->events);
            }
        }
    }

    public function testRetainedCancellationBeforeHandoverConfirmsOnlyTheOriginalBookingCancellation(): void
    {
        $this->provider->plan($this->shipment->booking, Status::Confirmed);
        $this->provider->plan($this->shipment->cancel, Status::Confirmed);
        $operation = $this->operation();
        $this->assertTrue($operation->execute($this->context())->observed);
        $this->assertTrue($operation->compensate($this->context())->verified);
        $this->assertSame('verified', $this->operation()->compensate($this->context())->outcome);
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM provider_effects')->fetchColumn());
        $this->assertFalse($this->facts()['return_requested']);
        $this->assertSame(0, $this->facts()['restocked_quantity']);
    }

    public function testDeductionCannotBeReversedByBookingCancellationWhenHandoverEvidenceIsMissing(): void
    {
        $id = static fn (string $operation): Identity => new Identity(
            'shop-a',
            'purchase-1',
            'g1',
            'accounted.' . $operation
        );
        $terms = $this->shipment->booking->terms;
        $booking = $id('book');
        $return = $id('return');
        $this->shipment = new ShipmentDescriptor(
            new Request($booking, 'ship-book', $terms),
            new Request($id('cancel'), 'ship-cancel-booking', $terms, $booking),
            new Request($return, 'request-return', $terms, $booking),
            new Request($id('restock'), 'restock', $terms, $return),
            new Request($id('deduct'), 'deduct-stock', $terms, $booking)
        );
        $this->provider->shipments()
            ->register($this->shipment);
        $this->confirm($this->shipment->booking);
        $this->fact('handed-over');
        $this->confirm($this->shipment->deduction);
        $this->db->exec("DELETE FROM provider_shipment_events WHERE kind = 'handed-over'");
        $this->provider->plan($this->shipment->cancel, Status::Confirmed);
        $result = $this->operation()
            ->compensate($this->context());
        $this->assertFalse($result->verified);
        $this->assertSame('shipment-deduction-without-handover-evidence', $result->reason);
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM provider_effects')->fetchColumn());
    }

    public function testRetainedDeliveredShipmentWaitsForPhysicalAcceptanceAndConfirmedRestocking(): void
    {
        $this->delivered();
        $this->provider->plan($this->shipment->return, Status::Confirmed);
        $this->provider->plan($this->shipment->restock, Status::Confirmed);
        $operation = $this->operation();
        $this->assertFalse($operation->compensate($this->context())->verified);
        $this->fact('received');
        $this->assertFalse($operation->compensate($this->context())->verified);
        $this->fact('inspected', 3, 3, 3);
        $result = $this->operation()
            ->compensate($this->context());
        $this->assertTrue($result->verified);
        $this->assertSame('verified', $result->outcome);
        $this->assertContains('original-receipt:event-received', $result->evidence);
        $this->assertContains('original-inspection:event-inspected', $result->evidence);
        $this->assertTrue($this->facts()['original_recipient_delivered']);
        $this->assertSame(3, $this->facts()['restocked_quantity']);
        $this->assertSame(Status::Unknown, $this->provider->lookup($this->shipment->cancel)->status);
    }

    public function testLostRestockAcknowledgementKeepsOneEffectOnRetainedRecovery(): void
    {
        $this->delivered();
        $this->provider->plan($this->shipment->return, Status::Confirmed);
        $this->assertFalse($this->operation()->compensate($this->context())->verified);
        $this->fact('received');
        $this->fact('inspected', 3, 3, 3);
        $this->provider->plan($this->shipment->restock, Status::Confirmed, true);
        try {
            $this->operation()
                ->compensate($this->context());
            $this->fail('An unacknowledged restock must not be reported as settled.');
        } catch (LostAcknowledgement) {
            $this->assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM provider_effects')->fetchColumn());
        }
        $this->assertTrue($this->operation()->compensate($this->context())->verified);
        $this->assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM provider_effects')->fetchColumn());
    }

    public function testUnknownBookingCannotSettleUntilItsOriginalLateOutcomeConfirms(): void
    {
        $this->provider->plan($this->shipment->booking, Status::Unknown);
        $this->assertFalse($this->operation()->execute($this->context())->observed);
        $result = $this->operation()
            ->compensate($this->context());
        $this->assertFalse($result->verified);
        $this->assertNotSame('verified', $result->outcome);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM provider_effects')->fetchColumn());
        $this->provider->resolve($this->shipment->booking, Status::Confirmed);
        $this->provider->plan($this->shipment->cancel, Status::Confirmed);
        $this->assertTrue($this->operation()->compensate($this->context())->verified);
    }

    public function testARevokedHostGrantRefusesBeforeAnyProviderCommandOrInspection(): void
    {
        $operation = new ShipmentOperation($this->provider, static function (): void {
            throw new LogicException('Original shipment grant revoked.');
        });
        foreach (['execute', 'compensate'] as $method) {
            try {
                $operation->{$method}($this->context());
                $this->fail('Revoked host authority cannot access the provider.');
            } catch (LogicException $error) {
                $this->assertSame('Original shipment grant revoked.', $error->getMessage());
            }
        }
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM provider_operations')->fetchColumn());
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM provider_requests')->fetchColumn());
    }

    private function operation(): ShipmentOperation
    {
        $original = $this->context();
        return new ShipmentOperation($this->provider, static function (OperationContext $actual, string $mode) use (
            $original
        ): void {
            if ($actual->scope->toArray() !== $original->scope->toArray() || $actual->originalRunId !== $original->originalRunId
                || $actual->operationId !== $original->operationId || $actual->obligation->toArray() !== $original->obligation->toArray()
                || ! in_array($mode, ['forward', 'compensate'], true)) {
                throw new LogicException('The original shipment scope, native owner or descriptor changed.');
            }
        });
    }

    private function context(): OperationContext
    {
        return new OperationContext(
            new ScopeIdentity('shop-a', 'provider-example', 'purchase-1.shipment', 'g1'),
            new Obligation('shipment.fulfillment', 'example.physical-shipment', '1', $this->shipment->toArray()),
            'original-shipment-operation',
            'original-native-shipment',
            'original-recorded-activity',
            'attempt-1',
            false
        );
    }

    private function descriptor(string $returnId = 'return'): ShipmentDescriptor
    {
        $identity = static fn (string $operation): Identity => new Identity(
            'shop-a',
            'purchase-1',
            'g1',
            'shipment.' . $operation
        );
        $terms = [
            'sku' => 'sku-1',
            'quantity' => 3,
            'carrier' => 'synthetic-carrier',
        ];
        $booking = $identity('full-book');
        $return = $identity($returnId);
        return new ShipmentDescriptor(
            new Request($booking, 'ship-book', $terms),
            new Request($identity('full-cancel'), 'ship-cancel-booking', $terms, $booking),
            new Request($return, 'request-return', $terms, $booking),
            new Request($identity('restock'), 'restock', $terms, $return)
        );
    }

    private function confirm(Request $request): void
    {
        $this->provider->plan($request, Status::Confirmed);
        $this->assertSame(Status::Confirmed, $this->provider->attempt($request)->status);
    }

    private function fact(string $kind, int $quantity = 3, int $accepted = 0, int $saleable = 0): void
    {
        $this->provider->shipments()
            ->record($this->shipment, 'event-' . $kind, $kind, $quantity, $accepted, $saleable);
    }

    private function delivered(): void
    {
        $this->confirm($this->shipment->booking);
        $this->fact('handed-over');
        $this->fact('delivered');
    }

    private function facts(): array
    {
        return $this->provider->shipments()
            ->inspect($this->shipment)
            ->toArray()['facts'];
    }
}
