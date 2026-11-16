<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AuthorizationTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\FinancialDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

final class FinancialSnapshotTest extends TestCase
{
    private PDO $db;

    private Journal $provider;

    private mixed $now = 1000;

    private int $clockReads = 0;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->provider = new Journal($this->db, 'shop-a', function (): mixed {
            ++$this->clockReads;
            return $this->now;
        });
        $this->provider->install();
        $this->provider->installAuthorizationSupport();
    }

    public function testInspectionDoesNotManufactureAdmissionOrEffectsFromMissingLookups(): void
    {
        $descriptor = $this->descriptor();
        $snapshot = $this->provider->inspectFinancial($descriptor);
        $this->assertSame(Status::Unknown, $snapshot->authorization->status);
        $this->assertSame(Status::Unknown, $snapshot->capture->status);
        $this->assertFalse($snapshot->capture->admissionClosed);
        $this->assertFalse($snapshot->captureInFlight);
        $this->assertFalse($snapshot->captureAdmissionClosed);
        $this->assertFalse($snapshot->facts()['authorization_confirmed']);
        $this->assertFalse($snapshot->facts()['capture_confirmed']);
        $this->assertSame(0, $this->rows('provider_operations'));
        $this->assertSame(0, $this->rows('provider_authorization_links'));
        $this->assertSame(0, $this->rows('provider_effects'));
        $this->assertSame(['financial-inspection'], $this->db->query(
            'SELECT kind FROM provider_requests ORDER BY sequence'
        )->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame($descriptor->fingerprint(), $snapshot->toArray()['descriptorFingerprint']);
    }

    public function testExpiryChangesPermissionWithoutErasingOriginalAuthorization(): void
    {
        $descriptor = $this->descriptor();
        $this->confirm($descriptor, 'authorize');
        $before = $this->provider->inspectFinancial($descriptor);
        $this->assertTrue($before->facts()['authorization_active']);
        $this->assertFalse($before->facts()['authorization_expired']);
        $this->assertFalse($before->captureInFlight);
        $this->now = 1001;
        $after = $this->provider->inspectFinancial($descriptor);
        $this->assertSame(1001, $after->observedAt);
        $this->assertSame(1001, $after->toArray()['originalTerms']['expiresAt']);
        $this->assertTrue($after->facts()['authorization_confirmed']);
        $this->assertTrue($after->facts()['authorization_expired']);
        $this->assertFalse($after->facts()['authorization_active']);
        $this->assertFalse($after->facts()['capture_confirmed']);
        $this->assertSame(1, $this->rows('provider_effects'));
    }

    public function testOneClockObservationBindsEachSnapshot(): void
    {
        $this->confirm($this->descriptor(), 'authorize');
        $reads = $this->clockReads;
        $snapshot = $this->provider->inspectFinancial($this->descriptor());
        $this->assertSame($reads + 1, $this->clockReads);
        $snapshot->facts();
        $snapshot->toArray();
        $this->assertSame($reads + 1, $this->clockReads);
    }

    public function testAdmittedUncertainCaptureAndItsLateSuccessRemainDistinctFromMissingAdmission(): void
    {
        $descriptor = $this->descriptor();
        $this->confirm($descriptor, 'authorize');
        $this->provider->plan($descriptor->capture, Status::Unknown);
        $this->provider->attempt($descriptor->capture);
        $during = $this->provider->inspectFinancial($descriptor);
        $this->assertTrue($during->captureInFlight);
        $this->assertFalse($during->facts()['authorization_active']);
        $this->assertFalse($during->facts()['capture_confirmed']);
        $this->now = 2000;
        $this->provider->resolve($descriptor->capture, Status::Confirmed);
        $after = $this->provider->inspectFinancial($descriptor);
        $this->assertFalse($after->captureInFlight);
        $this->assertTrue($after->facts()['authorization_expired']);
        $this->assertTrue($after->facts()['authorization_confirmed']);
        $this->assertTrue($after->facts()['capture_confirmed']);
        $this->assertSame(2, $this->rows('provider_effects'));
    }

    public function testPendingVoidFencesCaptureWithoutInventingAVoidConfirmation(): void
    {
        $descriptor = $this->descriptor();
        $this->confirm($descriptor, 'authorize');
        $this->provider->plan($descriptor->void, Status::Processing);
        $this->provider->attempt($descriptor->void);
        $snapshot = $this->provider->inspectFinancial($descriptor);
        $this->assertTrue($snapshot->captureAdmissionClosed);
        $this->assertFalse($snapshot->authorization->admissionClosed);
        $this->assertFalse($snapshot->facts()['authorization_active']);
        $this->assertFalse($snapshot->facts()['void_confirmed']);
        $this->provider->plan($descriptor->capture, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($descriptor->capture)->status);
        $this->assertSame(1, $this->rows('provider_effects'));
    }

    public function testRefundKeepsTheOriginalCaptureAsAHistoricalFact(): void
    {
        $descriptor = $this->descriptor();
        foreach (['authorize', 'capture', 'refund'] as $key) {
            $this->confirm($descriptor, $key);
        }
        $snapshot = $this->provider->inspectFinancial($descriptor);
        $this->assertTrue($snapshot->facts()['capture_confirmed']);
        $this->assertTrue($snapshot->facts()['refund_confirmed']);
        $this->assertFalse($snapshot->facts()['authorization_active']);
        $this->assertSame(3, $this->rows('provider_effects'));
    }

    public function testAnotherCaptureAliasCannotMakeTheOriginalAdmittedCaptureDisappear(): void
    {
        $descriptor = $this->descriptor();
        $this->confirm($descriptor, 'authorize');
        $this->provider->plan($descriptor->capture, Status::Unknown);
        $this->provider->attempt($descriptor->capture);
        $value = $descriptor->toArray();
        $value['capture']['identity']['operation'] = 'capture-alias';
        $value['refund']['target']['operation'] = 'capture-alias';
        $alias = FinancialDescriptor::fromArray($value);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Financial inspection cannot substitute another original operation.');
        $this->provider->inspectFinancial($alias);
    }

    public function testAnotherClientCannotInspectBeforeAnyProviderStateIsTouched(): void
    {
        $other = $this->descriptor('shop-b');
        try {
            $this->provider->inspectFinancial($other);
            $this->fail('The provider credential cannot inspect another client.');
        } catch (LogicException) {
            $this->assertSame(0, $this->rows('provider_requests'));
            $this->assertSame(0, $this->rows('provider_operations'));
        }
    }

    public static function invalidTimes(): array
    {
        return [[-1], [null], ['1000'], [1.5], [INF]];
    }

    #[DataProvider('invalidTimes')]
    public function testInspectionCannotCoerceOrReplaceAnInvalidProviderClock(mixed $time): void
    {
        $this->now = $time;
        try {
            $this->provider->inspectFinancial($this->descriptor());
            $this->fail('An invalid clock cannot produce a financial permission.');
        } catch (LogicException) {
            $this->assertSame(0, $this->rows('provider_requests'));
            $this->assertSame(0, $this->rows('provider_effects'));
        }
    }

    public function testMissingAdmissionCannotRemainAPositiveCapturedMoneyFact(): void
    {
        $descriptor = $this->descriptor();
        $this->confirm($descriptor, 'authorize');
        $this->confirm($descriptor, 'capture');
        $this->db->exec("DELETE FROM provider_authorization_links WHERE kind = 'capture'");
        $this->expectException(LogicException::class);
        $this->provider->inspectFinancial($descriptor);
    }

    public function testMissingCaptureEffectCannotReopenPermissionOrImplyThatNothingWasCaptured(): void
    {
        $descriptor = $this->descriptor();
        $this->confirm($descriptor, 'authorize');
        $this->confirm($descriptor, 'capture');
        $query = $this->db->prepare('DELETE FROM provider_effects WHERE operation_id = ?');
        $query->execute([$descriptor->capture->identity->key()]);
        $snapshot = $this->provider->inspectFinancial($descriptor);
        $this->assertSame(Status::Confirmed, $snapshot->capture->status);
        $this->assertNull($snapshot->capture->effectId);
        $this->assertFalse($snapshot->facts()['financial_observation_consistent']);
        $this->assertFalse($snapshot->facts()['authorization_active']);
        $this->assertFalse($snapshot->facts()['authorization_admissible']);
        $this->assertFalse($snapshot->facts()['capture_confirmed']);
    }

    private function descriptor(string $client = 'shop-a'): FinancialDescriptor
    {
        $terms = new OrderTerms(
            $client,
            'purchase-1',
            'g1',
            1499,
            'USD',
            'sku-1',
            2,
            new AuthorizationTerms(1499, 'USD', 1001)
        );
        return FinancialDescriptor::fromArray($terms->financialDescriptor());
    }

    private function confirm(FinancialDescriptor $descriptor, string $key): void
    {
        $request = $descriptor->requests()[$key];
        $this->provider->plan($request, Status::Confirmed);
        $this->assertSame(Status::Confirmed, $this->provider->attempt($request)->status);
    }

    private function rows(string $table): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
