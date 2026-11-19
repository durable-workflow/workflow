<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use LogicException;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\Workflow;

#[Type('example.configured.shipment.v1')]
final class Shipment extends Workflow
{
    public function handle(string $subject, string $rootRun, array $begin, array $identity): array
    {
        $initialization = self::activity(BoundaryActivity::class, 'initialize', compact('subject', 'rootRun', 'begin'));
        $execution = MachineAuthority::reference($begin);
        $machine = new MachineExecution($this, $initialization, $initialization['principal'], $execution->executionId);
        if (PortableDescriptor::encode($identity) !== PortableDescriptor::encode($machine->identity->toArray())) {
            throw new LogicException('Shipment has a different recorded compensation generation.');
        }
        try {
            $owner = $machine->registerOwner($execution->executionId);
            if ($owner['status'] === 'fenced') {
                throw new BusinessCancellationRequested('Shipment initialization observed business cancellation.');
            }
            $result = $machine->transition('deliver');
            if ($result->status !== TransitionStatus::Committed) {
                return $result->toArray();
            }
            return [
                'subject' => $subject,
                'originalRunId' => $this->runId(),
                'businessOutcome' => 'completed',
            ];
        } catch (WorkflowCancellationRequestedException|BusinessCancellationRequested $error) {
            self::cancellationShield(fn () => $this->compensate());
            // verifyCompensation() returns only once every obligation is verified; otherwise its activity fails.
            $resolution = self::cancellationShield(static fn () => $machine->scope->verifyCompensation());
            return [
                'businessOutcome' => 'compensated',
                'scope' => $resolution,
            ];
        }
    }
}
