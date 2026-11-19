<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\BusinessStateMachines;

use LogicException;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\ChildScopeBindingAuthorization;
use Workflow\V2\StateMachine\Binding\ChildScopeParticipation;
use Workflow\V2\StateMachine\Execution\ExecutionReference;

/**
 * Canonical ownership for the local, single-service-principal example. The worker identity
 * is supplied by trusted application bootstrap, never selected from recorded arguments.
 * Applications with other principals/tenants must supply their authenticated host policy.
 */
final readonly class ExampleOwnershipPolicy implements ChildScopeBindingAuthorization
{
    public const APPLICATION = 'example.fulfilment';

    public const NAMESPACE = 'business-example';

    public const CHILD_TYPE = 'example.fulfilment.child.v1';

    private const VERSION = 'example-relationship-v1';

    public function __construct(
        private string $workerIdentity
    ) {
        if (trim($workerIdentity) === '') {
            throw new LogicException('The example requires a configured worker identity.');
        }
    }

    public function assertAllowed(BindingIdentity $identity, string $principal, string $operation): string
    {
        $this->assertPrincipal($principal);
        if (! in_array($operation, ['reserve', 'load', 'close'], true)) {
            throw new LogicException('The example binding operation is unavailable.');
        }
        foreach (['prepare', 'imported'] as $integration) {
            $profile = ProfileCatalogue::select($integration, '1');
            if ($identity->parentExecution->policyFingerprint === $profile->fingerprint()) {
                $expected = self::binding(
                    $identity->parentExecution->subject,
                    $integration,
                    '1',
                    $identity->parentRunId
                );
                if ($identity->toArray() === $expected->toArray()) {
                    return self::VERSION;
                }
                break;
            }
        }
        throw new LogicException('The original example binding owner or policy was substituted.');
    }

    public function assertParticipationAllowed(
        BindingIdentity $identity,
        ChildScopeParticipation $participation,
        string $principal,
        string $operation,
        ?string $pinnedPolicyVersion = null,
    ): string {
        $this->assertAllowed($identity, $principal, $operation);
        $expected = new ChildScopeParticipation(
            self::scope($identity->parentExecution->subject, $identity->parentRunId, 'parent'),
            self::scope($identity->parentExecution->subject, $identity->parentRunId, 'child'),
            'work',
        );
        if ($participation->toArray() !== $expected->toArray()
            || ($pinnedPolicyVersion !== null && $pinnedPolicyVersion !== self::VERSION)) {
            throw new LogicException('The original example scope/binding relationship is unavailable.');
        }
        return self::VERSION;
    }

    public function assertScopeAllowed(ScopeIdentity $scope, string $principal, string $operation): string
    {
        $this->assertPrincipal($principal);
        if ($scope->application !== self::APPLICATION || $scope->namespace !== self::NAMESPACE
            || preg_match('/\Aitem-[0-9a-f]{16}\.(?:parent|child)\z/D', $scope->key) !== 1
            || ! in_array(
                $operation,
                ['register', 'forward', 'compensate', 'participate', 'inspect', 'request'],
                true
            )) {
            throw new LogicException('The example scope is outside this worker authority.');
        }
        return 'example-scope-v1';
    }

    public function scopeAuthorization(): ScopeAuthorization
    {
        return new class($this) implements ScopeAuthorization {
            public function __construct(
                private readonly ExampleOwnershipPolicy $policy
            ) {
            }

            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                try {
                    return $this->policy->assertScopeAllowed($scope, $principal, $operation);
                } catch (\LogicException $refused) {
                    // Policy refusals are permanent; other failures stay retryable.
                    throw new \Workflow\V2\Compensation\Security\ScopeAuthorizationRefused(
                        $refused->getMessage(),
                        $refused
                    );
                }
            }
        };
    }

    public static function binding(
        string $subject,
        string $integration,
        string $version,
        string $originalParentRun,
    ): BindingIdentity {
        self::assertSubject($subject);
        $profile = ProfileCatalogue::select($integration, $version);
        $child = self::scope($subject, $originalParentRun, 'child');
        return new BindingIdentity(
            new ExecutionReference(
                self::APPLICATION,
                $subject,
                $subject . ':begin',
                $profile->fingerprint(),
                'received',
                '1'
            ),
            self::NAMESPACE,
            self::instanceId($subject),
            $originalParentRun,
            'work',
            'entry:' . $originalParentRun,
            ProfileCatalogue::hostingState($integration, $version),
            self::CHILD_TYPE,
            ProfileCatalogue::child($integration, $version)->fingerprint(),
            'completed',
            ChildReservation::inputFingerprint([$subject, $integration, $version, $child->toArray()]),
        );
    }

    public static function scope(string $subject, string $originalParentRun, string $role): ScopeIdentity
    {
        self::assertSubject($subject);
        if (! in_array($role, ['parent', 'child'], true)) {
            throw new LogicException('Unknown example scope role.');
        }
        return new ScopeIdentity(self::APPLICATION, self::NAMESPACE, $subject . '.' . $role, $originalParentRun);
    }

    public static function instanceId(string $subject): string
    {
        self::assertSubject($subject);
        return 'business-example-' . $subject;
    }

    private static function assertSubject(string $subject): void
    {
        if (preg_match('/\Aitem-[0-9a-f]{16}\z/D', $subject) !== 1) {
            throw new LogicException('The example subject is outside its local dataset.');
        }
    }

    private function assertPrincipal(string $principal): void
    {
        if ($principal !== $this->workerIdentity) {
            throw new LogicException('The configured example worker did not authorize this principal.');
        }
    }
}
