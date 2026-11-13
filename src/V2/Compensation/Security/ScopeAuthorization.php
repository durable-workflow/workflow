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
     * Return the immutable authorization decision's policy version, or refuse by throwing.
     * Runs before the native ledger transaction. Do not retain an open transaction.
     * The captured grant is recorded with the mutation; native ownership is rechecked under locks.
     */
    public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string;
}
