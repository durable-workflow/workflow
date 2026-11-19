<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Transition;

use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Rules\Decision;

/**
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class TransitionContext
{
    public function __construct(
        public TransitionDefinition $transition,
        public FactSnapshot $snapshot,
        public ?Decision $admission = null,
    ) {
    }

    public function execution(): ExecutionReference
    {
        return $this->snapshot->execution;
    }
}
