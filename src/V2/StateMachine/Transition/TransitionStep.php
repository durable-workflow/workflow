<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Transition;

use Workflow\V2\StateMachine\Execution\ExecutionReceipt;
use Workflow\V2\StateMachine\Execution\ExecutionReference;

/**
 * [1] target check -> [2] permitted work -> [3] fresh target check -> [4] caller commits.
 * Guards are pure projections of the context's recorded assessments.
 * execute() can suspend through DW. A receipt is not required to compensate.
 */
interface TransitionStep
{
    public function canTransition(TransitionContext $context): bool;

    public function canExecute(TransitionContext $context): bool;

    public function execute(TransitionContext $context): ExecutionReceipt;

    /**
     * Native Saga calls the original owner. Return only after required effects or their
     * absence are verified. Pending/unknown work must wait or fail explicitly, never succeed.
     */
    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void;
}
