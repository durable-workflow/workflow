<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\BusinessStateMachines\ExampleOwnershipPolicy;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildScopeParticipation;

require_once __DIR__ . '/../../../../examples/business-state-machines/ProfileCatalogue.php';
require_once __DIR__ . '/../../../../examples/business-state-machines/ExampleOwnershipPolicy.php';

final class ExampleOwnershipPolicyTest extends TestCase
{
    private const SUBJECT = 'item-aaaaaaaaaaaaaaaa';

    public function testBothProfilesAuthorizeTheirOwnCompleteOriginalRelationship(): void
    {
        $policy = new ExampleOwnershipPolicy('worker-a');
        foreach (['prepare', 'imported'] as $integration) {
            $binding = ExampleOwnershipPolicy::binding(self::SUBJECT, $integration, '1', 'original-run');
            $version = $policy->assertParticipationAllowed($binding, $this->participation(), 'worker-a', 'reserve');
            $this->assertSame($version, $policy->assertParticipationAllowed(
                $binding, $this->participation(), 'worker-a', 'load', $version,
            ));
            $this->assertSame($version, $policy->assertParticipationAllowed(
                $binding, $this->participation(), 'worker-a', 'close', $version,
            ));
        }
    }

    public function testIndependentPermissionForTwoBusinessSubjectsCannotPairTheirScopes(): void
    {
        $policy = new ExampleOwnershipPolicy('worker-a');
        $first = ExampleOwnershipPolicy::binding(self::SUBJECT, 'prepare', '1', 'original-run');
        $other = ExampleOwnershipPolicy::binding('item-bbbbbbbbbbbbbbbb', 'prepare', '1', 'other-run');
        $this->assertNotEmpty($policy->assertAllowed($first, 'worker-a', 'reserve'));
        $this->assertNotEmpty($policy->assertAllowed($other, 'worker-a', 'reserve'));
        $otherChild = ExampleOwnershipPolicy::scope('item-bbbbbbbbbbbbbbbb', 'other-run', 'child');
        $this->assertNotEmpty($policy->scopeAuthorization()->assertAllowed($otherChild, 'worker-a', 'participate'));
        $forged = new ChildScopeParticipation($this->participation()->parent, $otherChild, 'work');
        $this->expectExceptionMessage('The original example scope/binding relationship is unavailable.');
        $policy->assertParticipationAllowed($first, $forged, 'worker-a', 'reserve');
    }

    public static function bindingChanges(): array
    {
        return [
            'native namespace' => ['namespace', 'other', false],
            'native instance' => ['parentInstanceId', 'other', false],
            'slot' => ['slot', 'other', false],
            'generation' => ['generation', 'entry:new', false],
            'hosting state' => ['hostingState', 'completed', false],
            'child type' => ['childWorkflowType', 'other.child', false],
            'child policy' => ['childPolicyFingerprint', str_repeat('b', 64), false],
            'required business outcome' => ['requiredOutcome', 'technical-complete', false],
            'child input' => ['inputFingerprint', str_repeat('c', 64), false],
            'business scope' => ['scope', 'other-app', true],
            'business subject' => ['subject', 'item-bbbbbbbbbbbbbbbb', true],
            'business execution' => ['executionId', 'other-execution', true],
            'source state' => ['sourceState', 'working', true],
            'source revision' => ['sourceRevision', '2', true],
            'parent policy' => ['policyFingerprint', str_repeat('d', 64), true],
        ];
    }

    #[DataProvider('bindingChanges')]
    public function testOriginalBusinessAndNativeIdentityCannotBeSubstituted(
        string $field,
        string $value,
        bool $execution,
    ): void {
        $policy = new ExampleOwnershipPolicy('worker-a');
        $payload = ExampleOwnershipPolicy::binding(self::SUBJECT, 'prepare', '1', 'original-run')->toArray();
        if ($execution) {
            $payload['parentExecution'][$field] = $value;
        } else {
            $payload[$field] = $value;
        }
        $changed = BindingIdentity::fromArray($payload);
        $this->expectExceptionMessage('The original example binding owner or policy was substituted.');
        $policy->assertAllowed($changed, 'worker-a', 'load');
    }

    public function testScopeGenerationCannotFollowANewerRun(): void
    {
        $policy = new ExampleOwnershipPolicy('worker-a');
        $binding = ExampleOwnershipPolicy::binding(self::SUBJECT, 'prepare', '1', 'original-run');
        $changed = new ChildScopeParticipation(
            ExampleOwnershipPolicy::scope(self::SUBJECT, 'new-run', 'parent'),
            ExampleOwnershipPolicy::scope(self::SUBJECT, 'new-run', 'child'),
            'work',
        );
        $this->expectExceptionMessage('The original example scope/binding relationship is unavailable.');
        $policy->assertParticipationAllowed($binding, $changed, 'worker-a', 'close');
    }

    public function testADeploymentCannotReplaceTheOriginalJointPolicy(): void
    {
        $policy = new ExampleOwnershipPolicy('worker-a');
        $binding = ExampleOwnershipPolicy::binding(self::SUBJECT, 'prepare', '1', 'original-run');
        $participation = $this->participation();
        $this->expectExceptionMessage('The original example scope/binding relationship is unavailable.');
        $policy->assertParticipationAllowed($binding, $participation, 'worker-a', 'close', 'replacement-policy');
    }

    public function testRecordedPrincipalNamesDoNotSelectWorkerAuthority(): void
    {
        $policy = new ExampleOwnershipPolicy('worker-a');
        $binding = ExampleOwnershipPolicy::binding(self::SUBJECT, 'prepare', '1', 'original-run');
        $this->expectExceptionMessage('The configured example worker did not authorize this principal.');
        $policy->assertAllowed($binding, 'worker-b', 'reserve');
    }

    private function participation(): ChildScopeParticipation
    {
        return new ChildScopeParticipation(
            ExampleOwnershipPolicy::scope(self::SUBJECT, 'original-run', 'parent'),
            ExampleOwnershipPolicy::scope(self::SUBJECT, 'original-run', 'child'),
            'work',
        );
    }
}
