<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Execution;

use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;

/**
 * What a handler knows about the operation it is asked to perform. The stable operation ID survives
 * native attempts and recovery runs.
 *
 * - reconcileBeforeForward (forward direction): an earlier forward attempt for this operation may have
 *   taken effect. Look the operation up by operationId before issuing it again.
 * - forwardOutcomeUnconfirmed (compensation direction): the forward effect was never confirmed observed.
 *   It may be absent, partial, or still arrive late. Reconcile by operationId before undoing it.
 *
 * Both are false in the other direction.
 *
 * @api Stable compensation-scope API (#1063). See docs/api-stability.md#compensation-scopes.
 */
final readonly class OperationContext
{
    /**
     * @internal The engine builds the context for each provider call.
     */
    public function __construct(
        public ScopeIdentity $scope,
        public Obligation $obligation,
        public string $operationId,
        public string $originalRunId,
        public string $activityId,
        public string $attemptId,
        public bool $reconcileBeforeForward,
        public ?string $forwardActivityId = null,
        public ?string $forwardAttemptId = null,
        public bool $forwardOutcomeUnconfirmed = false,
    ) {
    }
}
