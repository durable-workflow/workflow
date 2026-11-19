<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\Workflow;

/** One lifecycle for both new policies. Legacy Orders retain their original definition and history. */
#[Type('example.provider.order.checkout.v1')]
#[Signal('finish')]
#[Signal('reconcile-stock')]
final class CheckoutOrder extends Workflow
{
    public function handle(string $key): array
    {
        $machine = new Machine($this, self::activity(BoundaryActivity::class, 'initialize', [
            'arguments' => [$key],
        ]));
        try {
            $machine->registerOwner();
            $transition = $machine->transition('prepare');
            if ($transition->status === TransitionStatus::Committed) {
                $transition = $machine->transition('book');
            }
            if ($transition->status !== TransitionStatus::Committed) {
                return [
                    'businessOutcome' => 'unsettled',
                    'transition' => $transition->toArray(),
                ];
            }
            self::await('finish');
            return [
                'businessOutcome' => 'booked',
                'originalRunId' => $this->runId(),
            ];
        } catch (WorkflowCancellationRequestedException|BusinessCancellationRequested $error) {
            self::cancellationShield(fn () => $this->compensate());
            // verifyCompensation() returns only once every obligation is verified; otherwise its activity fails.
            self::cancellationShield(static fn () => $machine->scope->verifyCompensation());
            $settled = self::cancellationShield(static fn () => $machine->transition('cancel'));
            return [
                'businessOutcome' => $settled->status === TransitionStatus::Committed ? 'cancelled' : 'unsettled',
                'originalRunId' => $this->runId(),
                'transition' => $settled->toArray(),
            ];
        }
    }
}
