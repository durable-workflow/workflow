<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\LostAcknowledgement;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\OperationContext;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

/** Direct retained-operation controls; native ownership and physical ordering have separate integration cases. */
final class RetainedCodPaymentOperationTest extends TestCase
{
    private PDO $db;

    private CodPaymentProvider $provider;

    private CodDescriptor $original;

    private int $now = 1000;

    private bool $allowed = true;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->provider = new CodPaymentProvider($this->db, 'shop-a', fn (): int => $this->now);
        $this->provider->commands->install();
        $this->provider->install();
        $base = new OrderTerms('shop-a', 'purchase-cod', 'g1', 1500, 'USD', 'sku-1', 2);
        $this->original = new CodDescriptor(
            new Identity('shop-a', 'purchase-cod', 'g1', 'original-cod'),
            ShipmentDescriptor::fromArray($base->physicalDescriptor()),
            'carrier-original',
            'point-original',
            1500,
            'USD',
            1100,
            2000
        );
    }

    public function testCancellationBeforeCollectionCreatesAnAuthoritativeFenceForBothOriginalDuties(): void
    {
        foreach (['payer', 'merchant'] as $stage) {
            $outcome = $this->handler($stage)
                ->compensate($this->context($stage));
            $this->assertTrue($outcome->verified);
        }
        $this->assertSame(0, $this->effects());
        $this->assertTrue($this->provider->collections->inspect($this->original)['collectionAdmissionClosed']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('rejects collection');
        $this->provider->collections->record($this->original, $this->receipt());
    }

    public static function pendingRefunds(): array
    {
        return [[Status::Unknown], [Status::Processing], [Status::Refused]];
    }

    #[DataProvider('pendingRefunds')]
    public function testUnknownProcessingAndRefusedRefundsDoNotSettleOriginalPayerOrMerchant(Status $status): void
    {
        $this->receive();
        $context = $this->context('payer');
        $request = CodPaymentOperation::refundRequest(
            $context->scope,
            $context->obligation->key,
            $this->original,
            $this->receipt()
        );
        $this->provider->commands->plan($request, $status);
        $payer = $this->handler('payer')
            ->compensate($context);
        $this->assertFalse($payer->verified);
        $this->assertSame('cod-customer-refund-' . $status->value, $payer->reason);
        $merchant = $this->handler('merchant')
            ->compensate($this->context('merchant'));
        $this->assertFalse($merchant->verified);
        $this->assertSame('cod-original-merchant-settlement-open', $merchant->reason);
        $this->assertSame(0, $this->effects());
    }

    public function testLostRefundAcknowledgementRecoversOriginalEffectAndPreservesMerchantDebt(): void
    {
        $this->receive();
        $context = $this->context('payer');
        $request = CodPaymentOperation::refundRequest(
            $context->scope,
            $context->obligation->key,
            $this->original,
            $this->receipt()
        );
        $this->provider->commands->plan($request, Status::Confirmed, true);
        try {
            $this->handler('payer')
                ->compensate($context);
            $this->fail('The original refund must commit before its acknowledgement is lost.');
        } catch (LostAcknowledgement) {
            $this->assertSame(1, $this->effects());
        }
        $this->now = 3000;
        $replacement = new CodPaymentProvider($this->db, 'shop-a', fn (): int => $this->now);
        $this->provider = $replacement;
        $outcome = $this->handler('payer')
            ->compensate($this->context('payer', activity: 'replacement-activity'));
        $this->assertTrue($outcome->verified);
        $this->assertSame(1, $this->effects());
        $this->assertFalse($this->handler('merchant')->compensate($this->context('merchant'))->verified);
        $facts = $this->provider->collections->inspect($this->original);
        $this->assertSame(1500, $facts['facts']['collected_amount_minor']);
        $this->assertSame(1500, $facts['facts']['outstanding_remittance_minor']);
        $this->assertSame(1100, $facts['original']['collectionDeadline']);
        $this->assertSame(2000, $facts['original']['remittanceDeadline']);
    }

    public function testRefundingKnownMoneyCannotSettleAnOpenCollectionContract(): void
    {
        $this->receive(CodJournal::OPEN);
        $context = $this->context('payer', CodJournal::OPEN);
        $request = CodPaymentOperation::refundRequest(
            $context->scope,
            $context->obligation->key,
            $this->original,
            $this->receipt()
        );
        $this->provider->commands->plan($request, Status::Confirmed);
        $payer = $this->handler('payer')
            ->compensate($context);
        $this->assertFalse($payer->verified);
        $this->assertSame('cod-original-collection-admission-open', $payer->reason);
        $this->assertSame(1, $this->effects());
        $later = new CodReceipt(
            $this->original->identity->key(),
            'second-original-collection',
            CodReceipt::COLLECTION,
            1,
            'USD',
            $this->now,
            $this->original->collectionPoint
        );
        $this->provider->collections->record($this->original, $later);
        $this->assertSame(
            1501,
            $this->provider->collections->inspect($this->original)['facts']['collected_amount_minor']
        );
    }

    public static function collectedAmounts(): array
    {
        return [
            'partial-original-collection' => [500],
            'full-original-collection' => [1500],
        ];
    }

    #[DataProvider('collectedAmounts')]
    public function testClosedMerchantDutyRequiresEveryOriginalCollectedUnitToBeRemitted(int $amount): void
    {
        $this->handler('payer')
            ->execute($this->context('payer'));
        $receipt = new CodReceipt(
            $this->original->identity->key(),
            'original-part-to-settle',
            CodReceipt::COLLECTION,
            $amount,
            'USD',
            $this->now,
            $this->original->collectionPoint
        );
        $this->provider->collections->record($this->original, $receipt);
        $this->assertFalse($this->handler('merchant')->compensate($this->context('merchant'))->verified);
        $this->provider->collections->record($this->original, new CodReceipt(
            $receipt->payment,
            'original-partial-remittance',
            CodReceipt::REMITTANCE,
            $amount - 1,
            'USD',
            $this->now,
            $receipt->receipt
        ));
        $this->assertFalse($this->handler('merchant')->compensate($this->context('merchant'))->verified);
        $this->assertSame(
            1,
            $this->provider->collections->inspect($this->original)['facts']['outstanding_remittance_minor']
        );
        $this->now = 3000;
        $this->provider->collections->record($this->original, new CodReceipt(
            $receipt->payment,
            'original-final-remittance',
            CodReceipt::REMITTANCE,
            1,
            'USD',
            $this->now,
            $receipt->receipt
        ));
        $result = $this->handler('merchant')
            ->compensate($this->context('merchant', activity: 'original-debt-paid'));
        $this->assertTrue($result->verified);
        $this->assertSame(0, $this->effects());
        $snapshot = $this->provider->collections->inspect($this->original);
        $this->assertTrue($snapshot['collectionAdmissionClosed']);
        $this->assertSame($amount, $snapshot['facts']['collected_amount_minor']);
        $this->assertSame(0, $snapshot['facts']['outstanding_remittance_minor']);
        $this->assertSame(2000, $snapshot['original']['remittanceDeadline']);
    }

    public function testPaidKnownMerchantReceiptsCannotSettleAnOpenCollectionContract(): void
    {
        $this->receive(CodJournal::OPEN);
        $receipt = $this->receipt();
        $this->provider->collections->record($this->original, new CodReceipt(
            $receipt->payment,
            'known-merchant-payment',
            CodReceipt::REMITTANCE,
            $receipt->amountMinor,
            'USD',
            $this->now,
            $receipt->receipt
        ));
        $result = $this->handler('merchant')
            ->compensate($this->context('merchant', CodJournal::OPEN));
        $this->assertFalse($result->verified);
        $this->assertSame('cod-original-merchant-settlement-open', $result->reason);
        $this->assertFalse($this->provider->collections->inspect($this->original)['collectionAdmissionClosed']);
    }

    public function testDeniedHostAuthorityCannotCreateReferenceOrCloseAdmission(): void
    {
        $this->allowed = false;
        foreach (['payer', 'merchant'] as $stage) {
            foreach (['execute', 'compensate'] as $operation) {
                try {
                    $this->handler($stage)
                        ->{$operation}($this->context($stage));
                    $this->fail('Unauthorized original COD operation must refuse before provider SQL.');
                } catch (LogicException $error) {
                    $this->assertSame('Original COD host authority refused.', $error->getMessage());
                }
            }
        }
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM cod_payment_references')->fetchColumn());
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM cod_collection_closures')->fetchColumn());
        $this->assertSame(0, $this->effects());
    }

    private function handler(string $stage): CodPaymentOperation
    {
        return new CodPaymentOperation($this->provider, function (
            OperationContext $context,
            string $mode,
            string $role
        ): void {
            if (! $this->allowed || $context->originalRunId !== 'original-run' || ! in_array(
                $mode,
                ['forward', 'compensate'],
                true
            )
                || $context->obligation->key !== 'payment.cod.' . $role) {
                throw new LogicException('Original COD host authority refused.');
            }
        }, $stage);
    }

    private function context(
        string $stage,
        string $contract = CodJournal::CLOSABLE,
        string $activity = 'original-activity'
    ): OperationContext {
        return new OperationContext(
            new ScopeIdentity('shop-a', 'cod', 'purchase-cod.payment', 'g1'),
            new Obligation('payment.cod.' . $stage, 'provider.cod.' . $stage, '1', [
                'original' => $this->original->toArray(),
                'admissionContract' => $contract,
            ]),
            'original-operation-' . $stage,
            'original-run',
            $activity,
            $activity . '-attempt',
            false
        );
    }

    private function receive(string $contract = CodJournal::CLOSABLE): void
    {
        $this->handler('payer')
            ->execute($this->context('payer', $contract));
        $this->provider->collections->record($this->original, $this->receipt());
    }

    private function receipt(): CodReceipt
    {
        return new CodReceipt(
            $this->original->identity->key(),
            'payer-original',
            CodReceipt::COLLECTION,
            1500,
            'USD',
            1000,
            $this->original->collectionPoint
        );
    }

    private function effects(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM provider_effects')
            ->fetchColumn();
    }
}
