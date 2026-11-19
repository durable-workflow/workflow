<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Recovery;

use Throwable;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Persistence\ChildScopes;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Compensation\Security\ScopeAuthorizationRefused;

/**
 * @internal Compensation format or engine implementation.
 *
 * Child request acknowledgement never substitutes for verified business compensation. A permanent
 * authorization refusal propagates as ScopeAuthorizationRefused; any other failure is a retried unknown.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final readonly class ChildResolution
{
    public function __construct(
        private ScopeRepository $repository,
        private ScopeAuthorization $authorization,
        private ScopeRecovery $recovery
    ) {
    }

    public function resolve(
        ScopeIdentity $parent,
        Obligation $obligation,
        string $operationId,
        ?string $ownerRunId = null
    ): Resolution {
        if ($obligation->handlerVersion !== ChildScopes::VERSION || array_keys($obligation->input) !== [
            'child',
        ] || ! is_array($obligation->input['child'])) {
            return Resolution::unknown('child-descriptor-incompatible');
        }
        $child = ScopeIdentity::fromArray($obligation->input['child']);
        if ($child->application !== $parent->application || $child->namespace !== $parent->namespace) {
            return Resolution::unknown('child-owner-mismatch');
        }
        $principal = $this->repository->primaryRequest($parent)['principal'];
        try {
            $this->authorization->assertAllowed($child, $principal, 'compensate');
            $this->recovery->request(
                $child,
                ChildRecoveryRequest::key($operationId, $ownerRunId),
                $principal,
                'parent-compensation'
            );
            $snapshot = $this->repository->inspect($child);
        } catch (ScopeAuthorizationRefused $refusal) {
            throw $refusal;
        } catch (Throwable) {
            return Resolution::unknown('child-unresolved');
        }
        return $snapshot['status'] === 'verified'
            ? Resolution::verified(['scope-verified:' . $child->id()])
            : Resolution::unknown('child-unresolved');
    }
}
