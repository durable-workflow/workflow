<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Activities;

use Workflow\V2\Activity;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeProgress;
use Workflow\V2\Compensation\Security\ScopeBoundary;

/**
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final class VerifyScopeActivity extends Activity
{
    public function handle(array $identity): array
    {
        $scope = ScopeIdentity::fromArray($identity);
        return app(ScopeBoundary::class)->run(
            $this,
            $scope,
            'compensate',
            null,
            static fn () => app(ScopeProgress::class)->finish($scope)
        );
    }
}
