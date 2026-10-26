<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceOperation;

final class ServiceCallAttemptClaim
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly WorkflowServiceCall $call,
        public readonly WorkflowServiceOperation $operation,
        public readonly ServiceBoundaryRequest $request,
        public readonly array $options,
        public readonly int $attempt,
        public readonly int $maxAttempts,
        public readonly int $generation,
        public readonly string $token,
        public readonly string $catalogRevision,
        public readonly string $policyRevision,
    ) {
    }
}
