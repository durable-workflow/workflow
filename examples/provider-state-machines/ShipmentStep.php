<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Compensation\RegisteredObligation;
use Workflow\V2\StateMachine\Execution\ExecutionReceipt;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Transition\AssessedTransitionStep;
use Workflow\V2\StateMachine\Transition\TransitionContext;
use Workflow\V2\Workflow;

/** The predeclared original graph owns booking, deduction and applicable physical settlement together. */
final class ShipmentStep extends AssessedTransitionStep
{
    public function __construct(
        private readonly RegisteredObligation $physical
    ) {
    }

    public function execute(TransitionContext $context): ExecutionReceipt
    {
        $result = match ($context->transition->key) {
            'book' => $this->physical->execute(),
            'handover' => Workflow::activity(ShipmentBoundaryActivity::class, 'deduct'),
            default => throw new LogicException('Delivery is an observed physical fact, not a dispatchable effect.'),
        };
        return new ExecutionReceipt($context->execution(), ['original-physical:' . $result['operation']]);
    }

    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void
    {
        if (! $context->execution()->equals($originalExecution)) {
            throw new LogicException('Physical compensation must retain its original transition execution.');
        }
        $this->physical->compensate();
    }
}
