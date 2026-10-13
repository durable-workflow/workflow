<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;

final class ActivityRetryPolicyMismatchException extends LogicException
{
    public function __construct(
        public readonly int $workflowSequence,
        public readonly string $policyField,
    ) {
        parent::__construct(sprintf(
            'Activity retry policy at workflow sequence %d differs in [%s]. Restore the recorded per-call policy or use a versioned workflow change before repairing this run.',
            $workflowSequence,
            $policyField,
        ));
    }
}
