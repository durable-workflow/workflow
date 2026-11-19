<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Closure;
use Workflow\V2\StateMachine\Execution\TransitionStatus;

/** Shared recorded transition order and cancellation settlement for the funding workflow types. */
trait FundingPaymentExecution
{
    private function finishFunding(Machine $machine, FundingStep $step, ?Closure $beforeTransition = null): array
    {
        foreach (['prepare', 'collect', 'confirm-receipt'] as $transition) {
            $beforeTransition?->__invoke($transition);
            $result = $machine->transition($transition, $step);
            if ($result->status !== TransitionStatus::Committed) {
                return $machine->outcome($result);
            }
            self::activity(FundingHandoffActivity::class, 'notify', [
                'stage' => $transition,
            ]);
        }
        return $machine->outcome($result);
    }

    private function compensateFunding(Machine $machine): array
    {
        self::cancellationShield(fn () => $this->compensate());
        // verifyCompensation() returns only once every obligation is verified; otherwise its activity fails.
        self::cancellationShield(static fn () => $machine->scope->verifyCompensation());
        return [
            'businessOutcome' => 'compensated',
            'originalRunId' => $this->runId(),
        ];
    }
}
