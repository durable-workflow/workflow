<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Closure;
use Throwable;
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

    private function compensateFunding(Machine $machine, Throwable $error): array
    {
        self::cancellationShield(fn () => $this->compensate());
        $proof = self::cancellationShield(static fn () => $machine->scope->verifyCompensation());
        if (($proof['status'] ?? null) !== 'verified') {
            throw $error;
        }
        return [
            'businessOutcome' => 'compensated',
            'originalRunId' => $this->runId(),
        ];
    }
}
