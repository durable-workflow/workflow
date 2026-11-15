<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AuthorizationTerms;
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

final class ProviderAuthorizationTest extends TestCase
{
    private string $path;

    private PDO $db;

    private Journal $provider;

    private int $now = 1000;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'provider-authorization-');
        $this->db = new PDO('sqlite:' . $this->path);
        $this->provider = new Journal($this->db, 'shop-a', fn (): int => $this->now);
        $this->provider->install();
        $this->provider->installAuthorizationSupport();
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    public function testAuthorizationIsAnOriginalPermissionAndDoesNotCaptureMoney(): void
    {
        $authorization = $this->request('authorize');
        $this->confirm($authorization);
        $this->assertSame(['authorize'], $this->actionsWithEffects());
        $this->assertSame(
            [
                'amountMinor' => 1375,
                'currency' => 'USD',
                'expiresAt' => 1001,
            ],
            AuthorizationTerms::fromArray($authorization->terms)->toArray()
        );
        $this->assertSame(Status::Unknown, $this->provider->lookup($this->request('capture-authorized'))->status);
    }

    public function testCaptureBeforeExpiryAndRefundKeepAllOriginalFinancialFacts(): void
    {
        $authorization = $this->request('authorize');
        $this->confirm($authorization);
        $capture = $this->request('capture-authorized');
        $this->confirm($capture);
        $this->confirm($this->request('refund-authorized'));
        $this->assertSame(['authorize', 'capture-authorized', 'refund-authorized'], $this->actionsWithEffects());
        $this->assertSame(Status::Confirmed, $this->provider->lookup($authorization)->status);
        $this->assertSame(Status::Confirmed, $this->provider->lookup($capture)->status);
        $this->provider->attempt($capture);
        $this->provider->attempt($this->request('refund-authorized'));
        $this->assertCount(3, $this->actionsWithEffects());
        $this->provider->plan($this->request('void-authorization'), Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($this->request('void-authorization'))->status);
        $this->assertCount(3, $this->actionsWithEffects());
    }

    public static function expired(): array
    {
        return [[1001], [1002], [5000]];
    }

    #[DataProvider('expired')]
    public function testExpiryCannotBecomeANewCaptureAdmission(int $time): void
    {
        $this->confirm($this->request('authorize'));
        $this->now = $time;
        $capture = $this->request('capture-authorized');
        $this->provider->plan($capture, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($capture)->status);
        $this->assertNull($this->provider->lookup($capture)->effectId);
        $this->assertSame(['authorize'], $this->actionsWithEffects());
        $this->confirm($this->request('void-authorization'));
        $this->assertSame(['authorize', 'void-authorization'], $this->actionsWithEffects());
    }

    public function testVoidWinsAdmissionRaceAndPermanentlyFencesTheOriginalCapture(): void
    {
        $this->confirm($this->request('authorize'));
        $this->confirm($this->request('void-authorization'));
        $fresh = new Journal(new PDO('sqlite:' . $this->path), 'shop-a', static fn (): int => 1000);
        $capture = $this->request('capture-authorized');
        $fresh->plan($capture, Status::Confirmed);
        $this->assertSame(Status::Refused, $fresh->attempt($capture)->status);
        $this->assertSame(['authorize', 'void-authorization'], $this->actionsWithEffects());
        $this->expectException(LogicException::class);
        $fresh->resolve($capture, Status::Confirmed);
    }

    public function testUncertainCaptureWinsAdmissionButCancellationWaitsForItsActualOutcome(): void
    {
        $this->confirm($this->request('authorize'));
        $capture = $this->request('capture-authorized');
        $this->provider->plan($capture, Status::Unknown);
        $this->assertSame(Status::Unknown, $this->provider->attempt($capture)->status);
        $this->provider->closeAdmission($capture);
        $void = $this->request('void-authorization');
        $this->provider->plan($void, Status::Confirmed);
        $this->assertSame(Status::Unknown, $this->provider->attempt($void)->status);
        try {
            $this->provider->resolve($void, Status::Confirmed);
            $this->fail('A void cannot settle an admitted unknown capture.');
        } catch (LogicException) {
            $this->assertSame(['authorize'], $this->actionsWithEffects());
        }
        $fresh = new Process([PHP_BINARY, __DIR__ . '/../../../Fixtures/V2/provider-authorization-resolve.php',
            $this->path, json_encode($capture->toArray(), JSON_THROW_ON_ERROR), 'confirmed', '2000']);
        $fresh->setTimeout(10);
        $fresh->mustRun();
        $result = json_decode($fresh->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([
            'status' => 'confirmed',
            'admissionClosed' => true,
        ], $result);
        $this->assertSame(Status::Confirmed, $this->provider->lookup($capture)->status);
        $this->provider->resolve($void, Status::Refused);
        $this->confirm($this->request('refund-authorized'));
        $this->assertSame(['authorize', 'capture-authorized', 'refund-authorized'], $this->actionsWithEffects());
    }

    public function testDefinitivelyUnexecutedCaptureAllowsThePendingOriginalVoid(): void
    {
        $this->confirm($this->request('authorize'));
        $capture = $this->request('capture-authorized');
        $this->provider->plan($capture, Status::Processing);
        $this->provider->attempt($capture);
        $void = $this->request('void-authorization');
        $this->provider->plan($void, Status::Confirmed);
        $this->assertSame(Status::Unknown, $this->provider->attempt($void)->status);
        $this->provider->resolve($capture, Status::NotExecuted);
        $this->assertSame(Status::Confirmed, $this->provider->resolve($void, Status::Confirmed)->status);
        $this->provider->plan($capture, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($capture)->status);
        $this->assertSame(['authorize', 'void-authorization'], $this->actionsWithEffects());
    }

    public function testAProvenNonExecutionDoesNotResetTheOriginalExpiryOnRetry(): void
    {
        $this->confirm($this->request('authorize'));
        $capture = $this->request('capture-authorized');
        $this->provider->plan($capture, Status::NotExecuted);
        $this->assertSame(Status::NotExecuted, $this->provider->attempt($capture)->status);
        $this->now = 1001;
        $this->provider->plan($capture, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($capture)->status);
        $this->assertSame(['authorize'], $this->actionsWithEffects());
    }

    public function testAnUnknownAuthorizationDoesNotAuthorizeCaptureOrVanishDuringCancellation(): void
    {
        $authorization = $this->request('authorize');
        $this->provider->plan($authorization, Status::Unknown);
        $this->provider->attempt($authorization);
        $capture = $this->request('capture-authorized');
        $this->provider->plan($capture, Status::Confirmed);
        $this->assertSame(Status::Refused, $this->provider->attempt($capture)->status);
        $this->assertSame(Status::Unknown, $this->provider->lookup($authorization)->status);
        $void = $this->request('void-authorization');
        $this->provider->plan($void, Status::Confirmed);
        $this->assertSame(Status::Unknown, $this->provider->attempt($void)->status);
        $this->assertSame([], $this->actionsWithEffects());
        $this->provider->closeAdmission($authorization);
        $this->now = 2000;
        $this->provider->resolve($authorization, Status::Confirmed);
        $this->assertSame(Status::Confirmed, $this->provider->resolve($void, Status::Confirmed)->status);
        $this->assertSame(['authorize', 'void-authorization'], $this->actionsWithEffects());
    }

    public static function invalidTerms(): array
    {
        $valid = [
            'amountMinor' => 1375,
            'currency' => 'USD',
            'expiresAt' => 1001,
        ];
        return [
            [
                array_replace($valid, [
                    'amountMinor' => 0,
                ])],
            [
                array_replace($valid, [
                    'amountMinor' => -1,
                ])],
            [
                array_replace($valid, [
                    'amountMinor' => '1375',
                ])],
            [
                array_replace($valid, [
                    'amountMinor' => 13.75,
                ])],
            [
                array_replace($valid, [
                    'currency' => 'usd',
                ])],
            [
                array_replace($valid, [
                    'currency' => 'US',
                ])],
            [
                array_replace($valid, [
                    'expiresAt' => 0,
                ])],
            [
                array_replace($valid, [
                    'expiresAt' => '1001',
                ])],
            [
                array_replace($valid, [
                    'expiresAt' => INF,
                ])],
            [
                $valid + [
                    'extendOnRetry' => true,
                ]],
            [[
                'amountMinor' => 1375,
                'currency' => 'USD',
            ]],
        ];
    }

    #[DataProvider('invalidTerms')]
    public function testAuthorizationCannotCoerceOrAcquireMutableTerms(array $terms): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Request($this->id('authorize'), 'authorize', $terms);
    }

    public static function damagedAdmissions(): array
    {
        return [[null], ['not-a-time'], [-1], [1001], [2000]];
    }

    #[DataProvider('damagedAdmissions')]
    public function testLateConfirmationCannotUseAMissingMalformedOrExpiredCaptureAdmission(mixed $time): void
    {
        $this->confirm($this->request('authorize'));
        $capture = $this->request('capture-authorized');
        $this->provider->plan($capture, Status::Unknown);
        $this->provider->attempt($capture);
        $query = $this->db->prepare('UPDATE provider_authorization_links SET admitted_at = ? WHERE kind = ?');
        $query->execute([$time, 'capture']);
        try {
            $this->provider->resolve($capture, Status::Confirmed);
            $this->fail('A damaged admission cannot authorize a late capture effect.');
        } catch (LogicException) {
            $this->assertSame(Status::Unknown, $this->provider->lookup($capture)->status);
            $this->assertSame(['authorize'], $this->actionsWithEffects());
        }
    }

    public function testLostCaptureAcknowledgementReconcilesOneEffectAfterExpiry(): void
    {
        $this->confirm($this->request('authorize'));
        $capture = $this->request('capture-authorized');
        $this->provider->plan($capture, Status::Confirmed, loseAcknowledgement: true);
        try {
            $this->provider->attempt($capture);
            $this->fail('The provider must commit before losing its acknowledgement.');
        } catch (LostAcknowledgement) {
            $this->now = 2000;
        }
        $this->assertSame(Status::Confirmed, $this->provider->attempt($capture)->status);
        $this->assertSame(['authorize', 'capture-authorized'], $this->actionsWithEffects());
    }

    public static function changedTerms(): array
    {
        return [
            [[
                'amountMinor' => 1376,
                'currency' => 'USD',
                'expiresAt' => 1001,
            ]],
            [[
                'amountMinor' => 1375,
                'currency' => 'EUR',
                'expiresAt' => 1001,
            ]],
            [[
                'amountMinor' => 1375,
                'currency' => 'USD',
                'expiresAt' => 2000,
            ]],
        ];
    }

    #[DataProvider('changedTerms')]
    public function testCaptureCannotSubstituteOriginalMoneyOrExtendTheAuthorization(array $terms): void
    {
        $this->confirm($this->request('authorize'));
        $capture = new Request($this->id('capture-authorized'), 'capture-authorized', $terms, $this->id('authorize'));
        $this->provider->plan($capture, Status::Confirmed);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The financial command changes the original amount, currency or expiry.');
        $this->provider->attempt($capture);
    }

    public function testAnotherCaptureKeyCannotSpendTheSameAuthorizationTwice(): void
    {
        $this->confirm($this->request('authorize'));
        $this->confirm($this->request('capture-authorized'));
        $other = new Request($this->id('capture-alias'), 'capture-authorized', $this->terms(), $this->id('authorize'));
        $this->provider->plan($other, Status::Confirmed);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Another operation cannot replace the original financial idempotency identity.');
        $this->provider->attempt($other);
    }

    public function testAnotherRefundKeyCannotRefundTheSameCaptureTwice(): void
    {
        $this->confirm($this->request('authorize'));
        $this->confirm($this->request('capture-authorized'));
        $this->confirm($this->request('refund-authorized'));
        $other = new Request(
            $this->id('refund-alias'),
            'refund-authorized',
            [
                'amountMinor' => 1375,
                'currency' => 'USD',
            ],
            $this->id('capture-authorized')
        );
        $this->provider->plan($other, Status::Confirmed);
        $this->expectException(LogicException::class);
        $this->provider->attempt($other);
    }

    public function testFinancialSupportCannotBorrowACallerTransaction(): void
    {
        $db = new PDO('sqlite::memory:');
        $provider = new Journal($db, 'shop-a');
        $db->beginTransaction();
        try {
            $provider->installAuthorizationSupport();
            $this->fail('The provider owns financial admission and effect transactions.');
        } catch (LogicException) {
            $this->assertTrue($db->inTransaction());
        } finally {
            $db->rollBack();
        }
    }

    public function testForeignGenerationCannotBeUsedAsTheAuthorizationTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Request(
            $this->id('capture-authorized'),
            'capture-authorized',
            $this->terms(),
            new Identity('shop-a', 'order-1', 'g2', 'authorize')
        );
    }

    private function request(string $action): Request
    {
        $target = match ($action) {
            'authorize' => null,
            'capture-authorized', 'void-authorization' => $this->id('authorize'),
            'refund-authorized' => $this->id('capture-authorized'),
        };
        return new Request($this->id($action), $action, $action === 'refund-authorized'
            ? [
                'amountMinor' => 1375,
                'currency' => 'USD',
            ] : $this->terms(), $target);
    }

    private function terms(): array
    {
        return [
            'amountMinor' => 1375,
            'currency' => 'USD',
            'expiresAt' => 1001,
        ];
    }

    private function id(string $operation): Identity
    {
        return new Identity('shop-a', 'order-1', 'g1', $operation);
    }

    private function confirm(Request $request): void
    {
        $this->provider->plan($request, Status::Confirmed);
        $this->assertSame(Status::Confirmed, $this->provider->attempt($request)->status);
    }

    private function actionsWithEffects(): array
    {
        return array_map(
            static fn (string $json): string => json_decode($json, true, flags: JSON_THROW_ON_ERROR)['action'],
            $this->db->query('SELECT o.request FROM provider_effects e JOIN provider_operations o
                ON o.id = e.operation_id ORDER BY e.sequence')->fetchAll(PDO::FETCH_COLUMN)
        );
    }
}
