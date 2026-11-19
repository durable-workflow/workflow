<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\FinancialOperation;
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
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\OperationContext;

require_once __DIR__ . '/../../../../examples/configured-state-machines/providers/bootstrap.php';

final class RetainedFinancialOperationTest extends TestCase
{
    private PDO $db;

    private Journal $provider;

    private int $now = 1000;

    private bool $pairedAuthority = true;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->provider = new Journal($this->db, 'shop-a', fn (): int => $this->now);
        $this->provider->install();
        $this->provider->installAuthorizationSupport();
    }

    public function testConfirmedAuthorizationDoesNotProduceACapturedMoneyMilestone(): void
    {
        $this->provider->plan($this->request('authorize'), Status::Confirmed);
        $result = $this->operation('authorize')
            ->execute($this->context('authorize'));
        $this->assertTrue($result->observed);
        $this->assertSame($this->request('authorize')->identity->key(), $result->value['operation']);
        $this->assertSame('confirmed', $result->value['status']);
        $this->assertSame(['authorize'], $this->actions());
        $this->assertSame(Status::Unknown, $this->provider->lookup($this->request('capture'))->status);
    }

    public function testUncertainAuthorizationReconcilesItsOriginalIdentityWithoutASecondEffect(): void
    {
        $this->provider->plan($this->request('authorize'), Status::Confirmed, loseAcknowledgement: true);
        try {
            $this->operation('authorize')
                ->execute($this->context('authorize'));
            $this->fail('The provider commits permission before losing its response.');
        } catch (LostAcknowledgement) {
            $result = $this->operation('authorize')
                ->execute($this->context('authorize', true, 'replacement-attempt'));
        }
        $this->assertTrue($result->observed);
        $this->assertSame(['authorize'], $this->actions());
        $this->assertSame(['command', 'lookup'], $this->db->query(
            'SELECT kind FROM provider_requests ORDER BY sequence'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testUncertainMissingAuthorizationDoesNotAuthorizeAProviderCommand(): void
    {
        $this->provider->plan($this->request('authorize'), Status::Confirmed);
        $result = $this->operation('authorize')
            ->execute($this->context('authorize', true));
        $this->assertFalse($result->observed);
        $this->assertSame('financial-forward-unknown', $result->reason);
        $this->assertSame([], $this->actions());
        $this->assertSame(0, $this->countRows('provider_operations'));
    }

    public function testCancellationBeforeEitherForwardOperationFencesBothOriginalIdentities(): void
    {
        $capture = $this->operation('capture')
            ->compensate($this->context('capture'));
        $authorization = $this->operation('authorize')
            ->compensate($this->context('authorize'));
        $this->assertTrue($capture->verified);
        $this->assertTrue($authorization->verified);
        foreach (['authorize', 'capture'] as $key) {
            $result = $this->provider->attempt($this->request($key));
            $this->assertSame(Status::NotExecuted, $result->status);
            $this->assertTrue($result->admissionClosed);
        }
        $this->assertSame([], $this->actions());
    }

    public function testUnusedAuthorizationIsVoidedAfterTheOriginalCaptureIsFenced(): void
    {
        $this->authorize();
        $this->provider->plan($this->request('void'), Status::Confirmed);
        $this->assertTrue($this->operation('capture')->compensate($this->context('capture'))->verified);
        $result = $this->operation('authorize')
            ->compensate($this->context('authorize'));
        $this->assertTrue($result->verified);
        $this->assertSame(['authorize', 'void-authorization'], $this->actions());
        $this->assertSame(Status::Confirmed, $this->provider->lookup($this->request('authorize'))->status);
        $this->assertSame(Status::NotExecuted, $this->provider->attempt($this->request('capture'))->status);
        $this->assertSame(2, $this->countRows('provider_effects'));
    }

    public function testCaptureExpiryIsAnObservedRefusalRatherThanSuccessfulSettlement(): void
    {
        $this->authorize();
        $this->now = 1001;
        $this->provider->plan($this->request('capture'), Status::Confirmed);
        $result = $this->operation('capture')
            ->execute($this->context('capture'));
        $this->assertTrue($result->observed);
        $this->assertSame('refused', $result->value['status']);
        $this->assertNull($result->value['effectId']);
        $this->assertSame(['authorize'], $this->actions());
    }

    public function testCaptureRacingCancellationMustReconcileBeforeChoosingVoidOrRefund(): void
    {
        $this->authorize();
        $capture = $this->request('capture');
        $this->provider->plan($capture, Status::Unknown);
        $this->assertFalse($this->operation('capture')->execute($this->context('capture'))->observed);
        foreach (['capture', 'authorize'] as $stage) {
            $result = $this->operation($stage)
                ->compensate($this->context($stage));
            $this->assertFalse($result->verified);
            $this->assertSame('financial-capture-unknown', $result->reason);
        }
        $this->assertSame(['authorize'], $this->actions());
        $this->now = 2000;
        $this->provider->resolve($capture, Status::Confirmed);
        $this->provider->plan($this->request('refund'), Status::Confirmed);
        $money = $this->operation('capture')
            ->compensate($this->context('capture', true, 'fresh-recovery'));
        $permission = $this->operation('authorize')
            ->compensate($this->context('authorize', true, 'fresh-recovery'));
        $this->assertTrue($money->verified && $permission->verified);
        $this->assertSame(['authorize', 'capture-authorized', 'refund-authorized'], $this->actions());
        $this->assertContains(
            'authorization-consumed:' . $this->request('authorize')->identity->key(),
            $permission->evidence
        );
    }

    public function testUnexecutedCapturePreservesTheOriginalUnusedPermissionForVoid(): void
    {
        $this->authorize();
        $capture = $this->request('capture');
        $this->provider->plan($capture, Status::Processing);
        $this->operation('capture')
            ->execute($this->context('capture'));
        $this->assertFalse($this->operation('capture')->compensate($this->context('capture'))->verified);
        $this->provider->resolve($capture, Status::NotExecuted);
        $this->provider->plan($this->request('void'), Status::Confirmed);
        $this->assertTrue($this->operation('capture')->compensate($this->context('capture'))->verified);
        $this->assertTrue($this->operation('authorize')->compensate($this->context('authorize'))->verified);
        $this->assertSame(['authorize', 'void-authorization'], $this->actions());
    }

    public static function unsettledUndo(): array
    {
        return [[Status::Processing], [Status::Unknown], [Status::Refused], [Status::NotExecuted]];
    }

    #[DataProvider('unsettledUndo')]
    public function testUnsettledRefundCannotVerifyTheCapturedMoneyObligation(Status $status): void
    {
        $this->authorize();
        $this->provider->plan($this->request('capture'), Status::Confirmed);
        $this->operation('capture')
            ->execute($this->context('capture'));
        $this->provider->plan($this->request('refund'), $status);
        $money = $this->operation('capture')
            ->compensate($this->context('capture'));
        $this->assertFalse($money->verified);
        $this->assertSame('financial-compensation-' . $status->value, $money->reason);
        // Permission was consumed; verifying only this obligation does not verify the unresolved money obligation.
        $this->assertTrue($this->operation('authorize')->compensate($this->context('authorize'))->verified);
        $this->assertSame(['authorize', 'capture-authorized'], $this->actions());
    }

    #[DataProvider('unsettledUndo')]
    public function testUnsettledVoidCannotVerifyUnusedAuthorization(Status $status): void
    {
        $this->authorize();
        $this->provider->plan($this->request('void'), $status);
        $result = $this->operation('authorize')
            ->compensate($this->context('authorize'));
        $this->assertFalse($result->verified);
        $this->assertSame('financial-compensation-' . $status->value, $result->reason);
        $this->assertSame(['authorize'], $this->actions());
    }

    public function testLostRefundAcknowledgementReconcilesTheSameFullRefundDuringRecovery(): void
    {
        $this->authorize();
        $this->provider->plan($this->request('capture'), Status::Confirmed);
        $this->operation('capture')
            ->execute($this->context('capture'));
        $this->provider->plan($this->request('refund'), Status::Confirmed, loseAcknowledgement: true);
        try {
            $this->operation('capture')
                ->compensate($this->context('capture'));
            $this->fail('The original refund commits before losing its acknowledgement.');
        } catch (LostAcknowledgement) {
            $result = $this->operation('capture')
                ->compensate($this->context('capture', true, 'replacement'));
        }
        $this->assertTrue($result->verified);
        $this->assertSame(['authorize', 'capture-authorized', 'refund-authorized'], $this->actions());
    }

    public function testHostMustAuthorizeBothOriginalObligationsBeforeAnyProviderSql(): void
    {
        $this->pairedAuthority = false;
        foreach (['authorize', 'capture'] as $stage) {
            $operation = $this->operation($stage);
            foreach (['execute', 'compensate'] as $method) {
                try {
                    $operation->{$method}($this->context($stage));
                    $this->fail('Revoked or unpaired authority cannot reach the provider.');
                } catch (LogicException) {
                    $this->assertSame(0, $this->countRows('provider_requests'));
                    $this->assertSame(0, $this->countRows('provider_operations'));
                    $this->assertSame(0, $this->countRows('provider_authorization_links'));
                }
            }
        }
    }

    public function testSameClientForeignScopeCannotUseTheOriginalFinancialDescriptor(): void
    {
        $original = $this->context('authorize');
        $foreign = new OperationContext(
            new ScopeIdentity('shop-a', 'financial-example', 'other.payment', 'g1'),
            $original->obligation,
            $original->operationId,
            $original->originalRunId,
            'activity-2',
            'attempt-2',
            false
        );
        $this->expectException(LogicException::class);
        $this->operation('authorize')
            ->execute($foreign);
    }

    public function testAProviderDescriptorCannotExtendThePinnedExpiryForCapture(): void
    {
        $input = $this->descriptor();
        $input['capture']['terms']['expiresAt'] = 5000;
        $this->expectException(InvalidArgumentException::class);
        $this->operation('authorize')
            ->assertInput($input);
    }

    public function testCallerCannotSelectAnUnregisteredHandlerStage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FinancialOperation($this->provider, static function (): void {
        }, 'refund-everything');
    }

    private function authorize(): void
    {
        $this->provider->plan($this->request('authorize'), Status::Confirmed);
        $this->assertTrue($this->operation('authorize')->execute($this->context('authorize'))->observed);
    }

    private function operation(string $stage): FinancialOperation
    {
        $scope = $this->scope()
            ->toArray();
        $descriptor = PortableDescriptor::encode($this->descriptor());
        return new FinancialOperation($this->provider, function (OperationContext $context, string $mode) use (
            $scope,
            $descriptor,
            $stage
        ): void {
            if (! $this->pairedAuthority || $context->scope->toArray() !== $scope
                || $context->originalRunId !== 'native-original' || $context->operationId !== 'operation-' . $stage
                || $context->obligation->key !== 'payment.' . $stage
                || $context->obligation->handlerType !== 'example.financial.' . $stage
                || $context->obligation->handlerVersion !== '1'
                || PortableDescriptor::encode($context->obligation->input) !== $descriptor
                || ! in_array($mode, ['forward', 'compensate'], true)) {
                throw new LogicException('The host refused original paired financial authority.');
            }
        }, $stage);
    }

    private function context(string $stage, bool $uncertain = false, string $attempt = 'first'): OperationContext
    {
        return new OperationContext(
            $this->scope(),
            new Obligation('payment.' . $stage, 'example.financial.' . $stage, '1', $this->descriptor()),
            'operation-' . $stage,
            'native-original',
            'activity-' . $attempt,
            $attempt,
            $uncertain
        );
    }

    private function scope(): ScopeIdentity
    {
        return new ScopeIdentity('shop-a', 'financial-example', 'order-1.payment', 'g1');
    }

    private function descriptor(): array
    {
        $result = [];
        foreach (['authorize', 'capture', 'void', 'refund'] as $key) {
            $result[$key] = $this->request($key)->toArray();
        }
        return $result;
    }

    private function request(string $key): Request
    {
        $action = match ($key) {
            'authorize' => 'authorize',
            'capture' => 'capture-authorized',
            'void' => 'void-authorization',
            'refund' => 'refund-authorized',
        };
        $identity = static fn (string $operation): Identity => new Identity('shop-a', 'order-1', 'g1', $operation);
        $target = match ($key) {
            'authorize' => null,
            'capture', 'void' => $identity('authorize'),
            'refund' => $identity('capture'),
        };
        $terms = [
            'amountMinor' => 1375,
            'currency' => 'USD',
        ];
        if ($key !== 'refund') {
            $terms['expiresAt'] = 1001;
        }
        return new Request($identity($key), $action, $terms, $target);
    }

    private function actions(): array
    {
        return array_map(
            static fn (string $json): string => json_decode($json, true, flags: JSON_THROW_ON_ERROR)['action'],
            $this->db->query('SELECT o.request FROM provider_effects e JOIN provider_operations o
                ON o.id = e.operation_id ORDER BY e.sequence')->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
