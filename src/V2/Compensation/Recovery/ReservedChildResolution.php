<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Recovery;

use Throwable;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Persistence\ChildScopes;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\ChildReservationStore;
use Workflow\V2\StateMachine\Binding\NeverAdmittedChild;

/** @internal Resolves only an authoritative native closure or the exact admitted child's verified ledger. */
final readonly class ReservedChildResolution
{
    public function __construct(
        private ScopeRepository $repository,
        private ChildReservationStore $reservations,
        private ScopeAuthorization $authorization,
        private ScopeRecovery $recovery,
    ) {
    }

    public function resolve(ScopeIdentity $parent, Obligation $obligation, string $operationId): Resolution
    {
        try {
            if ($obligation->handlerType !== ChildScopes::RESERVED_TYPE || $obligation->handlerVersion !== ChildScopes::VERSION
                || array_keys($obligation->input) !== ['reservation'] || ! is_array(
                    $obligation->input['reservation']
                )) {
                return Resolution::unknown('reserved-child-descriptor-incompatible');
            }
            $reservation = ChildReservation::fromArray($obligation->input['reservation']);
            $participation = $reservation->participation;
            if ($participation === null || $participation->parent->toArray() !== $parent->toArray()
                || $participation->obligationKey !== $obligation->key) {
                return Resolution::unknown('reserved-child-owner-mismatch');
            }
            $principal = $this->repository->primaryRequest($parent)['principal'];
            $outcome = $this->reservations->close($reservation, $principal);
            if ($outcome instanceof NeverAdmittedChild) {
                return Resolution::verified(['native-child-never-admitted:' . $outcome->closureId], true);
            }
            PortableDescriptor::identifier(
                $this->authorization->assertAllowed($participation->child, $principal, 'compensate'),
                'authorization policy version',
            );
            $snapshot = $this->repository->metadata($participation->child);
            if ($snapshot['original_run_id'] !== $outcome->admission->childRunId) {
                return Resolution::unknown('reserved-child-scope-owner-mismatch');
            }
            if ($snapshot['status'] !== 'verified') {
                $this->recovery->request(
                    $participation->child,
                    'parent-' . $operationId,
                    $principal,
                    'parent-compensation'
                );
                $snapshot = $this->repository->inspect($participation->child);
            }
            return $snapshot['original_run_id'] === $outcome->admission->childRunId && $snapshot['status'] === 'verified'
                ? Resolution::verified(['scope-verified:' . $participation->child->id()], true)
                : Resolution::unknown('reserved-child-unresolved');
        } catch (Throwable) {
            return Resolution::unknown('reserved-child-unresolved');
        }
    }
}
