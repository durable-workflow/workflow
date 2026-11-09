<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Execution;

use InvalidArgumentException;

/** Confirms the work, not permission to commit the target business state. */
final readonly class ExecutionReceipt
{
    /**
     * @param list<string> $operations
     */
    public function __construct(
        public ExecutionReference $execution,
        public array $operations = []
    ) {
        if (! array_is_list($operations)) {
            throw new InvalidArgumentException('Operation references must be a list.');
        }
        foreach ($operations as $operation) {
            if (! is_string($operation) || trim($operation) === '') {
                throw new InvalidArgumentException('Operation references must be non-empty strings.');
            }
        }
        if (count(array_unique($operations)) !== count($operations)) {
            throw new InvalidArgumentException('Operation references must be unique.');
        }
    }
}
