<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\LostAcknowledgement;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\OperationContext;

require_once __DIR__ . '/../../../../examples/configured-state-machines/providers/bootstrap.php';

final class RetainedIncomingPaymentOperationTest extends TestCase
{
    private PDO $db;

    private Journal $provider;

    private IncomingPaymentProvider $incoming;

    private int $now = 1000;

    private bool $allowed = true;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->incoming = new IncomingPaymentProvider($this->db, 'shop-a', fn (): int => $this->now);
        $this->provider = $this->incoming->commands;
        $this->provider->install();
        $this->incoming->install();
    }

    public function testCancellationBeforeReferenceCreationAuthoritativelyFencesRatherThanInferringAbsence(): void
    {
        $result = $this->handler()
            ->compensate($this->context());
        $this->assertTrue($result->verified);
        $this->assertSame([], $this->effects());
        $this->handler()
            ->execute($this->context());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('rejects new transfers');
        $this->incoming->receipts
            ->record($this->original(), $this->receipt('late', 1000));
    }

    public function testLateOriginalMoneyIsRefundedWithoutRewritingExpiryOrManufacturingCapture(): void
    {
        $this->handler()
            ->execute($this->context());
        $this->now = 2000;
        $receipt = $this->receipt('late-overpayment', 1500);
        $this->receive($receipt);
        $request = IncomingPaymentOperation::refundRequest(
            $this->context()
->scope,
            'incoming.watch',
            $this->original(),
            $receipt
        );
        $this->provider->plan($request, Status::Confirmed);
        $result = $this->handler()
            ->compensate($this->context());
        $this->assertTrue($result->verified);
        $this->assertSame(['refund-incoming'], $this->effects());
        $snapshot = $this->incoming->receipts
            ->inspect($this->original());
        $this->assertSame(1100, $snapshot['original']['deadline']);
        $this->assertSame(1500, $snapshot['receivedAmountMinor']);
        $this->assertFalse($snapshot['requiredAmountReceivedBeforeDeadline']);
        $this->assertFalse($snapshot['nonExecutionEstablished']);
        $this->assertSame($result->toArray(), $this->handler()->compensate($this->context())->toArray());
        $this->assertSame(['refund-incoming'], $this->effects());
    }

    public function testAlreadyAdmittedTransferRemainsIdempotentButBackdatedNewTransferIsRefusedAfterClosure(): void
    {
        $bank = $this->incoming->receipts;
        $bank->register($this->original(), IncomingPaymentJournal::CLOSABLE);
        $receipt = $this->receipt('original', 400);
        $bank->record($this->original(), $receipt);
        $first = $bank->closeAdmission($this->original(), IncomingPaymentJournal::CLOSABLE);
        $this->now = 3000;
        $this->assertSame($receipt->toArray(), $bank->record($this->original(), $receipt));
        $this->assertSame($first, $bank->closeAdmission($this->original(), IncomingPaymentJournal::CLOSABLE));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('rejects new transfers');
        $bank->record(
            $this->original(),
            new IncomingPaymentReceipt($this->original()->identity->key(), 'backdated-new-transfer', 600, 'USD', 1000)
        );
    }

    public function testAnUnfencedReferenceCannotBeUpgradedToAClosableContractDuringCancellation(): void
    {
        $this->incoming->receipts
            ->register($this->original());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('contract cannot be replaced');
        $this->handler()
            ->compensate($this->context());
    }

    public function testUnfencedMoneyCanBeRefundedButTheFutureIncomingDutyRemainsUnresolved(): void
    {
        $context = $this->context(IncomingPaymentJournal::OPEN);
        $this->handler()
            ->execute($context);
        $receipt = $this->receipt('original', 1000);
        $this->receive($receipt);
        $this->provider->plan(
            IncomingPaymentOperation::refundRequest($context->scope, 'incoming.watch', $this->original(), $receipt),
            Status::Confirmed
        );
        $result = $this->handler()
            ->compensate($context);
        $this->assertFalse($result->verified);
        $this->assertSame('incoming-admission-open', $result->reason);
        $this->assertSame(['refund-incoming'], $this->effects());
        $this->receive($this->receipt('later-transfer', 1));
        $this->assertSame(1001, $this->incoming->receipts->inspect($this->original())['receivedAmountMinor']);
    }

    #[DataProvider('unsettled')]
    public function testUnsettledRefundKeepsTheOriginalRetainedObligation(Status $status): void
    {
        $this->handler()
            ->execute($this->context());
        $receipt = $this->receipt('original', 1000);
        $this->receive($receipt);
        $request = IncomingPaymentOperation::refundRequest(
            $this->context()
->scope,
            'incoming.watch',
            $this->original(),
            $receipt
        );
        $this->provider->plan($request, $status);
        $result = $this->handler()
            ->compensate($this->context());
        $this->assertFalse($result->verified);
        $this->assertSame('incoming-refund-' . $status->value, $result->reason);
        $this->assertSame([], $this->effects());
        if (in_array($status, [Status::Processing, Status::Unknown], true)) {
            $this->provider->resolve($request, Status::Confirmed);
            $this->assertTrue($this->handler()->compensate($this->context())->verified);
            $this->assertSame(['refund-incoming'], $this->effects());
        }
    }

    public static function unsettled(): array
    {
        return [[Status::Processing], [Status::Unknown], [Status::NotExecuted], [Status::Refused]];
    }

    public function testLostRefundAcknowledgementKeepsTheSameBusinessOperationAcrossHandlerReplacement(): void
    {
        $this->handler()
            ->execute($this->context());
        $receipt = $this->receipt('original', 1000);
        $this->receive($receipt);
        $request = IncomingPaymentOperation::refundRequest(
            $this->context()
->scope,
            'incoming.watch',
            $this->original(),
            $receipt
        );
        $this->provider->plan($request, Status::Confirmed, true);
        try {
            $this->handler()
                ->compensate($this->context());
            $this->fail('The refund must commit before the selected acknowledgement loss.');
        } catch (LostAcknowledgement) {
            $this->incoming = new IncomingPaymentProvider($this->db, 'shop-a', fn (): int => $this->now);
            $this->provider = $this->incoming->commands;
            $this->assertTrue($this->handler()->compensate($this->context())->verified);
        }
        $this->assertSame(['refund-incoming'], $this->effects());
        $this->assertSame(
            2,
            (int) $this->db->query("SELECT COUNT(*) FROM provider_requests WHERE kind = 'command'")
                ->fetchColumn()
        );
    }

    #[DataProvider('lostEvidence')]
    public function testMissingFenceOrReceiptEvidenceCannotBeReconstructedAsSuccessfulSettlement(string $target): void
    {
        $bank = $this->incoming->receipts;
        $bank->register($this->original(), IncomingPaymentJournal::CLOSABLE);
        $bank->record($this->original(), $this->receipt('original', 1000));
        $bank->closeAdmission($this->original(), IncomingPaymentJournal::CLOSABLE);
        $this->db->exec('DELETE FROM ' . $target);
        $this->expectException(LogicException::class);
        $bank->closeAdmission($this->original(), IncomingPaymentJournal::CLOSABLE);
    }

    public static function lostEvidence(): array
    {
        return [['incoming_payment_closures'], ['incoming_payment_contracts'], ['incoming_payment_receipts']];
    }

    public function testHostRefusalPrecedesAllProviderSqlInBothDirections(): void
    {
        $this->allowed = false;
        $this->db->exec('DROP TABLE incoming_payment_references');
        foreach (['execute', 'compensate'] as $method) {
            try {
                $this->handler()
                    ->{$method}($this->context());
                $this->fail('Revoked host authority cannot reach provider storage.');
            } catch (LogicException $error) {
                $this->assertSame('Original incoming host authority refused.', $error->getMessage());
            }
        }
    }

    public function testAllSixtyFourOriginalTransfersFitTheNativeEvidenceBoundWithoutTruncation(): void
    {
        $this->handler()
            ->execute($this->context());
        for ($i = 0; $i < 64; ++$i) {
            $receipt = $this->receipt('portion-' . $i, 1);
            $this->receive($receipt);
            $this->provider->plan(
                IncomingPaymentOperation::refundRequest(
                    $this->context()
->scope,
                    'incoming.watch',
                    $this->original(),
                    $receipt
                ),
                Status::Confirmed
            );
        }
        $result = $this->handler()
            ->compensate($this->context());
        $this->assertTrue($result->verified);
        $this->assertCount(2, $result->evidence);
        $this->assertCount(64, $this->effects());
    }

    private function handler(): IncomingPaymentOperation
    {
        return new IncomingPaymentOperation($this->incoming, function (OperationContext $context, string $mode): void {
            if (! $this->allowed || $context->originalRunId !== 'original-run' || ! in_array(
                $mode,
                ['forward', 'compensate'],
                true
            )) {
                throw new LogicException('Original incoming host authority refused.');
            }
        });
    }

    private function context(string $contract = IncomingPaymentJournal::CLOSABLE): OperationContext
    {
        return new OperationContext(
            new ScopeIdentity('shop-a', 'incoming', 'purchase-1.payment', 'g1'),
            new Obligation('incoming.watch', 'provider.incoming', '1', [
                'original' => $this->original()
                    ->toArray(),
                'admissionContract' => $contract,
            ]),
            'original-operation',
            'original-run',
            'recovery-activity',
            'recovery-attempt',
            false
        );
    }

    private function original(): IncomingPaymentDescriptor
    {
        return new IncomingPaymentDescriptor(new Identity(
            'shop-a',
            'purchase-1',
            'g1',
            'incoming'
        ), 'original-reference', 1000, 'USD', 1100);
    }

    private function receipt(string $transfer, int $amount): IncomingPaymentReceipt
    {
        return new IncomingPaymentReceipt($this->original()->identity->key(), $transfer, $amount, 'USD', $this->now);
    }

    private function receive(IncomingPaymentReceipt $receipt): void
    {
        $this->incoming->receipts
            ->record($this->original(), $receipt);
    }

    private function effects(): array
    {
        return $this->db->query("SELECT json_extract(o.request, '$.action') FROM provider_effects e
            JOIN provider_operations o ON o.id = e.operation_id ORDER BY e.sequence")->fetchAll(PDO::FETCH_COLUMN);
    }
}
