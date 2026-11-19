<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation;

use Workflow\V2\Compensation\Activities\CompensateObligationActivity;
use Workflow\V2\Compensation\Activities\RegisterChildScopeActivity;
use Workflow\V2\Compensation\Activities\RegisterObligationActivity;
use Workflow\V2\Compensation\Activities\RequestScopeActivity;
use Workflow\V2\Compensation\Activities\ReserveChildScopeActivity;
use Workflow\V2\Compensation\Activities\VerifyScopeActivity;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\ChildScopeParticipation;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\WorkflowFiberContext;
use Workflow\V2\Workflow;

/**
 * Workflow authoring helper. Every live boundary is a native recorded activity.
 *
 * @api Stable compensation-scope API (#1063). See docs/api-stability.md#compensation-scopes.
 */
final readonly class DurableScope
{
    /**
     * Patch marker for checks added after 2.31 (caller input and forward value limits, reserved handler types).
     */
    private const REGISTRATION_CHECKS = 'dw.compensation.registration-checks';

    private bool $registrationChecks;

    /**
     * @param Workflow $workflow the native workflow whose saga receives each compensation callback
     * @param string $principal trusted cleanup principal from application policy, never from request input
     */
    public function __construct(
        private Workflow $workflow,
        private ScopeIdentity $identity,
        private string $principal
    ) {
        if (! WorkflowFiberContext::active()) {
            throw new \LogicException('DurableScope must be authored inside a native workflow.');
        }
        Workflow::getVersion('dw.compensation.scope-activities', 1, 1);
        // Histories recorded before these checks replay without them.
        $this->registrationChecks = (bool) Workflow::patched(self::REGISTRATION_CHECKS);
    }

    public function execute(Obligation $obligation, ?ActivityOptions $options = null): array
    {
        return $this->register($obligation)
            ->execute($options);
    }

    /**
     * Record the obligation and compensation before policy work decides when to dispatch its forward operation.
     */
    public function register(Obligation $obligation): RegisteredObligation
    {
        if ($this->registrationChecks) {
            self::assertRegistrable($obligation);
        }
        $scope = $this->identity->toArray();
        $principal = $this->principal;
        $id = Workflow::activity(RegisterObligationActivity::class, $scope, $obligation->toArray(), $principal);
        // The callback is rebuilt by replay before dispatching the effect and never needs its return value.
        $this->workflow->addCompensation(static function () use ($scope, $principal, $id): void {
            Workflow::activity(RequestScopeActivity::class, $scope, $principal);
            Workflow::activity(CompensateObligationActivity::class, $scope, $id);
        });
        return new RegisteredObligation($this->identity, $id, $principal, $this->registrationChecks);
    }

    public function participate(string $key, ScopeIdentity $child): void
    {
        $scope = $this->identity->toArray();
        $principal = $this->principal;
        $id = Workflow::activity(RegisterChildScopeActivity::class, $scope, $key, $child->toArray(), $principal);
        $this->workflow->addCompensation(static function () use ($scope, $principal, $id): void {
            Workflow::activity(RequestScopeActivity::class, $scope, $principal);
            Workflow::activity(CompensateObligationActivity::class, $scope, $id);
        });
    }

    /**
     * Verify that every obligation in this scope is compensated, after the saga ran.
     *
     * Returns `['scopeId' => <id()>, 'status' => 'verified']`. While any obligation is unresolved it does not
     * return: the recorded verify activity fails, and the workflow receives that failure.
     *
     * @return array{scopeId: string, status: 'verified'}
     */
    public function verifyCompensation(): array
    {
        return Workflow::activity(VerifyScopeActivity::class, $this->identity->toArray());
    }

    /**
     * @experimental Reserved child admission depends on the experimental StateMachine binding types (#1069).
     *     It is not covered by the compensation-scope stability guarantee.
     */
    public function reserveChild(string $key, ScopeIdentity $child, BindingIdentity $binding): ChildReservation
    {
        $scope = $this->identity->toArray();
        $principal = $this->principal;
        $result = Workflow::activity(
            ReserveChildScopeActivity::class,
            $scope,
            $key,
            $child->toArray(),
            $binding->toArray(),
            $principal
        );
        if (! is_array($result) || count($result) !== 2 || ! is_array($result['reservation'] ?? null)
            || ! is_string($result['obligationId'] ?? null) || preg_match(
                '/\A[0-9a-f]{64}\z/D',
                $result['obligationId']
            ) !== 1) {
            throw new \LogicException('The recorded child participant result is malformed.');
        }
        $reservation = ChildReservation::fromArray($result['reservation']);
        if ($reservation->identity->toArray() !== $binding->toArray()
            || $reservation->participation?->toArray() !== (new ChildScopeParticipation(
                $this->identity,
                $child,
                $key
            ))->toArray()) {
            throw new \LogicException('The recorded child participant belongs to another binding.');
        }
        $id = $result['obligationId'];
        $this->workflow->addCompensation(static function () use ($scope, $principal, $id): void {
            Workflow::activity(RequestScopeActivity::class, $scope, $principal);
            Workflow::activity(CompensateObligationActivity::class, $scope, $id);
        });
        return $reservation;
    }

    private static function assertRegistrable(Obligation $obligation): void
    {
        if (str_starts_with($obligation->handlerType, HandlerRegistry::RESERVED_PREFIX)) {
            throw new \InvalidArgumentException('Handler types starting with "dw." are reserved for the engine.');
        }
        PortableDescriptor::userData(
            $obligation->input,
            'obligation input',
            PortableDescriptor::USER_INPUT_MAX_BYTES,
            PortableDescriptor::USER_INPUT_MAX_VALUES,
            PortableDescriptor::USER_INPUT_MAX_DEPTH,
        );
    }
}
