<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Activities;

use Throwable;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Activity;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ChildScopes;
use Workflow\V2\Compensation\Persistence\ScopeStorage;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Compensation\Security\ScopeBoundary;

/**
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final class RegisterChildScopeActivity extends Activity
{
    public int $tries = 3;

    public function handle(array $identity, string $key, array $childIdentity, string $principal): string
    {
        $scope = ScopeIdentity::fromArray($identity);
        $child = ScopeIdentity::fromArray($childIdentity);
        return app(ScopeBoundary::class)->run(
            $this,
            $scope,
            'register',
            $principal,
            function () use ($scope, $key, $child, $principal): string {
                try {
                    $policy = app(ScopeAuthorization::class)->assertAllowed($child, $principal, 'participate');
                    PortableDescriptor::identifier($policy, 'authorization policy version');
                } catch (Throwable) {
                    throw new NonRetryableException('scope_child_authorization_refused');
                }
                $id = app(ChildScopes::class)->register($scope, $this->runId(), $key, $child);
                ScopeStorage::append(ScopeStorage::locked($scope), 'child_authorized', [
                    'childScopeId' => $child->id(),
                    'principal' => $principal,
                    'operation' => 'participate',
                    'policyVersion' => $policy,
                    'runId' => $this->runId(),
                    'activityId' => $this->activityId(),
                    'attemptId' => $this->attemptId(),
                ]);
                return $id;
            }
        );
    }
}
