<?php

declare(strict_types=1);

namespace Workflow\V2\EntityReferences;

/**
 * Trusted execution context for resolving a reference (#996): where the
 * activity attempt runs, and what the resolved value is bound to.
 *
 * @experimental Draft API for workflow issue 996.
 */
final class EntityReferenceContext
{
    /**
     * @param class-string|null $parameterType The declared parameter type the
     *        resolved object must satisfy, or null for an untyped, built-in or
     *        compound type. The worker also checks compound types locally.
     */
    public function __construct(
        public readonly ?string $namespace,
        public readonly string $workflowInstanceId,
        public readonly string $workflowRunId,
        public readonly string $activityExecutionId,
        public readonly int $attemptNumber,
        public readonly int $argumentPosition,
        public readonly ?string $parameterType,
    ) {
    }
}
