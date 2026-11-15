<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\LostAcknowledgement;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/../../../../examples/configured-state-machines/providers/bootstrap.php';

final class ProviderJournalTest extends TestCase
{
    private string $path;

    private ?PDO $db;

    private ?Journal $provider;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'provider-journal-');
        $this->db = new PDO('sqlite:' . $this->path);
        $this->provider = new Journal($this->db, 'shop-a');
        $this->provider->install();
    }

    protected function tearDown(): void
    {
        $this->provider = null;
        $this->db = null;
        unlink($this->path);
    }

    public function testMissingLookupIsUnknownAndDoesNotManufactureANonAdmissionReceipt(): void
    {
        $result = $this->provider->lookup($this->request());
        $this->assertSame(Status::Unknown, $result->status);
        $this->assertNull($result->effectId);
        $this->assertFalse($result->admissionClosed);
        $this->assertSame(0, $this->rowCount('provider_operations'));
        $this->assertSame(0, $this->rowCount('provider_effects'));
        $this->assertSame(1, $this->rowCount('provider_requests'));
    }

    public function testPlanningDoesNotDispatchOrCommitAnEffect(): void
    {
        $this->provider->plan($this->request(), Status::Confirmed);
        $this->assertSame(1, $this->rowCount('provider_plans'));
        foreach (['provider_operations', 'provider_requests', 'provider_effects'] as $table) {
            $this->assertSame(0, $this->rowCount($table));
        }
    }

    public function testSchemaInstallationIsIndependentForEveryProviderConnection(): void
    {
        $another = new PDO('sqlite::memory:');
        (new Journal($another, 'shop-b'))->install();
        $this->assertSame(4, (int) $another->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'provider_%'"
        )->fetchColumn());
        $this->assertSame(0, $this->rowCount('provider_operations'));
    }

    public function testLostAcknowledgementSurvivesAnUnrelatedBusinessRollbackAndFreshProcess(): void
    {
        $request = $this->request();
        $business = new PDO('sqlite::memory:');
        $business->exec('CREATE TABLE dw_results (value TEXT)');
        $business->beginTransaction();
        $business->exec("INSERT INTO dw_results VALUES ('not-durable')");
        $this->provider->plan($request, Status::Confirmed, loseAcknowledgement: true);
        try {
            $this->provider->attempt($request);
            $this->fail('The provider response must be lost after its separate transaction commits.');
        } catch (LostAcknowledgement) {
            $business->rollBack();
        }
        $this->assertSame(0, (int) $business->query('SELECT COUNT(*) FROM dw_results')->fetchColumn());
        $this->assertSame(1, $this->rowCount('provider_effects'));
        $fresh = new Process([
            PHP_BINARY, __DIR__ . '/../../../Fixtures/V2/provider-journal-lookup.php',
            $this->path, json_encode($request->toArray(), JSON_THROW_ON_ERROR),
        ]);
        $fresh->setTimeout(10);
        $fresh->mustRun();
        $this->assertSame([
            'status' => 'confirmed',
            'effectId' => 'effect-' . $request->identity->key(),
            'admissionClosed' => false,
        ], json_decode($fresh->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        $again = $this->provider->attempt($request);
        $this->assertSame(Status::Confirmed, $again->status);
        $this->assertSame('effect-' . $request->identity->key(), $again->effectId);
        $this->assertSame(1, $this->rowCount('provider_effects'));
        $this->assertSame(['command', 'lookup', 'command'], $this->db->query(
            'SELECT kind FROM provider_requests ORDER BY sequence'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function unresolved(): array
    {
        return [
            'processing' => [Status::Processing],
            'unknown' => [Status::Unknown],
        ];
    }

    #[DataProvider('unresolved')]
    public function testAdmissionClosurePreservesAnUnresolvedAdmittedOperationAndItsLateSuccess(Status $status): void
    {
        $request = $this->request();
        $this->provider->plan($request, $status);
        $this->assertSame($status, $this->provider->attempt($request)->status);
        $closed = $this->provider->closeAdmission($request);
        $this->assertSame($status, $closed->status);
        $this->assertTrue($closed->admissionClosed);
        $this->assertNull($closed->effectId);
        $late = $this->provider->resolve($request, Status::Confirmed);
        $this->assertTrue($late->admissionClosed);
        $this->assertSame(Status::Confirmed, $late->status);
        $this->assertEquals($late, $this->provider->lookup($request));
        $this->provider->attempt($request);
        $this->assertSame(1, $this->rowCount('provider_effects'));
        $this->assertSame(1, $this->rowCount('provider_operations'));
    }

    public function testPermanentNonAdmissionRefusesAReopenedPlanAndLateSuccess(): void
    {
        $request = $this->request();
        $closed = $this->provider->closeAdmission($request);
        $this->assertSame(Status::NotExecuted, $closed->status);
        $this->assertTrue($closed->admissionClosed);
        $this->assertNull($closed->effectId);
        $this->assertEquals($closed, $this->provider->attempt($request));
        foreach ([
            fn () => $this->provider->plan($request, Status::Confirmed),
            fn () => $this->provider->resolve($request, Status::Confirmed),
        ] as $reopen) {
            try {
                $reopen();
                $this->fail('An original closed non-admission cannot become a new provider effect.');
            } catch (LogicException) {
                $this->assertSame(0, $this->rowCount('provider_effects'));
            }
        }
    }

    public function testDefinitiveNonExecutionCanBeRetriedOnlyByAnExplicitNewProviderPlan(): void
    {
        $request = $this->request();
        $this->provider->plan($request, Status::NotExecuted);
        $this->assertSame(Status::NotExecuted, $this->provider->attempt($request)->status);
        $this->assertSame(0, $this->rowCount('provider_effects'));
        $this->provider->plan($request, Status::Confirmed);
        $this->assertSame(Status::Confirmed, $this->provider->attempt($request)->status);
        $this->assertSame(1, $this->rowCount('provider_effects'));
        $this->assertSame(2, $this->rowCount('provider_requests'));
    }

    public function testRefusalDoesNotBecomeConfirmedSettlementOrPermitABlindRetry(): void
    {
        $request = $this->request();
        $this->provider->plan($request, Status::Refused);
        $this->assertSame(Status::Refused, $this->provider->attempt($request)->status);
        $this->assertSame(Status::Refused, $this->provider->closeAdmission($request)->status);
        $this->assertSame(0, $this->rowCount('provider_effects'));
        $this->expectException(LogicException::class);
        $this->provider->plan($request, Status::Confirmed);
    }

    public function testChangedCommandTermsCannotReuseTheOriginalOperationOrItsIdempotencyReceipt(): void
    {
        $original = $this->request();
        $this->provider->plan($original, Status::Confirmed);
        $this->provider->attempt($original);
        $changed = new Request($original->identity, 'capture', [
            'amountMinor' => 101,
            'currency' => 'USD',
        ]);
        foreach ([
            fn () => $this->provider->attempt($changed),
            fn () => $this->provider->lookup($changed),
            fn () => $this->provider->closeAdmission($changed),
        ] as $substitute) {
            try {
                $substitute();
                $this->fail('Changed terms must not reinterpret the original committed effect.');
            } catch (LogicException $error) {
                $this->assertSame('The original provider command terms were substituted.', $error->getMessage());
            }
        }
        $this->assertSame(1, $this->rowCount('provider_requests'));
        $this->assertSame(1, $this->rowCount('provider_effects'));
    }

    public function testAConflictingPlanRollsBackWithoutChangingTheOriginalPlan(): void
    {
        $original = $this->request();
        $this->provider->plan($original, Status::Confirmed);
        try {
            $this->provider->plan(new Request($original->identity, 'book', []), Status::Refused);
            $this->fail('Another command cannot reuse the same planned identity.');
        } catch (LogicException) {
            $this->assertSame(Status::Confirmed, $this->provider->attempt($original)->status);
        }
    }

    public function testAnotherClientIsRefusedWithoutTouchingTheProviderJournal(): void
    {
        $other = new Journal($this->db, 'shop-b');
        try {
            $other->lookup($this->request());
            $this->fail('A foreign provider credential cannot observe another client.');
        } catch (LogicException $error) {
            $this->assertSame('The provider credential belongs to another client.', $error->getMessage());
        }
        $this->assertSame(0, $this->rowCount('provider_requests'));
    }

    public function testRefundWaitsForTheOriginalCaptureAndPreservesItsHistoricalFact(): void
    {
        $capture = $this->request();
        $refund = new Request(new Identity('shop-a', 'order-1', 'g1', 'refund-1'), 'refund', [
            'amountMinor' => 100,
            'currency' => 'USD',
        ], $capture->identity);
        $this->provider->plan($refund, Status::Confirmed);
        $this->assertSame(Status::Unknown, $this->provider->attempt($refund)->status);
        $this->assertSame(0, $this->rowCount('provider_effects'));
        try {
            $this->provider->resolve($refund, Status::Confirmed);
            $this->fail('A refund cannot settle an unconfirmed capture.');
        } catch (LogicException) {
            $this->assertSame(0, $this->rowCount('provider_effects'));
        }
        $this->provider->plan($capture, Status::Confirmed);
        $captured = $this->provider->attempt($capture);
        $this->assertSame(Status::Confirmed, $this->provider->resolve($refund, Status::Confirmed)->status);
        $this->assertEquals($captured, $this->provider->lookup($capture));
        $this->assertSame(2, $this->rowCount('provider_effects'));
        $this->assertSame(2, $this->rowCount('provider_operations'));
    }

    public function testRefundCannotTargetABookingUnderTheSameOwner(): void
    {
        $booking = new Request(new Identity('shop-a', 'order-1', 'g1', 'booking-1'), 'book', []);
        $this->provider->plan($booking, Status::Confirmed);
        $this->provider->attempt($booking);
        $refund = new Request(new Identity('shop-a', 'order-1', 'g1', 'refund-1'), 'refund', [], $booking->identity);
        $this->provider->plan($refund, Status::Confirmed);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Compensation targets another original provider action.');
        $this->provider->attempt($refund);
    }

    public static function otherOwners(): array
    {
        return [['shop-b', 'order-1', 'g1'], ['shop-a', 'order-2', 'g1'], ['shop-a', 'order-1', 'g2']];
    }

    #[DataProvider('otherOwners')]
    public function testCompensationCannotSubstituteAClientSubjectOrGeneration(
        string $client,
        string $subject,
        string $generation,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new Request(new Identity('shop-a', 'order-1', 'g1', 'refund-1'), 'refund', [], new Identity(
            $client,
            $subject,
            $generation,
            'capture-1'
        ));
    }

    public function testPortableTermsAreSnapshotsAndCanonicalFingerprintsIgnoreMapOrder(): void
    {
        $amount = 100;
        $terms = [
            'amountMinor' => &$amount,
            'currency' => 'USD',
        ];
        $request = new Request($this->request()->identity, 'capture', $terms);
        $amount = 200;
        $this->assertSame(100, $request->terms['amountMinor']);
        $this->assertSame($this->request()->fingerprint(), $request->fingerprint());
        $this->assertSame($request->fingerprint(), (new Request($request->identity, 'capture', [
            'currency' => 'USD',
            'amountMinor' => 100,
        ]))->fingerprint());
    }

    public function testProviderCannotBorrowAnExistingCallerTransaction(): void
    {
        $this->db->beginTransaction();
        try {
            $this->provider->closeAdmission($this->request());
            $this->fail('The provider must own its transaction.');
        } catch (LogicException $error) {
            $this->assertSame('A caller transaction cannot contain the provider transaction.', $error->getMessage());
        } finally {
            $this->db->rollBack();
        }
        $this->assertSame(0, $this->rowCount('provider_operations'));
    }

    private function request(): Request
    {
        return new Request(new Identity('shop-a', 'order-1', 'g1', 'capture-1'), 'capture', [
            'amountMinor' => 100,
            'currency' => 'USD',
        ]);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
