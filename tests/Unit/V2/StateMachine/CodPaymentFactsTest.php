<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodSnapshot;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

/** Pure original-fact controls; provider journals, native integration and carrier effects remain separate. */
final class CodPaymentFactsTest extends TestCase
{
    public function testCollectionIsNotMerchantReceiptAndPartialRemittanceKeepsTheBalanceVisible(): void
    {
        $original = $this->original();
        $collection = $this->receipt(CodReceipt::COLLECTION, 'payer-collection', 1500, 'depot-original', 900);
        $collected = (new CodSnapshot($original, 1000, [$collection]))->facts();
        $this->assertTrue($collected['payer_collection_confirmed']);
        $this->assertFalse($collected['merchant_receipt_confirmed']);
        $this->assertSame(1500, $collected['outstanding_remittance_minor']);
        $partial = $this->receipt(CodReceipt::REMITTANCE, 'merchant-first-portion', 400, 'payer-collection', 950);
        $facts = (new CodSnapshot($original, 1000, [$partial, $collection]))->facts();
        $this->assertSame(1500, $facts['collected_amount_minor']);
        $this->assertSame(400, $facts['merchant_received_amount_minor']);
        $this->assertSame(1100, $facts['outstanding_remittance_minor']);
        $this->assertFalse($facts['merchant_receipt_confirmed']);
        $balance = $this->receipt(CodReceipt::REMITTANCE, 'merchant-original-balance', 1100, 'payer-collection', 960);
        $paid = (new CodSnapshot($original, 1000, [$collection, $balance, $partial]))->facts();
        $this->assertTrue($paid['merchant_receipt_confirmed']);
        $this->assertSame(0, $paid['outstanding_remittance_minor']);
    }

    public function testRemittingThePartialPayerAmountDoesNotPayTheWholeOriginalPurchase(): void
    {
        $receipts = [$this->receipt(CodReceipt::COLLECTION, 'partial-payer', 500, 'depot-original'),
            $this->receipt(CodReceipt::REMITTANCE, 'partial-merchant', 500, 'partial-payer')];
        $facts = (new CodSnapshot($this->original(), 1000, $receipts))->facts();
        $this->assertFalse($facts['payer_collection_confirmed']);
        $this->assertFalse($facts['merchant_receipt_confirmed']);
        $this->assertSame(1000, $facts['collection_remaining_minor']);
        $this->assertSame(0, $facts['outstanding_remittance_minor']);
    }

    public function testExpiryAndEmptyInventoryDoNotProveNonexecutionOrRestartEitherDeadline(): void
    {
        $original = $this->original();
        $snapshot = new CodSnapshot($original, 4000, []);
        $this->assertTrue($snapshot->facts()['collection_deadline_passed']);
        $this->assertTrue($snapshot->facts()['remittance_deadline_passed']);
        $this->assertFalse($snapshot->facts()['payer_collection_confirmed']);
        $this->assertFalse($snapshot->facts()['merchant_receipt_confirmed']);
        $this->assertFalse($snapshot->facts()['non_execution_established']);
        $this->assertSame(
            [1100, 2000],
            [$snapshot->descriptor->collectionDeadline, $snapshot->descriptor->remittanceDeadline]
        );
        $late = [$this->receipt(CodReceipt::COLLECTION, 'late-payer', 1500, 'depot-original', 3000),
            $this->receipt(CodReceipt::REMITTANCE, 'late-merchant', 1500, 'late-payer', 3500)];
        $this->assertTrue((new CodSnapshot($original, 4000, $late))->facts()['merchant_receipt_confirmed']);
    }

    public function testBothCarrierContractsKeepTheSameOriginalShipmentAndDistinctFingerprints(): void
    {
        $before = $this->original()
            ->toArray();
        $after = $before;
        $after['handoverPolicy'] = CodDescriptor::AFTER;
        $this->assertSame($before, CodDescriptor::fromArray($before)->toArray());
        $this->assertSame($before['shipment'], CodDescriptor::fromArray($after)->toArray()['shipment']);
        $this->assertNotSame(
            CodDescriptor::fromArray($before)->fingerprint(),
            CodDescriptor::fromArray($after)->fingerprint()
        );
        $receipt = $this->receipt(CodReceipt::COLLECTION, 'original', 1500, 'depot-original');
        $this->assertSame($receipt->toArray(), CodReceipt::fromArray($receipt->toArray())->toArray());
    }

    public static function invalidInventories(): array
    {
        return [['foreign-payment'], ['wrong-point'], ['foreign-currency'], ['future'], ['unknown-collection'],
            ['before-collection'], ['duplicate-collection'], ['duplicate-remittance'], ['excess-remittance'],
            ['integer-overflow'], ['not-a-receipt'], ['too-many-receipts']];
    }

    #[DataProvider('invalidInventories')]
    public function testOriginalMoneyOrInventoryCannotBeSubstituted(string $case): void
    {
        $collection = $this->receipt(CodReceipt::COLLECTION, 'payer', 1500, 'depot-original', 900);
        $remittance = $this->receipt(CodReceipt::REMITTANCE, 'merchant', 1500, 'payer', 950);
        $receipts = match ($case) {
            'foreign-payment' => [
                new CodReceipt('foreign-original', 'payer', CodReceipt::COLLECTION, 1500, 'USD', 900, 'depot-original'),
            ],
            'wrong-point' => [$this->receipt(CodReceipt::COLLECTION, 'payer', 1500, 'foreign-depot')],
            'foreign-currency' => [
                new CodReceipt(
                    $this->original()
                        ->identity->key(),
                    'payer',
                    CodReceipt::COLLECTION,
                    1500,
                    'EUR',
                    900,
                    'depot-original'
                ),
            ],
            'future' => [$this->receipt(CodReceipt::COLLECTION, 'payer', 1500, 'depot-original', 1001)],
            'unknown-collection' => [$remittance],
            'before-collection' => [
                $collection,
                $this->receipt(CodReceipt::REMITTANCE, 'merchant', 1500, 'payer', 800),
            ],
            'duplicate-collection' => [$collection, $collection],
            'duplicate-remittance' => [$collection, $remittance, $remittance],
            'excess-remittance' => [$collection, $this->receipt(CodReceipt::REMITTANCE, 'merchant', 1501, 'payer')],
            'integer-overflow' => [$this->receipt(CodReceipt::COLLECTION, 'first', PHP_INT_MAX, 'depot-original'),
                $this->receipt(CodReceipt::COLLECTION, 'second', 1, 'depot-original')],
            'not-a-receipt' => [[
                'payer' => true,
            ]],
            'too-many-receipts' => array_fill(0, 129, $collection),
        };
        $this->expectException(InvalidArgumentException::class);
        new CodSnapshot($this->original(), 1000, $receipts);
    }

    public static function malformedDescriptors(): array
    {
        return [['amountMinor', '1500'], ['currency', 'usd'], ['collectionDeadline', 0], ['remittanceDeadline', 1000],
            ['handoverPolicy', 'unselected-carrier-contract'], ['identity', null], ['unexpected', true]];
    }

    #[DataProvider('malformedDescriptors')]
    public function testUnselectedOrCoercedDescriptorFieldsAreRefused(string $key, mixed $value): void
    {
        $data = $this->original()
            ->toArray();
        $data[$key] = $value;
        $this->expectException(InvalidArgumentException::class);
        CodDescriptor::fromArray($data);
    }

    public function testAnotherPurchaseCannotProvideTheOriginalCarrierShipment(): void
    {
        $data = $this->original()
            ->toArray();
        $data['identity']['generation'] = 'foreign-business-generation';
        $this->expectException(InvalidArgumentException::class);
        CodDescriptor::fromArray($data);
    }

    private function original(): CodDescriptor
    {
        $terms = new OrderTerms('shop-a', 'cod-purchase', 'g1', 1500, 'USD', 'sku-1', 2);
        return new CodDescriptor(
            new Identity('shop-a', 'cod-purchase', 'g1', 'cod-payment'),
            ShipmentDescriptor::fromArray($terms->physicalDescriptor()),
            'original-carrier-reference',
            'depot-original',
            1500,
            'USD',
            1100,
            2000
        );
    }

    private function receipt(string $kind, string $id, int $amount, string $target, int $at = 900): CodReceipt
    {
        return new CodReceipt($this->original()->identity->key(), $id, $kind, $amount, 'USD', $at, $target);
    }
}
