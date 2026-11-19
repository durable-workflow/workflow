<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\Workflow;

#[Type('example.provider.order.v1')]
#[Signal('finish')]
final class Order extends Workflow
{
    public function handle(string $key): array
    {
        $machine = new Machine($this, self::activity(BoundaryActivity::class, 'initialize', [
            'arguments' => [$key],
        ]));
        try {
            $machine->registerOwner();
            foreach (['reserve', 'pay', 'book'] as $transition) {
                $result = $machine->transition($transition);
                if ($result->status !== TransitionStatus::Committed) {
                    return [
                        'businessOutcome' => 'unsettled',
                        'transition' => $result->toArray(),
                    ];
                }
            }
            self::await('finish');
            return [
                'businessOutcome' => 'booked',
                'originalRunId' => $this->runId(),
            ];
        } catch (WorkflowCancellationRequestedException|BusinessCancellationRequested $error) {
            self::cancellationShield(fn () => $this->compensate());
            $proof = self::cancellationShield(static fn () => $machine->scope->verifyCompensation());
            if (($proof['status'] ?? null) !== 'verified') {
                throw $error;
            }
            // Cancellation is a separate protected, proof-only business transition after all dependent work.
            return self::cancellationShield(static fn () => $machine->transition('cancel')->toArray());
        }
    }
}
