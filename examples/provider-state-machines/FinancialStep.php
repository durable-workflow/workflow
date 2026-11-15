<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Compensation\RegisteredObligation;
use Workflow\V2\StateMachine\Execution\ExecutionReceipt;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Transition\AssessedTransitionStep;
use Workflow\V2\StateMachine\Transition\TransitionContext;

/** Predeclared permission and money remain the same owners when a policy later admits capture. */
final class FinancialStep extends AssessedTransitionStep
{
    public function __construct(
        private readonly RegisteredObligation $authorization,
        private readonly RegisteredObligation $money
    ) {
    }

    public function execute(TransitionContext $context): ExecutionReceipt
    {
        $result = match ($context->transition->key) {
            'authorize' => $this->authorization->execute(), 'capture' => $this->money->execute(),
            default => throw new LogicException('The original financial step is unavailable.'),
        };
        return new ExecutionReceipt($context->execution(), ['original-financial:' . $result['operation']]);
    }

    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void
    {
        if (! $context->execution()->equals($originalExecution)) {
            throw new LogicException('The financial transition must compensate its original execution.');
        }
        $this->money->compensate();
        if ($context->transition->key === 'authorize') {
            $this->authorization->compensate();
        }
    }
}
