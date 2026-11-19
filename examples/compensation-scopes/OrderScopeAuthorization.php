<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\CompensationScopes;

use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Compensation\Security\ScopeAuthorizationRefused;

/**
 * Demo policy: one trusted worker principal for this application. Unknown operations are refused.
 */
final readonly class OrderScopeAuthorization implements ScopeAuthorization
{
    private const OPERATIONS = ['register', 'forward', 'compensate', 'participate', 'request', 'release', 'inspect'];

    public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
    {
        if ($principal !== OrderCompensation::PRINCIPAL || $scope->application !== OrderCompensation::APPLICATION
            || ! in_array($operation, self::OPERATIONS, true)) {
            throw new ScopeAuthorizationRefused('This worker may not ' . $operation . ' that scope.');
        }
        return 'order-policy-1';
    }
}
