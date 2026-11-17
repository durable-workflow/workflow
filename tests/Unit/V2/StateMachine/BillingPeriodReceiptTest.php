<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\LostAcknowledgement;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\TimedObservation;
use DurableWorkflow\Examples\ProviderStateMachines\BillingPeriod;
use DurableWorkflow\Examples\ProviderStateMachines\BillingPeriodPolicy;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

final class BillingPeriodReceiptTest extends TestCase
{
    public function testLostAcknowledgementRecoveredAfterGraceUsesTheOriginalCommitTime(): void
    {
        $period = $this->period();
        $charge = Request::fromArray($period->paymentDescriptor()['forward']);
        $now = $period->paymentDeadline - 1;
        $db = new PDO('sqlite::memory:');
        $provider = $this->provider($db, $now);
        $provider->install();
        $provider->plan($charge, Status::Confirmed, true);
        try {
            $provider->attempt($charge);
            $this->fail('The provider effect commits before losing its acknowledgement.');
        } catch (LostAcknowledgement) {
            $this->assertSame(1, $this->providerRows($db, 'provider_effects'));
        }
        $now = $period->paymentDeadline + 100;
        $replacement = $this->provider($db, $now);
        $receipt = $replacement->timedLookup($charge);
        $this->assertSame($period->paymentDeadline - 1, $receipt->confirmedAt);
        $this->assertTrue(BillingPeriodPolicy::access($period, $now, $receipt->confirmedAt)['access']);
        $replacement->attempt($charge);
        $replacement->closeAdmission($charge);
        $closed = $replacement->timedLookup($charge);
        $this->assertSame($receipt->confirmedAt, $closed->confirmedAt);
        $this->assertSame($receipt->observation->effectId, $closed->observation->effectId);
        $this->assertTrue($closed->observation->admissionClosed);
        $this->assertSame(1, $this->providerRows($db, 'provider_effects'));
        $this->assertSame(1, $this->providerRows($db, 'provider_effect_times'));
    }

    public function testACommitAtTheDeadlineIsAuthoritativelyLateAndDoesNotReactivateService(): void
    {
        $period = $this->period();
        $now = $period->paymentDeadline;
        $db = new PDO('sqlite::memory:');
        $provider = $this->provider($db, $now);
        $provider->install();
        $charge = Request::fromArray($period->paymentDescriptor()['forward']);
        $provider->plan($charge, Status::Processing);
        $provider->attempt($charge);
        $this->assertNull($provider->timedLookup($charge)->confirmedAt);
        $provider->resolve($charge, Status::Confirmed);
        $receipt = $provider->timedLookup($charge);
        $this->assertSame($now, $receipt->confirmedAt);
        $this->assertFalse(BillingPeriodPolicy::access($period, $now, $receipt->confirmedAt)['access']);
        $this->assertTrue(BillingPeriodPolicy::access($period, $now, $receipt->confirmedAt)['latePayment']);
    }

    public function testSupportedEmptyLookupDoesNotInventAPaymentOrCloseAdmission(): void
    {
        $now = 100;
        $db = new PDO('sqlite::memory:');
        $provider = $this->provider($db, $now);
        $provider->install();
        $charge = Request::fromArray($this->period()->paymentDescriptor()['forward']);
        $receipt = $provider->timedLookup($charge);
        $this->assertSame(Status::Unknown, $receipt->observation->status);
        $this->assertNull($receipt->confirmedAt);
        $this->assertFalse($receipt->observation->admissionClosed);
        $this->assertSame(0, $this->providerRows($db, 'provider_operations'));
        $this->assertSame(0, $this->providerRows($db, 'provider_effect_times'));
    }

    public function testReopeningTheIndependentProviderFilePreservesTheOriginalReceiptWithoutBackfill(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'period-receipt-');
        try {
            $charge = Request::fromArray($this->period()->paymentDescriptor()['forward']);
            $now = 200;
            $db = new PDO('sqlite:' . $path);
            $provider = $this->provider($db, $now);
            $provider->install();
            $provider->plan($charge, Status::Confirmed);
            $provider->attempt($charge);
            unset($provider, $db);
            $now = 999999;
            $db = new PDO('sqlite:' . $path);
            $replacement = $this->provider($db, $now);
            $receipt = $replacement->timedLookup($charge);
            $this->assertSame(200, $receipt->confirmedAt);
            $this->assertSame(1, $this->providerRows($db, 'provider_effects'));
            $this->assertSame(1, $this->providerRows($db, 'provider_effect_times'));
        } finally {
            unset($provider, $replacement, $db);
            unlink($path);
        }
    }

    public function testLegacyProviderSchemaAndObservationRemainUnchangedAndTimingIsExplicitlyUnavailable(): void
    {
        $db = new PDO('sqlite::memory:');
        $provider = new Journal($db, 'shop-a');
        $provider->install();
        $this->assertSame(
            4,
            (int) $db->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'provider_%'"
            )->fetchColumn()
        );
        $charge = Request::fromArray($this->period()->paymentDescriptor()['forward']);
        $provider->plan($charge, Status::Confirmed);
        $this->assertSame(Status::Confirmed, $provider->attempt($charge)->status);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The provider did not admit original effect timestamps.');
        $provider->timedLookup($charge);
    }

    public function testMissingTimestampCannotBeBackfilledAtRecoveryTime(): void
    {
        $now = 100;
        $db = new PDO('sqlite::memory:');
        $provider = $this->provider($db, $now);
        $provider->install();
        $charge = Request::fromArray($this->period()->paymentDescriptor()['forward']);
        $provider->plan($charge, Status::Confirmed);
        $provider->attempt($charge);
        $db->exec('DELETE FROM provider_effect_times');
        $now = 999999;
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The original provider commit timestamp is missing or substituted.');
        $provider->timedLookup($charge);
    }

    public function testSelectingTimingOnALegacyDatabaseCannotPretendAnEmptyLookupIsSupported(): void
    {
        $db = new PDO('sqlite::memory:');
        (new Journal($db, 'shop-a'))->install();
        $now = 100;
        $replacement = $this->provider($db, $now);
        $charge = Request::fromArray($this->period()->paymentDescriptor()['forward']);
        $this->expectException(\PDOException::class);
        $replacement->timedLookup($charge);
    }

    public function testOriginalCaptureAndRefundHaveSeparateImmutableCommitTimes(): void
    {
        $period = $this->period();
        $descriptor = $period->paymentDescriptor();
        $capture = Request::fromArray($descriptor['forward']);
        $refund = Request::fromArray($descriptor['compensation']);
        $now = 200;
        $db = new PDO('sqlite::memory:');
        $provider = $this->provider($db, $now);
        $provider->install();
        $provider->plan($capture, Status::Confirmed);
        $provider->attempt($capture);
        $now = 300;
        $provider->plan($refund, Status::Confirmed);
        $provider->attempt($refund);
        $this->assertSame(200, $provider->timedLookup($capture)->confirmedAt);
        $this->assertSame(300, $provider->timedLookup($refund)->confirmedAt);
        $this->assertSame(2, $this->providerRows($db, 'provider_effects'));
        $this->assertSame(2, $this->providerRows($db, 'provider_effect_times'));
    }

    public function testAnInvalidProviderClockRollsBackTheEffectAndReceiptTogether(): void
    {
        $now = 0;
        $db = new PDO('sqlite::memory:');
        $provider = $this->provider($db, $now);
        $provider->install();
        $charge = Request::fromArray($this->period()->paymentDescriptor()['forward']);
        $provider->plan($charge, Status::Confirmed);
        try {
            $provider->attempt($charge);
            $this->fail('A missing provider commit time cannot produce an effect.');
        } catch (LogicException $error) {
            $this->assertSame('The original provider commit time must be a positive integer.', $error->getMessage());
        }
        $this->assertFalse($db->inTransaction());
        foreach (['provider_operations', 'provider_effects', 'provider_effect_times'] as $table) {
            $this->assertSame(0, $this->providerRows($db, $table));
        }
    }

    public function testAReceiptFromAnotherPeriodCannotSatisfyThisPeriod(): void
    {
        $period = $this->period();
        $value = $period->toArray();
        $value['period'] = '2026-12';
        $other = BillingPeriod::fromArray($value);
        $now = 200;
        $db = new PDO('sqlite::memory:');
        $provider = $this->provider($db, $now);
        $provider->install();
        $charge = Request::fromArray($other->paymentDescriptor()['forward']);
        $provider->plan($charge, Status::Confirmed);
        $provider->attempt($charge);
        $receipt = $provider->timedLookup($charge)
            ->toArray();
        $this->expectException(InvalidArgumentException::class);
        TimedObservation::fromArray($receipt, Request::fromArray($period->paymentDescriptor()['forward']));
    }

    public static function substitutions(): array
    {
        return [
            'text time' => ['confirmedAt', '200'],
            'missing time' => ['confirmedAt', null],
            'different effect' => ['effectId', 'effect-foreign'],
            'claimed instead of committed' => ['status', 'processing'],
            'untyped admission' => ['admissionClosed', 0],
            'unknown extension' => ['lookupAt', 200],
        ];
    }

    #[DataProvider('substitutions')]
    public function testRecordedReceiptRequiresClosedTypedOriginalEvidence(string $field, mixed $replacement): void
    {
        $now = 200;
        $db = new PDO('sqlite::memory:');
        $provider = $this->provider($db, $now);
        $provider->install();
        $charge = Request::fromArray($this->period()->paymentDescriptor()['forward']);
        $provider->plan($charge, Status::Confirmed);
        $provider->attempt($charge);
        $receipt = $provider->timedLookup($charge)
            ->toArray();
        $this->assertSame($receipt, TimedObservation::fromArray($receipt, $charge)->toArray());
        $receipt[$field] = $replacement;
        $this->expectException(InvalidArgumentException::class);
        TimedObservation::fromArray($receipt, $charge);
    }

    private function period(): BillingPeriod
    {
        return new BillingPeriod(
            'shop-a',
            'service-subscription',
            'subscription-generation-1',
            '2026-11',
            1499,
            'USD',
            100,
            3000100,
            BillingPeriod::graceDeadline(100),
            BillingPeriod::GRACE,
            true
        );
    }

    private function provider(PDO $db, int &$now): Journal
    {
        return new Journal($db, 'shop-a', static function () use (&$now): int {
            return $now;
        }, captureEffectTimes: true);
    }

    private function providerRows(PDO $db, string $table): int
    {
        return (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
