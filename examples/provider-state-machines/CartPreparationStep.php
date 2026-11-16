<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\StateMachine\Execution\ExecutionReceipt;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Transition\AssessedTransitionStep;
use Workflow\V2\StateMachine\Transition\TransitionContext;
use Workflow\V2\Workflow;

/** Adopt the prelude's existing responsibilities; this step creates no provider work or child obligation. */
final class CartPreparationStep extends AssessedTransitionStep
{
    public function execute(TransitionContext $context): ExecutionReceipt
    {
        if ($context->transition->key !== 'prepare') {
            throw new LogicException('Cart preparation cannot fulfill a Shipment.');
        }
        $proof = Workflow::activity(CartBoundaryActivity::class, 'verify-prepared', []);
        return new ExecutionReceipt($context->execution(), ['original-cart-association:' . $proof['orderReference']]);
    }

    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void
    {
        if (! $context->execution()->equals($originalExecution) || $context->transition->key !== 'prepare') {
            throw new LogicException('Cart adoption compensation changed its original state-entry execution.');
        }
        // Adoption only read the existing receipt. Earlier predeclared scope callbacks own all actual effects.
    }
}
