<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\Workflow;

/** Financial preparation only so far. Ready does not assert recipient delivery or full fulfillment. */
#[Type('example.provider.order.authorized.v1')]
#[Signal('financial-milestone')]
#[Signal('finish')]
final class AuthorizationCheckout extends Workflow
{
    public function handle(string $key): array
    {
        $machine = new Machine($this, self::activity(BoundaryActivity::class, 'initialize', [
            'arguments' => [$key],
        ]));
        try {
            $machine->registerOwner();
            $prepared = $machine->transition('prepare', new FinancialPreparationStep($machine));
            if ($prepared->status !== TransitionStatus::Committed) {
                return [
                    'businessOutcome' => 'unsettled',
                    'transition' => $prepared->toArray(),
                ];
            }
            self::await('finish');
            return [
                'businessOutcome' => 'ready',
                'originalRunId' => $this->runId(),
            ];
        } catch (WorkflowCancellationRequestedException|BusinessCancellationRequested $error) {
            self::cancellationShield(fn () => $this->compensate());
            $proof = self::cancellationShield(static fn () => $machine->scope->verifyCompensation());
            if (($proof['status'] ?? null) !== 'verified') {
                throw $error;
            }
            $cancelled = self::cancellationShield(static fn () => $machine->transition('cancel'));
            return [
                'businessOutcome' => $cancelled->status === TransitionStatus::Committed ? 'cancelled' : 'unsettled',
                'originalRunId' => $this->runId(),
                'transition' => $cancelled->toArray(),
            ];
        }
    }
}
