<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\LostAcknowledgement;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\OriginalUnitAmounts;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundCommand;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundGateway;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../examples/configured-state-machines/providers/bootstrap.php';

final class UnitRefundGatewayTest extends TestCase
{
    public function testMissingUnitRefundThenFullCancellationPaysOnlyTheRemainingOriginalUnits(): void
    {
        [$db, , $gateway, $original] = $this->funded();
        $partial = new UnitRefundCommand($original, 'partial-shortage', [2]);
        $gateway->plan($partial, Status::Confirmed);
        $first = $gateway->attempt($partial);
        $this->assertSame('confirmed', $first['status']);
        $this->assertSame(499, $first['ownPayoutMinor']);
        $this->assertSame([2], $first['confirmedUnits']);
        $full = new UnitRefundCommand($original, 'original-full-cancellation', [0, 1, 2]);
        $gateway->plan($full, Status::Confirmed);
        $second = $gateway->attempt($full);
        $this->assertSame([0, 1, 2], $second['confirmedUnits']);
        $this->assertSame(1499, $second['confirmedAmountMinor']);
        $this->assertSame(1000, $second['ownPayoutMinor']);
        $this->assertSame(1499, (int) $db->query('SELECT SUM(amount_minor) FROM unit_refund_payouts')->fetchColumn());
        $this->assertSame(2, (int) $db->query('SELECT COUNT(*) FROM unit_refund_payouts')->fetchColumn());
        $this->assertSame($second, $gateway->attempt($full));
        $this->assertSame($second, $gateway->lookup($full));
        $this->assertSame(2, (int) $db->query('SELECT COUNT(*) FROM unit_refund_payouts')->fetchColumn());
    }

    public function testAnOverlappingUnresolvedRefundFencesAnotherCommandUntilItsOriginalOutcomeIsKnown(): void
    {
        [$db, , $gateway, $original] = $this->funded();
        $partial = new UnitRefundCommand($original, 'processing-part', [2]);
        $gateway->plan($partial, Status::Processing);
        $this->assertSame('processing', $gateway->attempt($partial)['status']);
        $full = new UnitRefundCommand($original, 'full-after-processing', [0, 1, 2]);
        $gateway->plan($full, Status::Confirmed);
        $blocked = $gateway->attempt($full);
        $this->assertSame('unknown', $blocked['status']);
        $this->assertSame([], $blocked['confirmedUnits']);
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM unit_refund_payouts')->fetchColumn());
        $gateway->resolve($partial, Status::Confirmed);
        $done = $gateway->resolve($full, Status::Confirmed);
        $this->assertSame(1000, $done['ownPayoutMinor']);
        $this->assertSame([0, 1, 2], $done['confirmedUnits']);
        $this->assertSame(1499, (int) $db->query('SELECT SUM(amount_minor) FROM unit_refund_payouts')->fetchColumn());
    }

    public function testRefusedOriginalCommandCannotResurrectAfterAnotherAuthorizedCommandTakesItsUnits(): void
    {
        [$db, , $gateway, $original] = $this->funded();
        $failed = new UnitRefundCommand($original, 'refused-shortage', [2]);
        $gateway->plan($failed, Status::Refused);
        $this->assertSame('refused', $gateway->attempt($failed)['status']);
        $full = new UnitRefundCommand($original, 'cancel-refused-purchase', [0, 1, 2]);
        $gateway->plan($full, Status::Confirmed);
        $this->assertSame(1499, $gateway->attempt($full)['ownPayoutMinor']);
        try {
            $gateway->resolve($failed, Status::Confirmed);
            $this->fail('A terminal refusal was allowed to pay the same original unit again.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('Only an original unresolved', $error->getMessage());
        }
        $this->assertSame(1499, (int) $db->query('SELECT SUM(amount_minor) FROM unit_refund_payouts')->fetchColumn());
    }

    public function testLostAcknowledgementReconcilesOneOriginalCommittedPayout(): void
    {
        [$db, , $gateway, $original] = $this->funded();
        $command = new UnitRefundCommand($original, 'lost-original-ack', [2]);
        $gateway->plan($command, Status::Confirmed, true);
        try {
            $gateway->attempt($command);
            $this->fail('The provider acknowledgement was not lost.');
        } catch (LostAcknowledgement $error) {
            $this->assertStringContainsString('committed before acknowledgement', $error->getMessage());
        }
        $recovered = $gateway->lookup($command);
        $this->assertSame('confirmed', $recovered['status']);
        $this->assertSame(499, $recovered['ownPayoutMinor']);
        $this->assertSame($recovered, $gateway->attempt($command));
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM unit_refund_payouts')->fetchColumn());
    }

    public function testAlreadyCoveredUnitsProduceNoSecondFinancialPayout(): void
    {
        [$db, , $gateway, $original] = $this->funded();
        $first = new UnitRefundCommand($original, 'first-unit-repayment', [2]);
        $gateway->plan($first, Status::Confirmed);
        $gateway->attempt($first);
        $second = new UnitRefundCommand($original, 'separately-requested-same-remedy', [2]);
        $gateway->plan($second, Status::Confirmed);
        $result = $gateway->attempt($second);
        $this->assertSame('confirmed', $result['status']);
        $this->assertSame([2], $result['confirmedUnits']);
        $this->assertNull($result['ownPayoutId']);
        $this->assertSame(0, $result['ownPayoutMinor']);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM unit_refund_payouts')->fetchColumn());
    }

    public function testTheSameBusinessRequestCannotChangeItsOriginalUnitSet(): void
    {
        [, , $gateway, $original] = $this->funded();
        $first = new UnitRefundCommand($original, 'one-business-request', [2]);
        $gateway->plan($first, Status::Confirmed);
        $gateway->attempt($first);
        $replacement = new UnitRefundCommand($original, 'one-business-request', [0, 1, 2]);
        $this->expectException(LogicException::class);
        $gateway->lookup($replacement);
    }

    public function testLegacyWholeRefundCannotBypassTheOriginalUnitAccounting(): void
    {
        [$db, $journal, , $original] = $this->funded();
        $refund = new Request(new Identity(
            'shop-a',
            'purchase-a',
            'generation-a',
            'legacy-whole-refund'
        ), 'refund', $original->capture->terms, $original->capture->identity);
        $journal->plan($refund, Status::Confirmed);
        try {
            $journal->attempt($refund);
            $this->fail('A legacy refund bypassed the pinned unit allocation.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('unit refund gateway', $error->getMessage());
        }
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM provider_effects')->fetchColumn());
    }

    public function testUnitAmountsCannotBeAddedAfterAnUnquotedCaptureHasStarted(): void
    {
        [$db, $journal, $gateway, $original] = $this->unfunded();
        $journal->plan($original->capture, Status::Confirmed);
        $journal->attempt($original->capture);
        $this->expectException(LogicException::class);
        $gateway->register($original);
    }

    public function testAQueuedRefundCanBeRefusedWithoutTakingAnotherUnresolvedCommandsUnits(): void
    {
        [$db, , $gateway, $original] = $this->funded();
        $first = new UnitRefundCommand($original, 'original-processing', [2]);
        $gateway->plan($first, Status::Processing);
        $gateway->attempt($first);
        $queued = new UnitRefundCommand($original, 'queued-and-refused', [0, 1, 2]);
        $gateway->plan($queued, Status::Confirmed);
        $this->assertSame('unknown', $gateway->attempt($queued)['status']);
        $refused = $gateway->resolve($queued, Status::Refused);
        $this->assertSame('refused', $refused['status']);
        $this->assertTrue($refused['admissionClosed']);
        $this->assertSame(
            0,
            (int) $db->query(
                'SELECT COUNT(*) FROM unit_refund_commands WHERE admitted = 1 AND closed = 1'
            )->fetchColumn()
        );
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM unit_refund_payouts')->fetchColumn());
        $this->assertSame('confirmed', $gateway->resolve($first, Status::Confirmed)['status']);
        $this->assertSame('refused', $gateway->lookup($queued)['status']);
    }

    public function testMissingCaptureEvidenceCannotPayAndLaterReconcilesTheOriginalCapture(): void
    {
        [$db, $journal, $gateway, $original] = $this->unfunded();
        $gateway->register($original);
        $command = new UnitRefundCommand($original, 'original-awaiting-capture', [2]);
        $gateway->plan($command, Status::Confirmed);
        $this->assertSame('unknown', $gateway->attempt($command)['status']);
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM unit_refund_payouts')->fetchColumn());
        $journal->plan($original->capture, Status::Confirmed);
        $journal->attempt($original->capture);
        $this->assertSame(499, $gateway->resolve($command, Status::Confirmed)['ownPayoutMinor']);
        $this->assertSame(499, (int) $db->query('SELECT SUM(amount_minor) FROM unit_refund_payouts')->fetchColumn());
    }

    public function testZeroPricedOriginalUnitsSettleWithoutInventingAnotherFinancialPayout(): void
    {
        [$db, $journal, $gateway, $initial] = $this->unfunded();
        $original = new OriginalUnitAmounts($initial->capture, [0, 1499, 0]);
        $gateway->register($original);
        $journal->plan($original->capture, Status::Confirmed);
        $journal->attempt($original->capture);
        $command = new UnitRefundCommand($original, 'zero-priced-remedy', [0, 2]);
        $gateway->plan($command, Status::Confirmed);
        $result = $gateway->attempt($command);
        $this->assertSame('confirmed', $result['status']);
        $this->assertSame([0, 2], $result['confirmedUnits']);
        $this->assertSame(0, $result['confirmedAmountMinor']);
        $this->assertNull($result['ownPayoutId']);
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM unit_refund_payouts')->fetchColumn());
    }

    public function testChangedOriginalUnitAmountsAreRefusedBeforeAnyRefundIsAdmitted(): void
    {
        [$db, , $gateway, $original] = $this->funded();
        $replacement = new OriginalUnitAmounts($original->capture, [499, 500, 500]);
        $this->assertRefused(static fn () => $gateway->register($replacement), 'allocation is unavailable or changed');
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM unit_refund_commands')->fetchColumn());
    }

    public function testAnotherClientsCredentialCannotReadOrAdmitTheOriginalUnitRefund(): void
    {
        [$db, , , $original] = $this->funded();
        $foreign = new UnitRefundGateway($db, 'shop-b');
        $command = new UnitRefundCommand($original, 'foreign-client', [2]);
        $this->assertRefused(static fn () => $foreign->lookup($command), 'another client');
        $this->assertRefused(static fn () => $foreign->attempt($command), 'another client');
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM unit_refund_commands')->fetchColumn());
    }

    public function testARefusedRowWithACommittedPayoutCannotReleaseUnitsForAnotherPayout(): void
    {
        [$db, , $gateway, $original] = $this->funded();
        $first = new UnitRefundCommand($original, 'original-paid-unit', [2]);
        $gateway->plan($first, Status::Confirmed);
        $gateway->attempt($first);
        $db->exec("UPDATE unit_refund_commands SET status = 'refused' WHERE closed = 1");
        $full = new UnitRefundCommand($original, 'cancel-after-inconsistent-refusal', [0, 1, 2]);
        $gateway->plan($full, Status::Confirmed);
        $this->assertRefused(static fn () => $gateway->attempt($full), 'inconsistent payout evidence');
        $this->assertSame(499, (int) $db->query('SELECT SUM(amount_minor) FROM unit_refund_payouts')->fetchColumn());
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM unit_refund_commands')->fetchColumn());
    }

    public function testAStoredClaimCannotBeSubstitutedForAnotherOriginalUnit(): void
    {
        [$db, , $gateway, $original] = $this->funded();
        $command = new UnitRefundCommand($original, 'original-unit-two', [2]);
        $gateway->plan($command, Status::Processing);
        $gateway->attempt($command);
        $db->exec("UPDATE unit_refund_commands SET claimed = '[0]'");
        $this->assertRefused(
            static fn () => $gateway->resolve($command, Status::Confirmed),
            'complete requested units'
        );
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM unit_refund_payouts')->fetchColumn());
    }

    public function testALostPayoutReceiptCannotBeReplacedByASecondRemedy(): void
    {
        [$db, , $gateway, $original] = $this->funded();
        $first = new UnitRefundCommand($original, 'paid-but-receipt-lost', [2]);
        $gateway->plan($first, Status::Confirmed);
        $gateway->attempt($first);
        $db->exec('DELETE FROM unit_refund_payouts');
        $next = new UnitRefundCommand($original, 'another-remedy-after-lost-receipt', [2]);
        $gateway->plan($next, Status::Confirmed);
        $this->assertRefused(static fn () => $gateway->attempt($next), 'matching payout receipt');
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM unit_refund_commands')->fetchColumn());
    }

    public function testAProviderReceiptWriteFailureRollsBackUnitOwnershipAndPayoutTogether(): void
    {
        [$db, , $gateway, $original] = $this->funded();
        $command = new UnitRefundCommand($original, 'atomic-original-refund', [2]);
        $gateway->plan($command, Status::Confirmed);
        $db->exec(
            "CREATE TRIGGER refuse_command_receipt BEFORE INSERT ON unit_refund_commands BEGIN SELECT RAISE(ABORT, 'receipt unavailable'); END"
        );
        try {
            $gateway->attempt($command);
            $this->fail('A provider payout committed without its original command receipt.');
        } catch (\PDOException $error) {
            $this->assertStringContainsString('receipt unavailable', $error->getMessage());
        }
        foreach (['unit_refund_commands', 'unit_refund_claims', 'unit_refund_payouts'] as $table) {
            $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
        }
        $db->exec('DROP TRIGGER refuse_command_receipt');
        $this->assertSame(499, $gateway->attempt($command)['ownPayoutMinor']);
    }

    public function testAnUnseenRemedyCannotReportCoveredUnitsAfterOriginalCaptureEvidenceIsLost(): void
    {
        [$db, , $gateway, $original] = $this->funded();
        $first = new UnitRefundCommand($original, 'original-covered-before-proof-loss', [2]);
        $gateway->plan($first, Status::Confirmed);
        $gateway->attempt($first);
        $db->exec('DELETE FROM provider_effects');
        $unseen = new UnitRefundCommand($original, 'unseen-after-proof-loss', [2]);
        $this->assertRefused(static fn () => $gateway->lookup($unseen), 'original capture');
        $this->assertSame(499, (int) $db->query('SELECT SUM(amount_minor) FROM unit_refund_payouts')->fetchColumn());
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM unit_refund_commands')->fetchColumn());
    }

    public function testANewProviderProcessReconcilesLostAcknowledgementWithoutReseedingOrPayingAgain(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'original-unit-refund-');
        $this->assertNotFalse($path);
        try {
            [$db, $journal, $gateway, $original] = $this->unfunded(new PDO('sqlite:' . $path));
            $gateway->register($original);
            $journal->plan($original->capture, Status::Confirmed);
            $journal->attempt($original->capture);
            $command = new UnitRefundCommand($original, 'lost-original-process', [2]);
            $gateway->plan($command, Status::Confirmed, true);
            try {
                $gateway->attempt($command);
                $this->fail('The original acknowledgement was not lost.');
            } catch (LostAcknowledgement) {
                $committed = $gateway->lookup($command);
            }
            $process = new \Symfony\Component\Process\Process([
                PHP_BINARY, 'tests/Support/UnitRefundRecoveryProcess.php', $path, $command->id(),
            ], dirname(__DIR__, 4), timeout: 30);
            $process->mustRun();
            $recovered = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            $this->assertNotSame(getmypid(), $recovered['pid']);
            $this->assertSame($committed, $recovered['observation']);
            $this->assertSame($committed, $recovered['replayed']);
            $this->assertSame(1, $recovered['payouts']);
            $this->assertSame(
                499,
                (int) $db->query('SELECT SUM(amount_minor) FROM unit_refund_payouts')
                    ->fetchColumn()
            );
        } finally {
            $gateway = $journal = $db = null;
            unlink($path);
        }
    }

    private function assertRefused(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('An unavailable or substituted original refund was accepted.');
        } catch (LogicException $error) {
            $this->assertStringContainsString($message, $error->getMessage());
        }
    }

    private function funded(): array
    {
        [$db, $journal, $gateway, $original] = $this->unfunded();
        $gateway->register($original);
        $journal->plan($original->capture, Status::Confirmed);
        $journal->attempt($original->capture);
        return [$db, $journal, $gateway, $original];
    }

    private function unfunded(?PDO $db = null): array
    {
        $db ??= new PDO('sqlite::memory:');
        $journal = new Journal($db, 'shop-a');
        $journal->install();
        $gateway = new UnitRefundGateway($db, 'shop-a');
        $gateway->install();
        $capture = new Request(new Identity('shop-a', 'purchase-a', 'generation-a', 'capture'), 'capture', [
            'amountMinor' => 1499,
            'currency' => 'USD',
        ]);
        return [$db, $journal, $gateway, new OriginalUnitAmounts($capture, [500, 500, 499])];
    }
}
