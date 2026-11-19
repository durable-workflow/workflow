<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Activities;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeProgress;
use Workflow\V2\Compensation\Recovery\CompensationWake;
use Workflow\V2\Compensation\Security\ScopeBoundary;

/**
 * @internal Recorded compensation engine boundary.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
#[Type('dw.compensation.verify-scope.v1')]
final class VerifyScopeActivity extends Activity
{
    public int $tries = 3;

    public function handle(array $identity): array
    {
        $scope = ScopeIdentity::fromArray($identity);
        $result = app(ScopeBoundary::class)->run(
            $this,
            $scope,
            'compensate',
            null,
            static fn () => app(ScopeProgress::class)->finish($scope)
        );
        // A parent waiting to retry this child participant can now verify it.
        CompensationWake::parentOf($scope);
        return $result;
    }
}
