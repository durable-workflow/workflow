<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Transition;

/** Keeps the boolean contract tied to the exact assessment exposed to the caller. */
abstract class AssessedTransitionStep implements TransitionStep
{
    final public function canTransition(TransitionContext $context): bool
    {
        return $context->snapshot->target->satisfied();
    }

    final public function canExecute(TransitionContext $context): bool
    {
        return $context->snapshot->permission->satisfied();
    }
}
