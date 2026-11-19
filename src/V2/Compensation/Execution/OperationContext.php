<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Execution;

use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;

/**
 * Runtime context only. The stable operation ID survives native attempts and recovery runs.
 *
 * @api Stable compensation-scope API from 2.31.0 (#1063). See docs/api-stability.md#compensation-scopes.
 */
final readonly class OperationContext
{
    public function __construct(
        public ScopeIdentity $scope,
        public Obligation $obligation,
        public string $operationId,
        public string $originalRunId,
        public string $activityId,
        public string $attemptId,
        public bool $uncertainForward,
        public ?string $forwardActivityId = null,
        public ?string $forwardAttemptId = null,
    ) {
    }
}
