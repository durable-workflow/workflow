<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\V2\TestBusinessStateMachineWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\V2\StateMachine\Definition\ConditionDefinition;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\RuleReference;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Execution\ExecutionReceipt;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Execution\TransitionResult;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\StateMachine\Persistence\CommitResult;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\Verdict;

final class PolicyProfileTest extends TestCase
{
    public function testChangedHandlerVersionChangesThePinnedFingerprint(): void
    {
        $original = TestBusinessStateMachineWorkflow::profile();
        $changed = new PolicyProfile('example', '1', 'prepare', '1', ['Received', 'Processing'], [
            new TransitionDefinition('process', 'received', ['Received'], 'Processing', 'prepare', '2'),
        ]);
        $this->assertNotSame($original->fingerprint(), $changed->fingerprint());
        $this->assertSame($original->fingerprint(), TestBusinessStateMachineWorkflow::profile()->fingerprint());
    }

    public function testProfilesCanUseDifferentEdgesWithinTheSameStateCatalogue(): void
    {
        $original = TestBusinessStateMachineWorkflow::profile();
        $other = new PolicyProfile('example', '1', 'imported', '1', $original->states, [
            new TransitionDefinition('import', 'imported', ['Received'], 'Processing', 'import', '1', false),
        ]);
        $this->assertSame([], $other->candidates('received', 'Received'));
        $this->assertSame('import', $other->candidates('imported', 'Received')[0]->key);
    }

    public function testUndeclaredTargetStateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PolicyProfile('machine', '1', 'profile', '1', ['Received'], [
            new TransitionDefinition('process', 'received', ['Received'], 'Processing', 'handler', '1'),
        ]);
    }

    public function testDuplicateTransitionIdentityIsRejected(): void
    {
        $transition = new TransitionDefinition('process', 'received', ['Received'], 'Processing', 'handler', '1');
        $this->expectException(InvalidArgumentException::class);
        new PolicyProfile('machine', '1', 'profile', '1', ['Received', 'Processing'], [$transition, $transition]);
    }

    public function testAStateCannotBeAMutableObject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PolicyProfile('machine', '1', 'profile', '1', [new \stdClass()], []);
    }

    public function testEvidenceMustBeImmutableReferencesRatherThanObjects(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Decision(Verdict::Unknown, 'missing_fact', [new \stdClass()]);
    }

    public function testUnknownCommitCannotManufactureSuccess(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CommitResult($this->execution(), TransitionStatus::Unknown, 'acknowledgement_missing');
    }

    public function testReceiptRejectsDuplicateOperationReferences(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExecutionReceipt($this->execution(), ['booking', 'booking']);
    }

    public function testPublicResultHasAPortableAvroRepresentation(): void
    {
        $result = new TransitionResult(
            $this->execution(),
            TransitionStatus::Pending,
            'delivery_pending',
            new Decision(Verdict::Unknown, 'evidence_missing', ['snapshot-1']),
            'complete'
        );
        $portable = $result->toArray();
        $this->assertSame(
            $portable,
            Serializer::unserializeWithCodec('avro', Serializer::serializeWithCodec('avro', $portable))
        );
        $this->assertSame('unknown', $portable['assessment']['verdict']);
    }

    public function testConditionFreeProfilesKeepTheirPreviouslyRecordedFingerprint(): void
    {
        $this->assertSame(
            '2a73df44be854e9cdeae37d024903dc443176187e786fbb072592b99c35df5be',
            $this->configuredProfile()
                ->fingerprint()
        );
    }

    public function testConditionParametersAndRuleVersionsArePinnedWithTheProfile(): void
    {
        $original = $this->configuredProfile([
            'target' => ConditionDefinition::rule(new RuleReference('flag', '1', [
                'fact' => 'ready',
            ],)),
        ]);
        $changedParameter = $this->configuredProfile([
            'target' => ConditionDefinition::rule(new RuleReference('flag', '1', [
                'fact' => 'different',
            ],)),
        ]);
        $changedVersion = $this->configuredProfile([
            'target' => ConditionDefinition::rule(new RuleReference('flag', '2', [
                'fact' => 'ready',
            ],)),
        ]);
        $this->assertNotSame($original->fingerprint(), $changedParameter->fingerprint());
        $this->assertNotSame($original->fingerprint(), $changedVersion->fingerprint());
        $this->assertNotSame($original->fingerprint(), $this->configuredProfile()->fingerprint());
        $this->assertSame('1', $original->condition('target')->rule->version);
    }

    public function testNamedConditionMapOrderDoesNotChangeThePinnedPolicy(): void
    {
        $target = ConditionDefinition::rule(new RuleReference('flag', '1', [
            'fact' => 'ready',
        ]));
        $permission = ConditionDefinition::rule(new RuleReference('permission', '1'));
        $this->assertSame(
            $this->configuredProfile([
                'target' => $target,
                'permission' => $permission,
            ])->fingerprint(),
            $this->configuredProfile([
                'permission' => $permission,
                'target' => $target,
            ])->fingerprint()
        );
    }

    public function testMissingConditionsCannotFallBackToAnotherRegisteredCondition(): void
    {
        $profile = $this->configuredProfile([
            'permission' => ConditionDefinition::rule(new RuleReference('flag', '1')),
        ]);
        $this->expectExceptionMessage('pinned policy condition is unavailable');
        $profile->condition('target');
    }

    public function testPolicyConditionsRejectUntypedConfigurationAndPositionalKeys(): void
    {
        foreach ([[new \stdClass()], [
            'target' => 'App\\Rule',
        ]] as $conditions) {
            try {
                $this->configuredProfile($conditions);
                $this->fail('The policy accepted untyped condition configuration.');
            } catch (InvalidArgumentException $error) {
                $this->assertStringContainsString('Named policy conditions', $error->getMessage());
            }
        }
    }

    private function configuredProfile(array $conditions = []): PolicyProfile
    {
        return new PolicyProfile('orders', '1', 'integration', '1', ['received', 'done'], [
            new TransitionDefinition('finish', 'go', ['received'], 'done', 'finish', '1'),
        ], $conditions);
    }

    private function execution(): ExecutionReference
    {
        return new ExecutionReference('scope', 'subject', 'operation', 'fingerprint', 'Received', '1');
    }
}
