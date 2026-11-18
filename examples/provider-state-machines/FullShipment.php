<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use LogicException;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\Workflow;

#[Type('example.provider.shipment.full.v1')]
#[Signal('physical-milestone')]
final class FullShipment extends Workflow
{
    public function handle(string $key, string $rootRun, array $entry, array $scope): array
    {
        $machine = new Machine($this, self::activity(BoundaryActivity::class, 'initialize', [
            'arguments' => [$key, $rootRun, $entry, $scope],
        ]));
        try {
            $machine->registerOwner();
            $physical = $machine->scope->register(new Obligation(
                'shipment.fulfillment',
                'provider.shipment.full',
                '1',
                $machine->initialization['descriptor']
            ));
            self::activity(ShipmentBoundaryActivity::class, 'register');
            $step = new ShipmentStep($physical);
            foreach (['book', 'handover', 'deliver'] as $stage) {
                $incident = $stage === 'book' ? null : $this->awaitStage($machine, $stage);
                if ($incident !== null) {
                    // The separate incident process owns the remedy; handover and deduction stay historical.
                    return [
                        'businessOutcome' => $incident['incident'],
                        'originalRunId' => $this->runId(),
                        'incidentRun' => $incident['runId'],
                    ];
                }
                $result = $machine->transition($stage, $step);
                if ($result->status !== TransitionStatus::Committed) {
                    return [
                        'businessOutcome' => 'unsettled',
                        'transition' => $result->toArray(),
                    ];
                }
            }
            self::activity(ShipmentBoundaryActivity::class, 'notify');
            return $machine->outcome($result);
        } catch (WorkflowCancellationRequestedException|BusinessCancellationRequested $error) {
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

    private function awaitStage(Machine $machine, string $stage): ?array
    {
        for ($wake = 0; $wake < 32; ++$wake) {
            $snapshot = $machine->observe($stage);
            if ($snapshot->permission->satisfied()) {
                return null;
            }
            if ($stage === 'deliver' && isset($machine->initialization['transitPolicy'])) {
                // Only the carrier's confirmed loss or damage ends this wait; a tracking delay keeps waiting.
                $incident = self::activity(ShipmentBoundaryActivity::class, 'incident');
                if ($incident['incident'] !== null) {
                    return $incident;
                }
            }
            $remaining = $machine->initialization['fulfillmentDeadline'] - self::now()->getTimestamp();
            if ($remaining <= 0) {
                self::activity(ShipmentBoundaryActivity::class, 'expire');
                throw new BusinessCancellationRequested('The original physical fulfillment deadline expired.');
            }
            self::await('physical-milestone', $remaining);
        }
        throw new LogicException('The bounded original physical milestone wait remains unresolved.');
    }
}
