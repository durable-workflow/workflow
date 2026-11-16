<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingRefundLedger;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingRefundTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\LostAcknowledgement;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

require_once __DIR__ . '/../../../../examples/configured-state-machines/providers/bootstrap.php';

final class IncomingRefundTest extends TestCase
{
    private PDO $db;

    private Journal $provider;

    private IncomingPaymentJournal $bank;

    private int $now = 1000;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->provider = new Journal($this->db, 'shop-a', fn (): int => $this->now);
        $this->provider->install();
        $this->provider->installIncomingPaymentSupport();
        $this->bank = $this->provider->incomingPayments();
        $this->bank->register($this->original());
    }

    public function testPartialRefundsKeepTheirOriginalTransfersAndNeverExceedReceivedMoney(): void
    {
        $this->receive();
        $first = $this->refund('refund-part-one', 400);
        $second = $this->refund('refund-part-two', 600);
        foreach ([$first, $second] as $request) {
            $this->provider->plan($request, Status::Confirmed);
            $this->assertSame(Status::Confirmed, $this->provider->attempt($request)->status);
        }
        $overflow = $this->refund('refund-too-much', 1);
        $this->provider->plan($overflow, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($overflow)->status);
        $this->assertSame('effect-' . $first->identity->key(), $this->provider->attempt($first)->effectId);
        $this->assertSame([400, 600], $this->effectAmounts());
        $this->assertSame(1000, $this->bank->inspect($this->original())['receivedAmountMinor']);
        $this->assertSame(['refund-incoming'], $this->db->query(
            "SELECT DISTINCT json_extract(request, '$.action') FROM provider_operations"
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    #[DataProvider('uncertainStatuses')]
    public function testUncertainRefundsReserveTheOriginalMoneyUntilTheirOutcomeIsKnown(Status $status): void
    {
        $this->receive();
        $first = $this->refund('original-pending-refund', 600);
        $this->provider->plan($first, $status);
        $this->assertSame($status, $this->provider->attempt($first)->status);
        $other = $this->refund('competing-refund', 500);
        $this->provider->plan($other, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($other)->status);
        $this->assertSame([], $this->effectAmounts());
        $this->assertSame(Status::Confirmed, $this->provider->resolve($first, Status::Confirmed)->status);
        $this->assertSame([600], $this->effectAmounts());
        $this->assertSame('effect-' . $first->identity->key(), $this->provider->lookup($first)->effectId);
    }

    public static function uncertainStatuses(): iterable
    {
        yield 'unknown' => [Status::Unknown];
        yield 'provider processing' => [Status::Processing];
    }

    public function testDefinitiveNonExecutionReleasesTheReservationButARetryRechecksTheBudget(): void
    {
        $this->receive();
        $first = $this->refund('original-refund', 600);
        $this->provider->plan($first, Status::Unknown);
        $this->provider->attempt($first);
        $this->provider->resolve($first, Status::NotExecuted);
        $other = $this->refund('other-original-portion', 1000);
        $this->provider->plan($other, Status::Confirmed);
        $this->assertSame(Status::Confirmed, $this->provider->attempt($other)->status);
        $this->provider->plan($first, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($first)->status);
        $this->assertSame([1000], $this->effectAmounts());
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM provider_operations')->fetchColumn());
    }

    public function testAnAcknowledgementLossReconcilesTheSameCommittedRefundExactlyOnce(): void
    {
        $this->receive();
        $refund = $this->refund('original-refund', 1000);
        $this->provider->plan($refund, Status::Confirmed, true);
        try {
            $this->provider->attempt($refund);
            $this->fail('The provider must commit before the selected acknowledgement loss.');
        } catch (LostAcknowledgement) {
            $this->assertSame([1000], $this->effectAmounts());
        }
        $replacement = new Journal($this->db, 'shop-a', fn (): int => $this->now);
        $this->assertSame(Status::Confirmed, $replacement->lookup($refund)->status);
        $this->assertSame('effect-' . $refund->identity->key(), $replacement->attempt($refund)->effectId);
        $this->assertSame([1000], $this->effectAmounts());
        $this->assertSame(2, (int) $this->db->query(
            "SELECT COUNT(*) FROM provider_requests WHERE kind = 'command'"
        )->fetchColumn());
    }

    public function testOneTransferCannotSpendTheUnrelatedPortionOfAnotherTransfer(): void
    {
        $this->receive('advance', 400);
        $this->receive('balance', 600);
        $tooMuch = $this->refund('advance-refund', 500, 'advance');
        $this->provider->plan($tooMuch, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($tooMuch)->status);
        foreach ([['balance-refund', 600, 'balance'],
            ['actual-advance-refund', 400, 'advance'],
        ] as [$id, $amount, $transfer]) {
            $request = $this->refund($id, $amount, $transfer);
            $this->provider->plan($request, Status::Confirmed);
            $this->assertSame(Status::Confirmed, $this->provider->attempt($request)->status);
        }
        $this->assertSame([600, 400], $this->effectAmounts());
    }

    public function testLateOverpaymentCanBeRefundedInFullWithoutRewritingItsOriginalDeadline(): void
    {
        $this->now = 2000;
        $this->receive('late-transfer', 1500, 1500);
        $refund = $this->refund('late-original-refund', 1500, 'late-transfer');
        $this->provider->plan($refund, Status::Confirmed);
        $this->assertSame(Status::Confirmed, $this->provider->attempt($refund)->status);
        $this->assertSame([1500], $this->effectAmounts());
        $snapshot = $this->bank->inspect($this->original());
        $this->assertSame(1100, $snapshot['original']['deadline']);
        $this->assertSame(1500, $snapshot['receivedAmountMinor']);
        $this->assertSame(500, $snapshot['overpaymentAmountMinor']);
        $this->assertFalse($snapshot['requiredAmountReceivedBeforeDeadline']);
        $this->assertFalse($snapshot['nonExecutionEstablished']);
    }

    public function testClosingAnAlreadyAdmittedRefundDoesNotEraseItsUncertainMoney(): void
    {
        $this->receive();
        $refund = $this->refund('original-refund', 600);
        $this->provider->plan($refund, Status::Unknown);
        $this->provider->attempt($refund);
        $closed = $this->provider->closeAdmission($refund);
        $this->assertSame(Status::Unknown, $closed->status);
        $this->assertTrue($closed->admissionClosed);
        $other = $this->refund('another-refund', 500);
        $this->provider->plan($other, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($other)->status);
        $this->assertSame(Status::Confirmed, $this->provider->resolve($refund, Status::Confirmed)->status);
        $this->assertSame([600], $this->effectAmounts());
    }

    public function testAnAbsentIncomingTransferCannotCreateOrRetroactivelyAdmitARefund(): void
    {
        $refund = $this->refund('original-refund', 1000);
        $this->provider->plan($refund, Status::Confirmed);
        $this->assertSame(Status::Unknown, $this->provider->attempt($refund)->status);
        $this->assertSame([], $this->effectAmounts());
        $this->receive();
        try {
            $this->provider->resolve($refund, Status::Confirmed);
            $this->fail('A later receipt must not invent an earlier money admission.');
        } catch (LogicException $error) {
            $this->assertSame('An incoming refund lost its original money admission.', $error->getMessage());
        }
        $this->provider->resolve($refund, Status::NotExecuted);
        $this->provider->plan($refund, Status::Confirmed);
        $this->assertSame(Status::Confirmed, $this->provider->attempt($refund)->status);
        $this->assertSame([1000], $this->effectAmounts());
    }

    public function testAProviderTransferBelongingToAnotherPurchaseCannotFundThisRefund(): void
    {
        $foreign = new IncomingPaymentDescriptor(
            new Identity('shop-a', 'another-purchase', 'g1', 'bank-receivable'),
            'foreign-bank-reference',
            1000,
            'USD',
            1100
        );
        $this->bank->register($foreign);
        $this->bank->record(
            $foreign,
            new IncomingPaymentReceipt($foreign->identity->key(), 'foreign-transfer', 1000, 'USD', 1000)
        );
        $refund = $this->refund('original-refund', 1000, 'foreign-transfer');
        $this->provider->plan($refund, Status::Confirmed);
        try {
            $this->provider->attempt($refund);
            $this->fail('Foreign incoming money cannot be substituted.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('another original owner', $error->getMessage());
        }
        $this->assertSame([], $this->effectAmounts());
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM provider_operations')->fetchColumn());
    }

    public function testTheSameRefundIdentityCannotChangeItsAmount(): void
    {
        $this->receive();
        $first = $this->refund('original-refund', 400);
        $this->provider->plan($first, Status::Confirmed);
        $this->provider->attempt($first);
        $changed = $this->refund('original-refund', 401);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('original provider command terms were substituted');
        $this->provider->lookup($changed);
    }

    public function testALostUncertainReservationCannotBeReconstructedFromALookup(): void
    {
        $this->receive();
        $first = $this->refund('original-refund', 600);
        $this->provider->plan($first, Status::Unknown);
        $this->provider->attempt($first);
        $this->db->exec('DELETE FROM incoming_refund_allocations');
        $other = $this->refund('another-refund', 600);
        $this->provider->plan($other, Status::Confirmed);
        try {
            $this->provider->attempt($other);
            $this->fail('An unresolved refund still owns its money when admission evidence is missing.');
        } catch (LogicException $error) {
            $this->assertSame('An unresolved incoming refund has no original money admission.', $error->getMessage());
        }
        $this->assertSame([], $this->effectAmounts());
    }

    public function testALostProviderOutcomeDoesNotFreeReservedMoney(): void
    {
        $this->receive();
        $first = $this->refund('original-refund', 600);
        $this->provider->plan($first, Status::Unknown);
        $this->provider->attempt($first);
        $this->db->exec('DELETE FROM provider_operations');
        $other = $this->refund('another-refund', 600);
        $this->provider->plan($other, Status::Confirmed);
        try {
            $this->provider->attempt($other);
            $this->fail('A missing outcome cannot release admitted money.');
        } catch (LogicException $error) {
            $this->assertSame(
                'An incoming refund allocation lost its original provider outcome.',
                $error->getMessage()
            );
        }
        $this->assertSame([], $this->effectAmounts());
    }

    public function testRehashingTheIncomingReceiptCannotIncreasePreviouslyAdmittedMoney(): void
    {
        $this->receive();
        $first = $this->refund('original-refund', 500);
        $this->provider->plan($first, Status::Confirmed);
        $this->provider->attempt($first);
        $row = $this->db->query('SELECT * FROM incoming_payment_receipts')
            ->fetch(PDO::FETCH_ASSOC);
        $receipt = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
        $receipt['amountMinor'] = 2000;
        $payload = PortableDescriptor::encode($receipt);
        $update = $this->db->prepare('UPDATE incoming_payment_receipts SET payload = ?, fingerprint = ? WHERE id = ?');
        $update->execute([$payload, hash('sha256', $payload), $row['id']]);
        $other = $this->refund('another-refund', 1500);
        $this->provider->plan($other, Status::Confirmed);
        try {
            $this->provider->attempt($other);
            $this->fail('A receipt cannot replace the money pinned by an existing original refund.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('allocation or received money was substituted', $error->getMessage());
        }
        $this->assertSame([500], $this->effectAmounts());
    }

    public function testARehashedRequestCannotHideItsOriginalMoneyReservation(): void
    {
        $this->receive();
        $first = $this->refund('original-refund', 500);
        $this->provider->plan($first, Status::Confirmed);
        $this->provider->attempt($first);
        $value = $first->toArray();
        $value['terms']['transfer'] = 'hidden-transfer';
        $changed = Request::fromArray($value);
        $update = $this->db->prepare('UPDATE provider_operations SET request = ?, fingerprint = ? WHERE id = ?');
        $update->execute(
            [PortableDescriptor::encode($changed->toArray()), $changed->fingerprint(), $first->identity->key()]
        );
        $other = $this->refund('another-refund', 600);
        $this->provider->plan($other, Status::Confirmed);
        try {
            $this->provider->attempt($other);
            $this->fail('A rehashed provider request cannot hide its original money reservation.');
        } catch (LogicException $error) {
            $this->assertSame(
                'The incoming refund inventory substituted its original terms or owner.',
                $error->getMessage()
            );
        }
        $this->assertSame([500], $this->effectAmounts());
    }

    public function testThePerTransferBoundDoesNotSilentlyDropOriginalRefunds(): void
    {
        $this->receive();
        for ($i = 0; $i < 64; ++$i) {
            $request = $this->refund('original-refund-' . $i, 1);
            $this->provider->plan($request, Status::NotExecuted);
            $this->provider->attempt($request);
        }
        $extra = $this->refund('extra-refund', 1);
        $this->provider->plan($extra, Status::Confirmed);
        try {
            $this->provider->attempt($extra);
            $this->fail('Incomplete refund inventories cannot be treated as free money.');
        } catch (LogicException $error) {
            $this->assertSame('The bounded original incoming refund inventory is full.', $error->getMessage());
        }
        $this->assertSame([], $this->effectAmounts());
        $this->assertSame(64, (int) $this->db->query('SELECT COUNT(*) FROM provider_operations')->fetchColumn());
    }

    public function testLargeIntegerRefundsDoNotRoundOrOverflowTheRemainingMoney(): void
    {
        $this->receive('large-transfer', PHP_INT_MAX);
        $first = $this->refund('large-refund', PHP_INT_MAX - 1, 'large-transfer');
        $this->provider->plan($first, Status::Confirmed);
        $this->assertSame(Status::Confirmed, $this->provider->attempt($first)->status);
        $other = $this->refund('overflow-refund', 2, 'large-transfer');
        $this->provider->plan($other, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($other)->status);
        $this->assertSame([PHP_INT_MAX - 1], $this->effectAmounts());
    }

    public function testRefundAdmissionRequiresTheProviderOwnedTransaction(): void
    {
        $request = $this->refund('original-refund', 1000);
        $ledger = new IncomingRefundLedger($this->db, fn (): int => $this->now);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('provider-owned transaction');
        $ledger->admit($request, Status::Confirmed);
    }

    #[DataProvider('malformedTerms')]
    public function testRefundTermsRejectCoercionAndForeignCurrency(string $key, mixed $value): void
    {
        $terms = (new IncomingRefundTerms($this->original(), 'transfer-one', 1000))->toArray();
        $terms[$key] = $value;
        $this->expectException(InvalidArgumentException::class);
        IncomingRefundTerms::fromArray($terms);
    }

    public static function malformedTerms(): iterable
    {
        yield 'fractional money' => ['amountMinor', 1.5];
        yield 'coerced money' => ['amountMinor', '1000'];
        yield 'zero money' => ['amountMinor', 0];
        yield 'negative money' => ['amountMinor', -1];
        yield 'foreign currency' => ['currency', 'EUR'];
        yield 'extra field' => ['unselected', true];
        yield 'missing transfer' => ['transfer', null];
    }

    private function original(): IncomingPaymentDescriptor
    {
        return new IncomingPaymentDescriptor(
            new Identity('shop-a', 'purchase-1', 'g1', 'bank-receivable'),
            'original-bank-reference',
            1000,
            'USD',
            1100
        );
    }

    private function receive(string $transfer = 'transfer-one', int $amount = 1000, int $time = 1000): void
    {
        $this->bank->record($this->original(), new IncomingPaymentReceipt(
            $this->original()
                ->identity->key(),
            $transfer,
            $amount,
            'USD',
            $time
        ));
    }

    private function refund(string $identity, int $amount, string $transfer = 'transfer-one'): Request
    {
        return new Request(
            new Identity('shop-a', 'purchase-1', 'g1', $identity),
            'refund-incoming',
            (new IncomingRefundTerms($this->original(), $transfer, $amount))->toArray(),
            $this->original()
->identity
        );
    }

    private function effectAmounts(): array
    {
        return $this->db->query("SELECT json_extract(o.request, '$.terms.amountMinor')
            FROM provider_effects e JOIN provider_operations o ON o.id = e.operation_id ORDER BY e.sequence")
            ->fetchAll(PDO::FETCH_COLUMN);
    }
}
