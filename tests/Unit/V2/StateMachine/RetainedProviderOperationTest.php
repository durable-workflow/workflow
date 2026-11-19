<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\LostAcknowledgement;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\RetainedOperation;
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

final class RetainedProviderOperationTest extends TestCase
{
    private PDO $db;

    private Journal $provider;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->provider = new Journal($this->db, 'shop-a');
        $this->provider->install();
    }

    public function testUncertainForwardReconcilesTheSameChargeAcrossNativeAttemptIdentities(): void
    {
        $forward = $this->forward();
        $this->provider->plan($forward, Status::Confirmed, loseAcknowledgement: true);
        $operation = $this->operation();
        try {
            $operation->execute($this->context());
            $this->fail('The provider acknowledgement must be lost after the original charge commits.');
        } catch (LostAcknowledgement) {
            $result = $operation->execute($this->context(uncertain: true, attempt: 'recovery-attempt'));
        }
        $this->assertTrue($result->observed);
        $this->assertSame('confirmed', $result->value['status']);
        $this->assertSame('effect-' . $forward->identity->key(), $result->value['effectId']);
        $this->assertSame(1, $this->rowCount('provider_effects'));
        $this->assertSame(['command', 'lookup'], $this->db->query(
            'SELECT kind FROM provider_requests ORDER BY sequence'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testAnUncertainMissingLookupDoesNotAuthorizeACharge(): void
    {
        $this->provider->plan($this->forward(), Status::Confirmed);
        $result = $this->operation()
            ->execute($this->context(uncertain: true));
        $this->assertFalse($result->observed);
        $this->assertSame('provider-forward-unknown', $result->reason);
        $this->assertSame(0, $this->rowCount('provider_operations'));
        $this->assertSame(0, $this->rowCount('provider_effects'));
    }

    public function testAnAuthoritativeNonAdmissionFenceSettlesAbsenceAndBlocksLateDispatch(): void
    {
        $result = $this->operation()
            ->compensate($this->context(uncertain: true));
        $this->assertTrue($result->verified);
        $this->assertSame('verified', $result->outcome);
        $this->assertSame(['provider-fenced:' . $this->forward()->identity->key()], $result->evidence);
        $late = $this->provider->attempt($this->forward());
        $this->assertSame(Status::NotExecuted, $late->status);
        $this->assertTrue($late->admissionClosed);
        $this->assertSame(0, $this->rowCount('provider_effects'));
    }

    public static function unsettled(): array
    {
        return [
            'processing' => [Status::Processing],
            'unknown' => [Status::Unknown],
        ];
    }

    #[DataProvider('unsettled')]
    public function testAnAdmittedUnresolvedForwardRemainsOutstandingUntilItsLateResult(Status $status): void
    {
        $this->provider->plan($this->forward(), $status);
        $this->provider->plan($this->refund(), Status::Confirmed);
        $operation = $this->operation();
        $forward = $operation->execute($this->context());
        $this->assertSame($status !== Status::Unknown, $forward->observed);
        $blocked = $operation->compensate($this->context());
        $this->assertFalse($blocked->verified);
        $this->assertNotSame('verified', $blocked->outcome);
        $this->assertSame('provider-forward-' . $status->value, $blocked->reason);
        $this->assertSame(1, $this->rowCount('provider_requests'));
        $this->assertSame(0, $this->rowCount('provider_effects'));
        $this->provider->resolve($this->forward(), Status::Confirmed);
        $settled = $operation->compensate($this->context(uncertain: true, attempt: 'later-compensation'));
        $this->assertTrue($settled->verified);
        $this->assertSame('verified', $settled->outcome);
        $this->assertSame(2, $this->rowCount('provider_effects'));
        $this->assertSame(Status::Confirmed, $this->provider->lookup($this->forward())->status);
    }

    public static function refundOutcomes(): array
    {
        return [
            'processing' => [Status::Processing],
            'unknown' => [Status::Unknown],
            'refused' => [Status::Refused],
            'definitively unexecuted' => [Status::NotExecuted],
        ];
    }

    #[DataProvider('refundOutcomes')]
    public function testAnUnconfirmedRefundCannotVerifyCompensation(Status $status): void
    {
        $this->provider->plan($this->forward(), Status::Confirmed);
        $this->provider->plan($this->refund(), $status);
        $this->operation()
            ->execute($this->context());
        $result = $this->operation()
            ->compensate($this->context());
        $this->assertFalse($result->verified);
        $this->assertNotSame('verified', $result->outcome);
        $this->assertSame('provider-compensation-' . $status->value, $result->reason);
        $this->assertSame([], $result->evidence);
        $this->assertSame(1, $this->rowCount('provider_effects'));
    }

    public function testLostRefundAcknowledgementCannotCreateAnotherRefundOnRecovery(): void
    {
        $this->provider->plan($this->forward(), Status::Confirmed);
        $this->provider->plan($this->refund(), Status::Confirmed, loseAcknowledgement: true);
        $operation = $this->operation();
        $operation->execute($this->context());
        try {
            $operation->compensate($this->context());
            $this->fail('A lost refund acknowledgement must not be called verified.');
        } catch (LostAcknowledgement) {
            $this->assertSame(2, $this->rowCount('provider_effects'));
        }
        $again = $operation->compensate($this->context(uncertain: true, attempt: 'recovery-attempt'));
        $this->assertTrue($again->verified);
        $this->assertSame([
            'effect-' . $this->forward()->identity->key(),
            'effect-' . $this->refund()->identity->key(),
        ], $again->evidence);
        $this->assertSame(2, $this->rowCount('provider_effects'));
        $this->assertSame(3, $this->rowCount('provider_requests'));
        $this->assertSame(Status::Confirmed, $this->provider->lookup($this->forward())->status);
    }

    public function testRevokedAuthorityIsRefusedBeforeAnyProviderSql(): void
    {
        $operation = new RetainedOperation($this->provider, static function (): void {
            throw new LogicException('The host revoked original-owner authority.');
        });
        foreach (['execute', 'compensate'] as $method) {
            try {
                $operation->{$method}($this->context());
                $this->fail('Revoked authority cannot touch the provider journal.');
            } catch (LogicException $error) {
                $this->assertSame('The host revoked original-owner authority.', $error->getMessage());
            }
        }
        $this->assertSame(0, $this->rowCount('provider_operations'));
        $this->assertSame(0, $this->rowCount('provider_requests'));
    }

    public function testAnotherOriginalScopeGenerationCannotInheritTheProviderGrant(): void
    {
        $foreign = $this->context(scope: new ScopeIdentity('shop-a', 'provider-example', 'order-1.payment', 'g2'));
        $operation = $this->operation();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The host rejected the original scope, owner or descriptor.');
        $operation->compensate($foreign);
    }

    public function testAConfirmedStatusWithoutItsEffectEvidenceCannotSettle(): void
    {
        $this->provider->plan($this->forward(), Status::Confirmed);
        $operation = $this->operation();
        $operation->execute($this->context());
        $this->db->exec('DELETE FROM provider_effects');
        $this->assertFalse($operation->execute($this->context(uncertain: true))->observed);
        $this->assertFalse($operation->compensate($this->context())->verified);
        $this->assertSame(2, $this->rowCount('provider_requests'));
    }

    public function testPortableRequestRoundTripRejectsExtraOrCoercedIdentityFields(): void
    {
        $value = $this->refund()
            ->toArray();
        $this->assertSame($this->refund()->fingerprint(), Request::fromArray($value)->fingerprint());
        $value['identity']['generation'] = 1;
        $this->expectException(InvalidArgumentException::class);
        Request::fromArray($value);
    }

    public function testContradictoryRefusalCannotEraseARecordedEffect(): void
    {
        $this->provider->plan($this->forward(), Status::Confirmed);
        $operation = $this->operation();
        $operation->execute($this->context());
        $this->db->exec("UPDATE provider_operations SET status = 'refused'");
        $this->assertFalse($operation->execute($this->context(uncertain: true))->observed);
        $this->assertFalse($operation->compensate($this->context())->verified);
        $this->assertSame(1, $this->rowCount('provider_effects'));
    }

    private function operation(): RetainedOperation
    {
        $scope = $this->scope()
            ->toArray();
        $input = PortableDescriptor::encode($this->descriptor());
        return new RetainedOperation($this->provider, static function (OperationContext $context, string $mode) use (
            $scope,
            $input
        ): void {
            if ($context->scope->toArray() !== $scope || $context->originalRunId !== 'native-original'
                || $context->operationId !== 'native-operation-1' || $context->obligation->key !== 'payment.capture'
                || $context->obligation->handlerType !== 'example.provider' || $context->obligation->handlerVersion !== '1'
                || PortableDescriptor::encode($context->obligation->input) !== $input
                || ! in_array($mode, ['forward', 'compensate'], true)) {
                throw new LogicException('The host rejected the original scope, owner or descriptor.');
            }
        });
    }

    private function context(
        bool $uncertain = false,
        string $attempt = 'attempt-1',
        ?ScopeIdentity $scope = null
    ): OperationContext {
        return new OperationContext(
            $scope ?? $this->scope(),
            new Obligation('payment.capture', 'example.provider', '1', $this->descriptor()),
            'native-operation-1',
            'native-original',
            'activity-' . $attempt,
            $attempt,
            $uncertain,
        );
    }

    private function scope(): ScopeIdentity
    {
        return new ScopeIdentity('shop-a', 'provider-example', 'order-1.payment', 'g1');
    }

    private function descriptor(): array
    {
        return [
            'forward' => $this->forward()
                ->toArray(),
            'compensation' => $this->refund()
                ->toArray(),
        ];
    }

    private function forward(): Request
    {
        return new Request(new Identity('shop-a', 'order-1', 'g1', 'capture-1'), 'capture', [
            'amountMinor' => 100,
            'currency' => 'USD',
        ]);
    }

    private function refund(): Request
    {
        return new Request(new Identity('shop-a', 'order-1', 'g1', 'refund-1'), 'refund', [
            'amountMinor' => 100,
            'currency' => 'USD',
        ], $this->forward()
->identity);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
