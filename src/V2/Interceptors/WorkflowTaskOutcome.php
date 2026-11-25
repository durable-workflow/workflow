<?php

declare(strict_types=1);

namespace Workflow\V2\Interceptors;

/**
 * Read-only view of what one workflow task recorded.
 *
 * The engine commits the history the workflow code produced, not this object,
 * so changing or replacing it cannot change history.
 */
final class WorkflowTaskOutcome
{
    /**
     * @param list<string> $historyEventTypes event types this task appended, in order
     */
    public function __construct(
        public readonly array $historyEventTypes,
        public readonly ?string $runStatus,
        public readonly ?string $nextTaskId,
    ) {
    }
}
