<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use LogicException;
use Workflow\V2\Compensation\RegisteredObligation;
use Workflow\V2\StateMachine\Execution\ExecutionReceipt;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Transition\AssessedTransitionStep;
use Workflow\V2\StateMachine\Transition\TransitionContext;
use Workflow\V2\Workflow;

final class BillingPeriodStep extends AssessedTransitionStep
{
    public function __construct(
        private readonly BillingPeriod $period,
        private readonly RegisteredObligation $payment
    ) {
    }

    public function execute(TransitionContext $context): ExecutionReceipt
    {
        if ($context->transition->key === 'prepare') {
            $this->payment->execute();
        }
        for ($wake = 0; $wake < 32; ++$wake) {
            $value = Workflow::activity(BillingPeriodBoundaryActivity::class, 'observe', []);
            if ($value['cancelled']) {
                throw new BusinessCancellationRequested('The original period was cancelled.');
            }
            $now = Workflow::now()->getTimestamp();
            if ($value['receipt']['status'] === Status::Confirmed->value || $now >= $this->period->endsAt) {
                return new ExecutionReceipt($context->execution(), [
                    'billing-observed:' . $context->execution()->executionId,
                ]);
            }
            $deadline = $now < $this->period->paymentDeadline ? $this->period->paymentDeadline : $this->period->endsAt;
            Workflow::await('payment-observed', max(0, $deadline - $now));
        }
        throw new LogicException('The bounded original-period observation remains unresolved.');
    }

    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void
    {
        if (! $context->execution()->equals($originalExecution)) {
            throw new LogicException('Period compensation must preserve its original execution.');
        }
        $this->payment->compensate();
    }
}
