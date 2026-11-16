<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

require_once __DIR__ . '/../../../../examples/configured-state-machines/providers/bootstrap.php';

final class IncomingPaymentJournalTest extends TestCase
{
    private PDO $db;

    private IncomingPaymentJournal $provider;

    private int $now = 1000;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        (new Journal($this->db, 'shop-a'))->install();
        $this->provider = new IncomingPaymentJournal($this->db, 'shop-a', fn (): int => $this->now);
        $this->provider->install();
    }

    public function testAnEmptyExpiredReferenceProvesNeitherPaymentNorNonExecution(): void
    {
        $original = $this->descriptor();
        $this->provider->register($original);
        $this->now = 2000;
        $snapshot = $this->provider->inspect($original);
        $this->assertSame($original->toArray(), $snapshot['original']);
        $this->assertSame([], $snapshot['receipts']);
        $this->assertSame(0, $snapshot['receivedAmountMinor']);
        $this->assertSame(1499, $snapshot['remainingAmountMinor']);
        $this->assertFalse($snapshot['fullAmountReceived']);
        $this->assertFalse($snapshot['nonExecutionEstablished']);
        $this->assertTrue($snapshot['deadlinePassed']);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM provider_effects')->fetchColumn());
    }

    public function testALateOriginalPaymentSurvivesReplacementWithoutInventingAuthorizationOrCharge(): void
    {
        $original = $this->descriptor();
        $this->provider->register($original);
        $this->now = 2000;
        $receipt = $this->receipt('bank-transfer-1', 1499, 1500);
        $this->provider->record($original, $receipt);
        $replacement = new IncomingPaymentJournal($this->db, 'shop-a', fn (): int => $this->now);
        $replacement->register($original);
        $this->assertSame($receipt->toArray(), $replacement->record($original, $receipt));
        $snapshot = $replacement->inspect($original);
        $this->assertSame(1100, $snapshot['original']['deadline']);
        $this->assertSame([$receipt->toArray()], $snapshot['receipts']);
        $this->assertTrue($snapshot['fullAmountReceived']);
        $this->assertFalse($snapshot['requiredAmountReceivedBeforeDeadline']);
        $this->assertSame(0, $snapshot['receivedBeforeDeadlineMinor']);
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM incoming_payment_receipts')->fetchColumn());
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM provider_operations')->fetchColumn());
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM provider_requests')->fetchColumn());
        $this->assertFalse($snapshot['nonExecutionEstablished']);
    }

    public function testSeparatePortionsPreserveTheirAmountsAndOverpayment(): void
    {
        $original = $this->descriptor();
        $this->provider->register($original);
        $this->provider->record($original, $this->receipt('advance', 899));
        $partial = $this->provider->inspect($original);
        $this->assertFalse($partial['fullAmountReceived']);
        $this->assertSame(600, $partial['remainingAmountMinor']);
        $this->provider->record($original, $this->receipt('balance', 600));
        $paid = $this->provider->inspect($original);
        $this->assertTrue($paid['fullAmountReceived']);
        $this->assertTrue($paid['requiredAmountReceivedBeforeDeadline']);
        $this->assertSame(1499, $paid['receivedAmountMinor']);
        $this->assertCount(2, $paid['receipts']);
        $this->provider->record($original, $this->receipt('extra', 1));
        $overpaid = $this->provider->inspect($original);
        $this->assertSame(1, $overpaid['overpaymentAmountMinor']);
        $this->assertSame(0, $overpaid['remainingAmountMinor']);
        $this->assertSame(1500, $overpaid['receivedAmountMinor']);
    }

    public function testAnEquivalentTransferIsIdempotentButChangedMoneyIsRefused(): void
    {
        $original = $this->descriptor();
        $this->provider->register($original);
        $receipt = $this->receipt('same-transfer', 1499);
        $this->provider->record($original, $receipt);
        $this->assertSame($receipt->toArray(), $this->provider->record($original, $receipt));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The original incoming transfer changed its owner, money or evidence.');
        $this->provider->record($original, $this->receipt('same-transfer', 1500));
    }

    public function testOneTransferCannotBeReassignedToAnotherPurchase(): void
    {
        $original = $this->descriptor();
        $other = $this->descriptor('purchase-2', 'reference-2');
        $this->provider->register($original);
        $this->provider->register($other);
        $this->provider->record($original, $this->receipt('same-transfer', 1499));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The original incoming transfer changed its owner, money or evidence.');
        $this->provider->record($other, new IncomingPaymentReceipt(
            $other->identity->key(),
            'same-transfer',
            1499,
            'USD',
            1000
        ));
    }

    public function testAnOriginalReferenceCannotMoveToAnotherGeneration(): void
    {
        $this->provider->register($this->descriptor());
        $substituted = IncomingPaymentDescriptor::fromArray([
            ...$this->descriptor()
                ->toArray(),
            'identity' => (new Identity('shop-a', 'purchase-1', 'generation-2', 'incoming'))->toArray(),
        ]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The original incoming-payment identity, reference or terms were substituted.');
        $this->provider->register($substituted);
    }

    public function testARehashedForeignReceiptStillCannotBecomeOriginalPaymentEvidence(): void
    {
        $original = $this->descriptor();
        $this->provider->register($original);
        $this->provider->record($original, $this->receipt('transfer-1', 1499));
        $foreign = [
            ...$this->receipt('transfer-1', 1499)
                ->toArray(),
            'receivable' => 'another-period',
        ];
        $payload = PortableDescriptor::encode($foreign);
        $q = $this->db->prepare('UPDATE incoming_payment_receipts SET payload = ?, fingerprint = ?');
        $q->execute([$payload, hash('sha256', $payload)]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The incoming receipt targets another original receivable or currency.');
        $this->provider->inspect($original);
    }

    public function testForeignCredentialsAreRefusedBeforeTheProviderQueriesMissingTables(): void
    {
        $foreign = new IncomingPaymentJournal(new PDO('sqlite::memory:'), 'shop-b');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The incoming-payment credential belongs to another client.');
        $foreign->inspect($this->descriptor());
    }

    public function testMissingOriginalReferenceDoesNotAuthorizeAnIncomingReceipt(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The original incoming-payment reference is unavailable.');
        $this->provider->record($this->descriptor(), $this->receipt('unknown-reference', 1499));
    }

    public function testFutureReceiptTimeCannotAuthorizePayment(): void
    {
        $this->provider->register($this->descriptor());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('An incoming receipt cannot claim a future provider observation.');
        $this->provider->record($this->descriptor(), $this->receipt('future', 1499, 1001));
    }

    public function testAnOverflowCannotBeRoundedIntoASuccessfulMoneyObservation(): void
    {
        $this->provider->register($this->descriptor());
        $this->provider->record($this->descriptor(), $this->receipt('large', PHP_INT_MAX));
        $this->provider->record($this->descriptor(), $this->receipt('overflow', 1));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Incoming money exceeds the exact integer observation range.');
        $this->provider->inspect($this->descriptor());
    }

    public function testAnIncompleteReceiptSetIsRefusedWithoutDiscardingProviderFacts(): void
    {
        $original = $this->descriptor();
        $this->provider->register($original);
        for ($i = 0; $i < 65; ++$i) {
            $this->provider->record($original, $this->receipt('transfer-' . $i, 1));
        }
        $this->assertSame(65, (int) $this->db->query('SELECT COUNT(*) FROM incoming_payment_receipts')->fetchColumn());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The bounded incoming-payment snapshot is incomplete and remains unresolved.');
        $this->provider->inspect($original);
    }

    public function testAProviderObservationCannotBorrowACallerTransaction(): void
    {
        $this->provider->register($this->descriptor());
        $this->db->beginTransaction();
        try {
            $this->provider->inspect($this->descriptor());
            $this->fail('An incoming provider observation must own its transaction.');
        } catch (LogicException $error) {
            $this->assertSame(
                'A caller transaction cannot contain the incoming provider transaction.',
                $error->getMessage()
            );
            $this->assertTrue($this->db->inTransaction());
        } finally {
            $this->db->rollBack();
        }
    }

    public static function malformedMoney(): array
    {
        return [[[
            'amountMinor' => 0,
        ]], [[
            'amountMinor' => 1499.0,
        ]], [[
            'currency' => 'usd',
        ]],
            [[
                'deadline' => '1100',
            ]], [[
                'deadline' => null,
            ]], [[
                'extra' => true,
            ]]];
    }

    #[DataProvider('malformedMoney')]
    public function testTheOriginalReceivingTermsRejectCoercedOrUnknownFields(array $changes): void
    {
        $value = [...$this->descriptor()->toArray(), ...$changes];
        $this->expectException(InvalidArgumentException::class);
        IncomingPaymentDescriptor::fromArray($value);
    }

    public static function malformedReceipts(): array
    {
        return [
            [[
                'currency' => 'EUR',
            ], LogicException::class],
            [[
                'receivable' => 'another-period',
            ], LogicException::class],
            [[
                'amountMinor' => 0,
            ], InvalidArgumentException::class],
            [[
                'amountMinor' => 1499.0,
            ], InvalidArgumentException::class],
            [[
                'receivedAt' => 1000.0,
            ], InvalidArgumentException::class],
            [[
                'receivedAt' => 1001,
            ], LogicException::class],
            [[
                'extra' => true,
            ], InvalidArgumentException::class],
        ];
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('malformedReceipts')]
    public function testAnIncomingReceiptCannotCoerceMoneyOrSubstituteItsTarget(array $changes, string $exception): void
    {
        $original = $this->descriptor();
        $this->provider->register($original);
        $value = [...$this->receipt('transfer-1', 1499)->toArray(), ...$changes];
        $this->expectException($exception);
        $this->provider->record($original, IncomingPaymentReceipt::fromArray($value));
    }

    private function descriptor(
        string $purchase = 'purchase-1',
        string $reference = 'bank-reference-1'
    ): IncomingPaymentDescriptor {
        return new IncomingPaymentDescriptor(new Identity(
            'shop-a',
            $purchase,
            'generation-1',
            'incoming'
        ), $reference, 1499, 'USD', 1100);
    }

    private function receipt(string $transfer, int $amount, int $receivedAt = 1000): IncomingPaymentReceipt
    {
        return new IncomingPaymentReceipt($this->descriptor()->identity->key(), $transfer, $amount, 'USD', $receivedAt);
    }
}
