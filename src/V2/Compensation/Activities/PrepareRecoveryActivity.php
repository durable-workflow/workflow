<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Activities;

use Workflow\V2\Activity;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Security\ScopeBoundary;

/**
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final class PrepareRecoveryActivity extends Activity
{
    public int $tries = 3;

    public function handle(array $identity): array
    {
        $scope = ScopeIdentity::fromArray($identity);
        return app(ScopeBoundary::class)->run(
            $this,
            $scope,
            'compensate',
            null,
            static function (array $snapshot): array {
                if (! in_array($snapshot['status'], ['requested', 'verified'], true)) {
                    throw new \LogicException('The scope has no accepted compensation request.');
                }
                return array_column($snapshot['obligations'], 'id');
            }
        );
    }
}
