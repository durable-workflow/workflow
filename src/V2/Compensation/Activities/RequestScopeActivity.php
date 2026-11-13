<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Activities;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Security\ScopeBoundary;

/**
 * @internal
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
#[Type('dw.compensation.request-scope.v1')]
final class RequestScopeActivity extends Activity
{
    public int $tries = 3;

    public function handle(array $identity, string $principal): void
    {
        $scope = ScopeIdentity::fromArray($identity);
        app(ScopeBoundary::class)->run(
            $this,
            $scope,
            'request',
            $principal,
            static fn (array $snapshot, string $policyVersion) => app(ScopeRepository::class)->request(
                $scope,
                'native-saga',
                $principal,
                'workflow-cleanup',
                $policyVersion
            )
        );
    }
}
