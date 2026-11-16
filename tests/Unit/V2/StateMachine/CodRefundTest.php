<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodRefundTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\LostAcknowledgement;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

/** Carrier-origin refunds preserve payer collection identity; merchant remittance remains a separate fact. */
final class CodRefundTest extends TestCase
{
    private PDO $db;

    private CodPaymentProvider $provider;

    private CodDescriptor $original;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->provider = new CodPaymentProvider($this->db, 'shop-a', static fn (): int => 2000);
        $this->provider->commands->install();
        $this->provider->install();
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
        $this->provider->collections->register($this->original);
    }

    public function testLostAcknowledgementResumesOriginalRefundWithoutASecondEffect(): void
    {
        $this->collect();
        $refund = $this->refund('refund-original', 1000);
        $this->provider->commands->plan($refund, Status::Confirmed, true);
        try {
            $this->provider->commands->attempt($refund);
            $this->fail('The provider must commit the refund before losing its acknowledgement.');
        } catch (LostAcknowledgement) {
            $this->assertSame(1, $this->effects());
        }
        $replacement = new CodPaymentProvider($this->db, 'shop-a', static fn (): int => 3000);
        $observed = $replacement->commands->attempt($refund);
        $this->assertSame(Status::Confirmed, $observed->status);
        $this->assertNotNull($observed->effectId);
        $this->assertSame(1, $this->effects());
        $excess = $this->refund('refund-excess', 501);
        $replacement->commands->plan($excess, Status::Confirmed);
        $this->assertSame(Status::Refused, $replacement->commands->attempt($excess)->status);
        $remaining = $this->refund('refund-remaining', 500);
        $replacement->commands->plan($remaining, Status::Confirmed);
        $this->assertSame(Status::Confirmed, $replacement->commands->attempt($remaining)->status);
        $this->assertSame(2, $this->effects());
        $this->assertSame(1500, $replacement->collections->inspect($this->original)['facts']['collected_amount_minor']);
        $this->assertFalse($replacement->collections->inspect($this->original)['facts']['merchant_receipt_confirmed']);
    }

    public static function unresolved(): array
    {
        return [[Status::Unknown], [Status::Processing]];
    }

    #[DataProvider('unresolved')]
    public function testUnresolvedRefundReservesOriginalMoneyUntilDefinitiveCompletion(Status $status): void
    {
        $this->collect();
        $first = $this->refund('refund-pending', 1000);
        $this->provider->commands->plan($first, $status);
        $this->assertSame($status, $this->provider->commands->attempt($first)->status);
        $second = $this->refund('refund-overlap', 600);
        $this->provider->commands->plan($second, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->commands->attempt($second)->status);
        $this->assertSame(0, $this->effects());
        $this->provider->commands->resolve($first, Status::NotExecuted);
        $full = $this->refund('refund-after-nonexecution', 1500);
        $this->provider->commands->plan($full, Status::Confirmed);
        $this->assertSame(Status::Confirmed, $this->provider->commands->attempt($full)->status);
        $this->assertSame(1, $this->effects());
    }

    public function testMerchantRemittanceCannotSubstituteForPayerCollection(): void
    {
        $this->collect();
        $this->provider->collections->record($this->original, new CodReceipt(
            $this->original->identity->key(),
            'merchant-receipt',
            CodReceipt::REMITTANCE,
            1500,
            'USD',
            1500,
            'payer-original'
        ));
        $wrong = $this->refund('wrong-original', 1500, 'merchant-receipt');
        $this->provider->commands->plan($wrong, Status::Confirmed);
        $this->assertSame(Status::Unknown, $this->provider->commands->attempt($wrong)->status);
        $this->assertSame(0, $this->effects());
    }

    public function testMissingCollectionCannotLaterBecomeAnAdmittedRefundByLookup(): void
    {
        $refund = $this->refund('refund-before-payer', 1500);
        $this->provider->commands->plan($refund, Status::Confirmed);
        $this->assertSame(Status::Unknown, $this->provider->commands->attempt($refund)->status);
        $this->collect();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('lost its original money admission');
        $this->provider->commands->resolve($refund, Status::Confirmed);
    }

    public static function damagedEvidence(): array
    {
        return [['missing-anchor'], ['rehashed-payer'], ['missing-allocation'], ['changed-allocation']];
    }

    #[DataProvider('damagedEvidence')]
    public function testOriginalRefundCompletionRejectsLostOrSubstitutedEvidence(string $case): void
    {
        $this->collect();
        $refund = $this->refund('refund-pending', 1500);
        $this->provider->commands->plan($refund, Status::Processing);
        $this->provider->commands->attempt($refund);
        if ($case === 'missing-anchor') {
            $this->db->exec('DELETE FROM cod_receipt_anchors');
        } elseif ($case === 'missing-allocation') {
            $this->db->exec('DELETE FROM cod_refund_allocations');
        } elseif ($case === 'changed-allocation') {
            $this->db->exec('UPDATE cod_refund_allocations SET amount_minor = 1');
        } else {
            $row = $this->db->query('SELECT payload FROM cod_payment_receipts')
                ->fetchColumn();
            $value = json_decode($row, true, flags: JSON_THROW_ON_ERROR);
            $value['amountMinor'] = 3000;
            $payload = PortableDescriptor::encode($value);
            $this->db->prepare('UPDATE cod_payment_receipts SET payload = ?, payload_sha256 = ?')
                ->execute([$payload, hash('sha256', $payload)]);
        }
        $this->expectException(LogicException::class);
        $this->provider->commands->resolve($refund, Status::Confirmed);
    }

    public function testForeignProviderCredentialRefusesBeforeAccessingUninstalledTables(): void
    {
        $foreign = new CodPaymentProvider(new PDO('sqlite::memory:'), 'foreign-client');
        $refund = $this->refund('refund-foreign', 1500);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('provider credential belongs to another client');
        $foreign->commands->attempt($refund);
    }

    public static function invalidTerms(): array
    {
        return [['currency'], ['amount-type'], ['amount-negative'], ['unexpected']];
    }

    #[DataProvider('invalidTerms')]
    public function testOriginalRefundTermsCannotChangeCurrencyOrCoerceTheAmount(string $case): void
    {
        $value = (new CodRefundTerms($this->original, 'payer-original', 1500))->toArray();
        if ($case === 'currency') {
            $value['currency'] = 'EUR';
        } elseif ($case === 'amount-type') {
            $value['amountMinor'] = '1500';
        } elseif ($case === 'amount-negative') {
            $value['amountMinor'] = -1;
        } else {
            $value['replacement'] = true;
        }
        $this->expectException(InvalidArgumentException::class);
        CodRefundTerms::fromArray($value);
    }

    public function testCarrierCustomerRefundDoesNotEraseHistoricalMerchantRemittance(): void
    {
        $this->collect();
        $this->provider->collections->record($this->original, new CodReceipt(
            $this->original->identity->key(),
            'merchant-original',
            CodReceipt::REMITTANCE,
            500,
            'USD',
            1500,
            'payer-original'
        ));
        $refund = $this->refund('refund-original', 1500);
        $this->provider->commands->plan($refund, Status::Confirmed);
        $this->provider->commands->attempt($refund);
        $facts = $this->provider->collections->inspect($this->original)['facts'];
        $this->assertSame(500, $facts['merchant_received_amount_minor']);
        $this->assertSame(1000, $facts['outstanding_remittance_minor']);
        $this->assertSame(1, $this->effects());
    }

    private function collect(): void
    {
        $this->provider->collections->record($this->original, new CodReceipt(
            $this->original->identity->key(),
            'payer-original',
            CodReceipt::COLLECTION,
            1500,
            'USD',
            1000,
            'depot-original'
        ));
    }

    private function refund(string $operation, int $amount, string $collection = 'payer-original'): Request
    {
        return new Request(
            new Identity('shop-a', 'purchase-cod', 'g1', $operation),
            'refund-cod',
            (new CodRefundTerms($this->original, $collection, $amount))->toArray(),
            $this->original->identity
        );
    }

    private function effects(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM provider_effects')
            ->fetchColumn();
    }
}
