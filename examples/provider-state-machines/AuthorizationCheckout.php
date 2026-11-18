<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\StateMachine\Transition\TransitionStep;
use Workflow\V2\Workflow;

/** Common authorization Order route. Ready is a prerequisite milestone, never recipient delivery. */
#[Type('example.provider.order.authorized.v1')]
#[Signal('financial-milestone')]
#[Signal('reconcile-stock')]
#[Signal('fulfill')]
#[Signal('physical-milestone')]
class AuthorizationCheckout extends Workflow
{
    public function handle(string $key): array
    {
        $machine = new Machine($this, self::activity(BoundaryActivity::class, 'initialize', [
            'arguments' => [$key],
        ]));
        return $this->checkout($machine);
    }

    protected function checkout(
        Machine $machine,
        bool $ownerRegistered = false,
        ?TransitionStep $preparation = null
    ): array {
        try {
            if (! $ownerRegistered) {
                $machine->registerOwner();
            }
            $prepared = $machine->transition('prepare', $preparation ?? new FinancialPreparationStep($machine));
            if ($prepared->status !== TransitionStatus::Committed) {
                return [
                    'businessOutcome' => 'unsettled',
                    'transition' => $prepared->toArray(),
                ];
            }
            $deadline = $machine->initialization['fulfillmentDeadline'] ?? null;
            self::await('fulfill', $deadline === null ? null : max(0, $deadline - self::now()->getTimestamp()));
            if ($deadline !== null && self::now()->getTimestamp() >= $deadline) {
                self::activity(ShipmentBoundaryActivity::class, 'expire');
                throw new BusinessCancellationRequested('The original fulfillment request deadline expired.');
            }
            try {
                $fulfilled = $machine->transition('fulfill', new FulfillmentStep($machine));
            } catch (TransitIncidentObserved) {
                // Only opted-in remedy grants raise this; the Order never reports delivery or a false cancellation.
                $remedied = $machine->transition('remedy', new TransitRemedyStep($machine));
                return [
                    'businessOutcome' => $remedied->status === TransitionStatus::Committed ? 'remedied' : 'unsettled',
                    'originalRunId' => $this->runId(),
                    'transition' => $remedied->toArray(),
                ];
            }
            return [
                'businessOutcome' => $fulfilled->status === TransitionStatus::Committed ? 'fulfilled' : 'unsettled',
                'originalRunId' => $this->runId(),
                'transition' => $fulfilled->toArray(),
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
