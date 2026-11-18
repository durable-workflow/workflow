<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Workflow\V2\StateMachine\Execution\ExecutionReceipt;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Transition\AssessedTransitionStep;
use Workflow\V2\StateMachine\Transition\TransitionContext;

/**
 * The Order dispatches nothing here: its separate incident process owns every remedy effect. The step waits
 * for that process's recorded settled customer remedy, without the original fulfillment deadline.
 */
final class TransitRemedyStep extends AssessedTransitionStep
{
    public function __construct(
        private readonly Machine $machine
    ) {
    }

    public function execute(TransitionContext $context): ExecutionReceipt
    {
        $value = IncidentWait::remedy();
        return new ExecutionReceipt($context->execution(), ['transit-remedy:' . $value['remedyId']]);
    }

    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void
    {
        (new RetainedStep($this->machine->scope, $this->machine->identity, $this->machine->initialization))
            ->compensate($context, $originalExecution);
    }
}
