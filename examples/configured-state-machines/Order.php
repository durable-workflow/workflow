<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\Workflow;

#[Type('example.configured.order.v1')]
#[Signal('finish')]
final class Order extends Workflow
{
    public function handle(string $subject, string $event = 'begin'): array
    {
        $initialization = self::activity(BoundaryActivity::class, 'initialize', [
            'subject' => $subject,
            'rootRun' => $this->runId(),
            'begin' => null,
        ]);
        $machine = new MachineExecution($this, $initialization, $initialization['principal']);
        try {
            $owner = $machine->registerOwner();
            if ($owner['status'] === 'fenced') {
                throw new BusinessCancellationRequested('Initialization observed business cancellation.');
            }
            $result = $machine->transition($event);
            if ($event !== 'begin' || $result->status !== TransitionStatus::Committed) {
                return $result->toArray();
            }
            self::await('finish');
            return $machine->transition('finish')
                ->toArray();
        } catch (WorkflowCancellationRequestedException|BusinessCancellationRequested $error) {
            self::cancellationShield(fn () => $this->compensate());
            // verifyCompensation() returns only once every obligation is verified; otherwise its activity fails.
            $resolution = self::cancellationShield(static fn () => $machine->scope->verifyCompensation());
            // A separate proof-only transition commits business cancellation after this retained verification.
            return [
                'businessOutcome' => 'compensated',
                'scope' => $resolution,
            ];
        }
    }
}
