<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

/** Provider facts and explicit synthetic collection fencing; no native cancellation/refund acceptance. */
final class CodJournalTest extends TestCase
{
    private PDO $db;

    private CodJournal $journal;

    private CodDescriptor $original;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->journal = new CodJournal($this->db, 'shop-a', static fn (): int => 1000);
        $this->journal->install();
        $terms = new OrderTerms('shop-a', 'purchase-cod', 'g1', 1500, 'USD', 'sku-1', 2);
        $this->original = new CodDescriptor(
            new Identity('shop-a', 'purchase-cod', 'g1', 'cod-original'),
            ShipmentDescriptor::fromArray($terms->physicalDescriptor()),
            'carrier-original',
            'depot-original',
            1500,
            'USD',
            1100,
            2000
        );
        $this->journal->register($this->original);
    }

    public function testOriginalReceiptsSurviveReplacementWithoutManufacturingMerchantMoney(): void
    {
        $payer = $this->receipt(CodReceipt::COLLECTION, 'original-payer', 1500, 'depot-original');
        $this->journal->record($this->original, $payer);
        $this->journal->record($this->original, $payer);
        $this->assertFalse($this->journal->inspect($this->original)['facts']['merchant_receipt_confirmed']);
        $first = $this->receipt(CodReceipt::REMITTANCE, 'merchant-first', 400, 'original-payer');
        $this->journal->record($this->original, $first);
        $this->journal = new CodJournal($this->db, 'shop-a', static fn (): int => 1000);
        $this->journal->register($this->original);
        $this->assertSame(1100, $this->journal->inspect($this->original)['facts']['outstanding_remittance_minor']);
        $this->journal->record(
            $this->original,
            $this->receipt(CodReceipt::REMITTANCE, 'merchant-balance', 1100, 'original-payer')
        );
        $this->assertTrue($this->journal->inspect($this->original)['facts']['merchant_receipt_confirmed']);
        $this->assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM cod_payment_receipts')->fetchColumn());
    }

    public function testForeignCredentialsAreRejectedBeforeAnyProviderSql(): void
    {
        $foreign = new CodJournal(new PDO('sqlite::memory:'), 'foreign-client');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The carrier provider credential belongs to another client.');
        $foreign->inspect($this->original);
    }

    public function testOriginalReferenceCannotBeReboundToAnotherPolicy(): void
    {
        $value = $this->original->toArray();
        $value['handoverPolicy'] = CodDescriptor::AFTER;
        $this->expectException(LogicException::class);
        $this->journal->register(CodDescriptor::fromArray($value));
    }

    public function testChangedDuplicateDoesNotReplaceTheOriginalReceipt(): void
    {
        $this->journal->record(
            $this->original,
            $this->receipt(CodReceipt::COLLECTION, 'same-id', 1000, 'depot-original')
        );
        try {
            $this->journal->record(
                $this->original,
                $this->receipt(CodReceipt::COLLECTION, 'same-id', 1500, 'depot-original')
            );
            $this->fail('A changed original receipt must not replace its first facts.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('changed content', $error->getMessage());
        }
        $this->assertSame(1000, $this->journal->inspect($this->original)['facts']['collected_amount_minor']);
    }

    public static function damagedFacts(): array
    {
        return [['delete-receipt'], ['delete-anchor'], ['rehashed-payload'], ['rehashed-owner-index']];
    }

    #[DataProvider('damagedFacts')]
    public function testAnAnchoredReceiptCannotDisappearOrChangeOwner(string $case): void
    {
        $receipt = $this->receipt(CodReceipt::COLLECTION, 'original-payer', 1500, 'depot-original');
        $this->journal->record($this->original, $receipt);
        if ($case === 'delete-receipt') {
            $this->db->exec('DELETE FROM cod_payment_receipts');
        } elseif ($case === 'delete-anchor') {
            $this->db->exec('DELETE FROM cod_receipt_anchors');
        } elseif ($case === 'rehashed-owner-index') {
            $this->db->exec("UPDATE cod_payment_receipts SET payment_id = 'another-original-payment'");
        } else {
            $payload = $receipt->toArray();
            $payload['amountMinor'] = 1;
            $encoded = \Workflow\V2\Compensation\Definition\PortableDescriptor::encode($payload);
            $this->db->prepare('UPDATE cod_payment_receipts SET payload = ?, payload_sha256 = ?')
                ->execute([$encoded, hash('sha256', $encoded)]);
        }
        $this->expectException(LogicException::class);
        $this->journal->inspect($this->original);
    }

    public function testRemittanceCannotInventOrExceedTheOriginalCollection(): void
    {
        $this->journal->record(
            $this->original,
            $this->receipt(CodReceipt::COLLECTION, 'partial-payer', 500, 'depot-original')
        );
        $this->expectException(InvalidArgumentException::class);
        $this->journal->record(
            $this->original,
            $this->receipt(CodReceipt::REMITTANCE, 'excess-merchant', 501, 'partial-payer')
        );
    }

    public function testCallerTransactionIsRefusedWithoutTakingItOver(): void
    {
        $this->db->beginTransaction();
        try {
            $this->journal->inspect($this->original);
            $this->fail('An application transaction must not own the provider boundary.');
        } catch (LogicException) {
            $this->assertTrue($this->db->inTransaction());
        } finally {
            $this->db->rollBack();
        }
    }

    public function testOverflowFactsAreRetainedWhileTheBoundedSnapshotRefusesQualification(): void
    {
        for ($n = 0; $n < 129; ++$n) {
            $this->journal->record(
                $this->original,
                $this->receipt(CodReceipt::COLLECTION, 'payer-' . $n, 1, 'depot-original')
            );
        }
        $this->assertSame(129, (int) $this->db->query('SELECT COUNT(*) FROM cod_payment_receipts')->fetchColumn());
        $this->expectException(InvalidArgumentException::class);
        $this->journal->inspect($this->original);
    }

    public function testNeverOpenedOriginalCanBeFencedAndCannotCollectAfterReplacement(): void
    {
        $this->selectClosableOriginal();
        $closed = $this->journal->closeAdmission($this->original, CodJournal::CLOSABLE);
        $this->assertTrue($closed['collectionAdmissionClosed']);
        $this->assertSame([], $closed['collections']);
        $this->journal = new CodJournal($this->db, 'shop-a', static fn (): int => 3000);
        $this->journal->register($this->original, CodJournal::CLOSABLE);
        $this->assertSame($closed, $this->journal->closeAdmission($this->original, CodJournal::CLOSABLE));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('rejects collection after its provider fence');
        $this->journal->record(
            $this->original,
            $this->receipt(CodReceipt::COLLECTION, 'late-payer', 1500, 'depot-original')
        );
    }

    public function testOriginalCollectionRepeatsButNewCollectionsCannotFollowTheFence(): void
    {
        $this->selectClosableOriginal();
        $this->journal->register($this->original, CodJournal::CLOSABLE);
        $receipt = $this->receipt(CodReceipt::COLLECTION, 'payer-original', 1500, 'depot-original');
        $this->journal->record($this->original, $receipt);
        $closed = $this->journal->closeAdmission($this->original, CodJournal::CLOSABLE);
        $this->assertSame([$receipt->toArray()], $closed['collections']);
        $this->assertSame($receipt->toArray(), $this->journal->record($this->original, $receipt));
        $this->expectException(LogicException::class);
        $this->journal->record(
            $this->original,
            $this->receipt(CodReceipt::COLLECTION, 'another-payer', 1, 'depot-original')
        );
    }

    public function testLateMerchantRemittanceRemainsVisibleAfterCollectionAdmissionCloses(): void
    {
        $this->selectClosableOriginal();
        $this->journal->register($this->original, CodJournal::CLOSABLE);
        $this->journal->record(
            $this->original,
            $this->receipt(CodReceipt::COLLECTION, 'payer-original', 1500, 'depot-original')
        );
        $closed = $this->journal->closeAdmission($this->original, CodJournal::CLOSABLE);
        $this->journal = new CodJournal($this->db, 'shop-a', static fn (): int => 2200);
        $before = $this->journal->inspect($this->original);
        $this->assertTrue($before['facts']['remittance_deadline_passed']);
        $this->assertFalse($before['facts']['merchant_receipt_confirmed']);
        $this->assertSame(1500, $before['facts']['outstanding_remittance_minor']);
        $this->journal->record($this->original, new CodReceipt(
            $this->original->identity->key(),
            'late-merchant-original',
            CodReceipt::REMITTANCE,
            1500,
            'USD',
            2100,
            'payer-original'
        ));
        $after = $this->journal->inspect($this->original);
        $this->assertTrue($after['facts']['merchant_receipt_confirmed']);
        $this->assertSame(0, $after['facts']['outstanding_remittance_minor']);
        $this->assertTrue($after['collectionAdmissionClosed']);
        $this->assertSame($closed, $this->journal->closeAdmission($this->original, CodJournal::CLOSABLE));
    }

    public function testExpiryCannotCloseAnOpenContractOrPermitContractReplacement(): void
    {
        $this->journal = new CodJournal($this->db, 'shop-a', static fn (): int => 3000);
        $view = $this->journal->inspect($this->original);
        $this->assertTrue($view['facts']['collection_deadline_passed']);
        $this->assertFalse($view['collectionAdmissionClosed']);
        $this->assertFalse($view['facts']['non_execution_established']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('original carrier collection contract cannot be replaced');
        $this->journal->closeAdmission($this->original, CodJournal::CLOSABLE);
    }

    public static function damagedClosures(): array
    {
        return [['missing-contract'], ['missing-fence'], ['changed-flag'], ['changed-descriptor']];
    }

    #[DataProvider('damagedClosures')]
    public function testDamagedOriginalFenceCannotBeRecreated(string $case): void
    {
        $this->selectClosableOriginal();
        $this->journal->closeAdmission($this->original, CodJournal::CLOSABLE);
        if ($case === 'missing-contract') {
            $this->db->exec('DELETE FROM cod_collection_contracts');
        } elseif ($case === 'missing-fence') {
            $this->db->exec('DELETE FROM cod_collection_closures');
        } elseif ($case === 'changed-flag') {
            $this->db->exec('UPDATE cod_collection_contracts SET closed = 0');
        } else {
            $this->db->exec("UPDATE cod_collection_closures SET descriptor_sha256 = 'changed'");
        }
        $this->expectException(LogicException::class);
        $this->journal->closeAdmission($this->original, CodJournal::CLOSABLE);
    }

    public function testClosedCollectionCannotDisappearWithItsAnchor(): void
    {
        $this->selectClosableOriginal();
        $this->journal->register($this->original, CodJournal::CLOSABLE);
        $this->journal->record(
            $this->original,
            $this->receipt(CodReceipt::COLLECTION, 'payer-original', 1500, 'depot-original')
        );
        $this->journal->closeAdmission($this->original, CodJournal::CLOSABLE);
        $this->db->exec('DELETE FROM cod_payment_receipts');
        $this->db->exec('DELETE FROM cod_receipt_anchors');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('fenced original carrier collection inventory is incomplete');
        $this->journal->inspect($this->original);
    }

    public function testMissingOriginalReferenceCannotAdoptSurvivingFencedFacts(): void
    {
        $this->selectClosableOriginal();
        $this->journal->closeAdmission($this->original, CodJournal::CLOSABLE);
        $delete = $this->db->prepare('DELETE FROM cod_payment_references WHERE id = ?');
        $delete->execute([$this->original->identity->key()]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot adopt surviving provider facts');
        $this->journal->closeAdmission($this->original, CodJournal::CLOSABLE);
    }

    public function testForeignClosureCannotAccessUninstalledProviderTables(): void
    {
        $foreign = new CodJournal(new PDO('sqlite::memory:'), 'foreign-client');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('provider credential belongs to another client');
        $foreign->closeAdmission($this->original, CodJournal::CLOSABLE);
    }

    public function testOpenContractCannotClaimSyntheticCollectionClosure(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot fence future payer collections');
        $this->journal->closeAdmission($this->original, CodJournal::OPEN);
    }

    private function selectClosableOriginal(): void
    {
        $value = $this->original->toArray();
        $value['identity']['operation'] = 'cod-fenced';
        $value['reference'] = 'carrier-fenced';
        $this->original = CodDescriptor::fromArray($value);
    }

    private function receipt(string $kind, string $id, int $amount, string $target): CodReceipt
    {
        return new CodReceipt($this->original->identity->key(), $id, $kind, $amount, 'USD', 900, $target);
    }
}
