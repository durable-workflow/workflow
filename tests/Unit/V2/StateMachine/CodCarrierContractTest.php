<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

/** Authoritative synthetic carrier ordering only; native workflow acceptance remains separate. */
final class CodCarrierContractTest extends TestCase
{
    private PDO $db;

    private CodPaymentProvider $provider;

    private CodDescriptor $original;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->provider = new CodPaymentProvider($this->db, 'shop-a', static fn (): int => 1000, true);
        $this->provider->commands->install();
        $this->provider->commands->shipments()
            ->install();
        $this->provider->install();
    }

    public function testCollectionBeforeHandoverRequiresFullOriginalPayerMoneyBeforeDelivery(): void
    {
        $this->start(CodDescriptor::BEFORE);
        $first = $this->collect('payer-first', 500);
        try {
            $this->physical('delivered');
            $this->fail('A partial payer collection cannot permit recipient handover.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('complete on-time payer collection', $error->getMessage());
        }
        $this->assertArrayNotHasKey(
            'delivered',
            $this->provider->commands->shipments()
                ->inspect($this->original->shipment)
->events
        );
        $this->collect('payer-balance', 1000);
        $this->provider->collections->record($this->original, $first);
        $this->physical('delivered');
        $view = $this->provider->inspect($this->original);
        $this->assertTrue($view['facts']['payer_collection_confirmed']);
        $this->assertFalse($view['facts']['merchant_receipt_confirmed']);
        $this->assertCount(2, $view['carrierContract']['collections']);
        $this->assertSame(CodDescriptor::BEFORE, $view['carrierContract']['handoverPolicy']);
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM cod_carrier_collections')->fetchColumn());
    }

    public function testCollectionAfterHandoverCannotPrecedeTheOriginalRecipientDelivery(): void
    {
        $this->start(CodDescriptor::AFTER);
        try {
            $this->collect('payer-original', 1500);
            $this->fail('The original after-handover contract must refuse early collection.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('pinned recipient-handover order', $error->getMessage());
        }
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM cod_payment_receipts')->fetchColumn());
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM cod_carrier_collections')->fetchColumn());
        $this->physical('delivered');
        $this->collect('payer-original', 1500);
        $view = $this->provider->inspect($this->original);
        $this->assertTrue($view['facts']['payer_collection_confirmed']);
        $this->assertSame(CodDescriptor::AFTER, $view['carrierContract']['collections'][0]['phase']);
    }

    public function testOriginalCarrierRefusesCollectionBeforeTheParcelLeavesTheWarehouse(): void
    {
        $this->start(CodDescriptor::BEFORE, false);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('pinned recipient-handover order');
        $this->collect('payer-original', 1500);
    }

    public function testUnqualifiedReceiptCannotBypassTheRecordedPhysicalOrderProof(): void
    {
        $this->start(CodDescriptor::BEFORE);
        $weak = new CodJournal($this->db, 'shop-a', static fn (): int => 1000);
        $weak->record($this->original, $this->receipt('payer-without-physical-admission', 1500));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('carrier collection inventory lost');
        $this->provider->inspect($this->original);
    }

    public function testReplacementCannotRecreateALostOriginalPhysicalBinding(): void
    {
        $this->start(CodDescriptor::BEFORE);
        $this->db->exec('DELETE FROM cod_carrier_contracts');
        $replacement = new CodPaymentProvider($this->db, 'shop-a', static fn (): int => 1000, true);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('physical COD binding is missing');
        $replacement->collections->register($this->original, CodJournal::CLOSABLE);
    }

    public static function damagedAdmissions(): array
    {
        return [['missing-phase'], ['foreign-phase-owner'], ['changed-phase'], ['missing-money']];
    }

    #[DataProvider('damagedAdmissions')]
    public function testOriginalPhysicalCollectionEvidenceCannotDisappearOrChangeOwner(string $case): void
    {
        $this->start(CodDescriptor::BEFORE);
        $this->collect('payer-original', 1500);
        if ($case === 'missing-phase') {
            $this->db->exec('DELETE FROM cod_carrier_collections');
        } elseif ($case === 'foreign-phase-owner') {
            $this->db->exec("UPDATE cod_carrier_collections SET payment_id = 'foreign-original'");
        } elseif ($case === 'changed-phase') {
            $this->db->exec("UPDATE cod_carrier_collections SET phase = 'changed'");
        } else {
            $this->db->exec('DELETE FROM cod_payment_receipts');
            $this->db->exec('DELETE FROM cod_receipt_anchors');
        }
        $this->expectException(LogicException::class);
        $this->provider->inspect($this->original);
    }

    public function testObservationProviderCannotBeUpgradedToPhysicalSupportAfterReferenceRegistration(): void
    {
        $plainDb = new PDO('sqlite::memory:');
        $plain = new CodPaymentProvider($plainDb, 'shop-a');
        $plain->commands->install();
        $plain->install();
        $this->start(CodDescriptor::BEFORE);
        $plain->collections->register($this->original);
        $strong = new CodPaymentProvider($plainDb, 'shop-a', static fn (): int => 1000, true);
        $plainDb->beginTransaction();
        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('cannot be installed over existing');
            $strong->carrier->install();
        } finally {
            $plainDb->rollBack();
        }
    }

    private function start(string $policy, bool $handover = true): void
    {
        $terms = new OrderTerms('shop-a', 'purchase-cod', 'g1', 1500, 'USD', 'sku-1', 2);
        $this->original = new CodDescriptor(
            new Identity('shop-a', 'purchase-cod', 'g1', 'cod-original'),
            ShipmentDescriptor::fromArray($terms->physicalDescriptor()),
            'carrier-original',
            'depot-original',
            1500,
            'USD',
            1100,
            2000,
            $policy
        );
        $this->provider->collections->register($this->original, CodJournal::CLOSABLE);
        $this->provider->commands->shipments()
            ->register($this->original->shipment);
        $this->confirm($this->original->shipment->booking);
        if ($handover) {
            $this->physical('handed-over');
            $this->confirm($this->original->shipment->deduction);
        }
    }

    private function physical(string $kind): void
    {
        $this->provider->commands->shipments()
            ->record($this->original->shipment, 'original-' . $kind, $kind, 2);
    }

    private function confirm(Request $request): void
    {
        $this->provider->commands->plan($request, Status::Confirmed);
        $this->assertSame(Status::Confirmed, $this->provider->commands->attempt($request)->status);
    }

    private function collect(string $id, int $amount): CodReceipt
    {
        $receipt = $this->receipt($id, $amount);
        $this->provider->collections->record($this->original, $receipt);
        return $receipt;
    }

    private function receipt(string $id, int $amount): CodReceipt
    {
        return new CodReceipt(
            $this->original->identity->key(),
            $id,
            CodReceipt::COLLECTION,
            $amount,
            'USD',
            1000,
            'depot-original'
        );
    }
}
