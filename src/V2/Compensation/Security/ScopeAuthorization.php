<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Security;

use Workflow\V2\Compensation\Definition\ScopeIdentity;

/**
 * The host derives a trusted principal and authorizes the exact scope, generation and operation.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
interface ScopeAuthorization
{
    /**
     * Return the immutable authorization policy version, or refuse by throwing.
     */
    public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string;
}
