<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Activities;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ChildScopes;
use Workflow\V2\Compensation\Persistence\ScopeStorage;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Compensation\Security\ScopeBoundary;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildDefinitionRegistry;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\ChildReservationStore;
use Workflow\V2\StateMachine\Binding\ChildScopeParticipation;

/** @internal Recorded reservation and retained-participant boundary. */
#[Type('dw.compensation.reserve-child-scope.v1')]
final class ReserveChildScopeActivity extends Activity
{
    public int $tries = 3;

    public function handle(
        array $identity,
        string $key,
        array $childIdentity,
        array $bindingIdentity,
        string $principal
    ): array {
        $scope = ScopeIdentity::fromArray($identity);
        $child = ScopeIdentity::fromArray($childIdentity);
        $binding = BindingIdentity::fromArray($bindingIdentity);
        if ($binding->namespace !== $this->run->namespace || $binding->parentRunId !== $this->runId()
            || $binding->parentInstanceId !== $this->workflowId()) {
            throw new NonRetryableException('scope_child_binding_owner_mismatch');
        }
        $participation = new ChildScopeParticipation($scope, $child, $key);
        $reservation = null;
        $policy = null;
        return app(ScopeBoundary::class)->run(
            $this,
            $scope,
            'register',
            $principal,
            function () use ($scope, $child, $principal, &$reservation, &$policy): array {
                if (! $reservation instanceof ChildReservation) {
                    throw new NonRetryableException('scope_child_reservation_missing');
                }
                $id = app(ChildScopes::class)->registerReserved($reservation);
                ScopeStorage::append(ScopeStorage::locked($scope), 'child_authorized', [
                    'childScopeId' => $child->id(),
                    'principal' => $principal,
                    'operation' => 'participate',
                    'policyVersion' => $policy,
                    'runId' => $this->runId(),
                    'activityId' => $this->activityId(),
                    'attemptId' => $this->attemptId(),
                ]);
                return [
                    'reservation' => $reservation->toArray(),
                    'obligationId' => $id,
                ];
            },
            beforeTransaction: static function () use (
                $child,
                $principal,
                $binding,
                $participation,
                &$reservation,
                &$policy
            ): void {
                try {
                    $policy = app(ScopeAuthorization::class)->assertAllowed($child, $principal, 'participate');
                    PortableDescriptor::identifier($policy, 'authorization policy version');
                } catch (Throwable) {
                    throw new NonRetryableException('scope_child_authorization_refused');
                }
                // This only commits an unused native reservation. Native admission
                // refuses until the participant below is durably registered.
                $store = app(ChildReservationStore::class);
                try {
                    $reservation = $store->load($binding, $principal);
                } catch (ModelNotFoundException) {
                    $reservation = $store->reserve(
                        $binding,
                        app(ChildDefinitionRegistry::class)->forBinding($binding),
                        $principal,
                        $participation,
                    );
                }
                if ($reservation->participation?->toArray() !== $participation->toArray()) {
                    throw new NonRetryableException('scope_child_participation_mismatch');
                }
            },
        );
    }
}
