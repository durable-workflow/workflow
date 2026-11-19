<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use Closure;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\ChildScopeBindingAuthorization;
use Workflow\V2\StateMachine\Binding\ChildScopeParticipation;
use Workflow\V2\StateMachine\Execution\ExecutionReference;

/** The demo worker's authority is supplied by trusted bootstrap, never workflow arguments. */
final readonly class Ownership implements ChildScopeBindingAuthorization
{
    public const APPLICATION = 'configured.orders';

    public const SHIPMENT_APPLICATION = self::APPLICATION . '.shipment';

    public const NAMESPACE = 'configured-example';

    public const CHILD_TYPE = 'example.configured.shipment.v1';

    public const VERSION = 'configured-owner-v1';

    /**
     * @param list<string> $subjects
     * @param Closure(BindingIdentity, string, string): void $bindingOwner Live host ownership check, before native storage.
     */
    public function __construct(
        public string $principal,
        private array $subjects,
        private Closure $bindingOwner
    ) {
        if ($principal === '' || ! array_is_list($subjects)) {
            throw new LogicException('A configured worker requires its trusted principal and subject grant.');
        }
        foreach ($subjects as $subject) {
            self::assertSubject($subject);
        }
    }

    public function authorize(string $subject, string $principal): void
    {
        self::assertSubject($subject);
        if ($principal !== $this->principal || ! in_array($subject, $this->subjects, true)) {
            throw new LogicException('The worker cannot access this business subject.');
        }
    }

    public function assertAllowed(BindingIdentity $identity, string $principal, string $operation): string
    {
        $this->authorize($identity->parentExecution->subject, $principal);
        if (! in_array($operation, ['reserve', 'load', 'close'], true)) {
            throw new LogicException('The binding operation is unavailable.');
        }
        foreach (Catalogue::profiles() as [$integration, $version]) {
            if (Catalogue::order(
                $integration,
                $version
            )->fingerprint() === $identity->parentExecution->policyFingerprint) {
                $expected = self::binding($identity->parentExecution, $identity->parentRunId, $integration, $version);
                if (PortableDescriptor::encode($expected->toArray()) === PortableDescriptor::encode(
                    $identity->toArray()
                )) {
                    ($this->bindingOwner)($identity, $principal, $operation);
                    return self::VERSION;
                }
            }
        }
        throw new LogicException('The original business binding or policy was substituted.');
    }

    public function assertParticipationAllowed(
        BindingIdentity $identity,
        ChildScopeParticipation $participation,
        string $principal,
        string $operation,
        ?string $pinnedPolicyVersion = null
    ): string {
        $this->assertAllowed($identity, $principal, $operation);
        $expected = new ChildScopeParticipation(
            self::scope($identity->parentExecution->subject, $identity->parentRunId),
            self::childScope($identity->parentExecution),
            $identity->parentExecution->executionId . '.shipment',
        );
        if ($participation->toArray() !== $expected->toArray()
            || ($pinnedPolicyVersion !== null && $pinnedPolicyVersion !== self::VERSION)) {
            throw new LogicException('The retained scope and binding do not share their original owner.');
        }
        return self::VERSION;
    }

    /**
     * @param Closure(ScopeIdentity, string, string): void $scopeOwner
     */
    public function scopeAuthorization(Closure $scopeOwner): ScopeAuthorization
    {
        return new class($this, $scopeOwner) implements ScopeAuthorization {
            public function __construct(
                private readonly Ownership $ownership,
                private readonly Closure $scopeOwner
            ) {
            }

            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                try {
                    $subject = explode('.', $scope->key)[0];
                    $this->ownership->authorize($subject, $principal);
                    if ($scope->application !== Ownership::APPLICATION || $scope->namespace !== Ownership::NAMESPACE
                        || ! in_array(
                            $operation,
                            ['register', 'forward', 'compensate', 'participate', 'inspect', 'request'],
                            true
                        )) {
                        throw new LogicException('The scope lies outside this worker authority.');
                    }
                    $root = Ownership::scope($subject, $scope->generation);
                    if ($scope->toArray() === $root->toArray()) {
                        ($this->scopeOwner)($scope, $principal, $operation);
                        return Ownership::VERSION;
                    }
                    $parts = explode('.', $scope->generation);
                    if (count($parts) !== 3 || $parts[1] !== 'begin' || ! ctype_digit($parts[2])
                        || $scope->key !== $subject . '.shipment.' . $parts[2]) {
                        throw new LogicException('The child scope has a different state-entry owner.');
                    }
                    ($this->scopeOwner)($scope, $principal, $operation);
                    return Ownership::VERSION;
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

    public static function instance(string $subject): string
    {
        self::assertSubject($subject);
        return 'configured-order-' . $subject;
    }

    public static function scope(string $subject, string $rootRun): ScopeIdentity
    {
        self::assertSubject($subject);
        return new ScopeIdentity(self::APPLICATION, self::NAMESPACE, $subject . '.order', $rootRun);
    }

    public static function childScope(ExecutionReference $begin): ScopeIdentity
    {
        self::assertSubject($begin->subject);
        return new ScopeIdentity(
            self::APPLICATION,
            self::NAMESPACE,
            $begin->subject . '.shipment.' . $begin->sourceRevision,
            $begin->executionId
        );
    }

    public static function binding(
        ExecutionReference $begin,
        string $rootRun,
        string $integration,
        string $version = '1'
    ): BindingIdentity {
        if ($begin->scope !== self::APPLICATION || $begin->sourceState !== 'received'
            || $begin->executionId !== $rootRun . '.begin.' . $begin->sourceRevision
            || $begin->policyFingerprint !== Catalogue::order($integration, $version)->fingerprint()) {
            throw new LogicException('The child does not belong to this original transition execution.');
        }
        $scope = self::childScope($begin);
        return new BindingIdentity(
            $begin,
            self::NAMESPACE,
            self::instance($begin->subject),
            $rootRun,
            'shipment',
            $begin->executionId,
            Catalogue::hosting($integration),
            self::CHILD_TYPE,
            Catalogue::shipment($integration, $version)->fingerprint(),
            'completed',
            ChildReservation::inputFingerprint([$begin->subject, $rootRun, $begin->toArray(), $scope->toArray()])
        );
    }

    private static function assertSubject(string $subject): void
    {
        if (preg_match('/\Acase-[0-9a-f]{16}-g[1-9]\d{0,5}\z/D', $subject) !== 1) {
            throw new LogicException('The subject is outside this configured example.');
        }
    }
}
