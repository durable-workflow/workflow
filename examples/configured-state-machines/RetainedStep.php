<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use LogicException;
use Workflow\V2\Compensation\Activities\RequestScopeActivity;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\StartedChildWorkflow;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Execution\ExecutionReceipt;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Transition\AssessedTransitionStep;
use Workflow\V2\StateMachine\Transition\TransitionContext;
use Workflow\V2\Workflow;

/** Both Order and Shipment use the same four-operation protocol and native Saga. */
final class RetainedStep extends AssessedTransitionStep
{
    public function __construct(
        private readonly DurableScope $scope,
        private readonly ScopeIdentity $identity,
        private readonly string $principal,
        private readonly array $initialization
    ) {
    }

    public function execute(TransitionContext $context): ExecutionReceipt
    {
        $execution = $context->execution();
        $key = $context->transition->key;
        $operations = [];
        $actions = match ($key) {
            'begin' => Catalogue::actions($this->initialization['integration']),
            'deliver' => ['deliver'],
            default => [],
        };
        foreach ($actions as $action) {
            $result = $this->scope->execute(new Obligation(
                $execution->executionId . '.' . $action,
                'configured.sql.' . $action,
                '1',
                [
                    'action' => $action,
                    'execution' => $execution->toArray(),
                ]
            ));
            if (($result['status'] ?? null) !== 'active') {
                throw new BusinessCancellationRequested('The original SQL operation was permanently fenced.');
            }
            $operations[] = $result['operationId'];
        }
        if ($key === 'begin') {
            $childScope = Ownership::childScope($execution);
            $reservation = $this->scope->reserveChild(
                $execution->executionId . '.shipment',
                $childScope,
                Ownership::binding($execution, $this->initialization['rootRun'], $this->initialization['integration'])
            );
            $existing = Workflow::activity(BoundaryActivity::class, 'binding', [
                'reservation' => $reservation->toArray(),
            ]);
            if ($existing === null) {
                $reservation->start(
                    $execution->subject,
                    $this->initialization['rootRun'],
                    $execution->toArray(),
                    $childScope->toArray()
                );
                $existing = Workflow::activity(BoundaryActivity::class, 'record-start', [
                    'reservation' => $reservation->toArray(),
                ]);
            }
            $binding = ChildBinding::fromArray($existing);
            $operations[] = 'child:' . $binding->admission->childRunId;
        } elseif ($key === 'finish') {
            $value = Workflow::activity(BoundaryActivity::class, 'active-binding', []);
            $binding = ChildBinding::fromArray($value);
            $outcome = StartedChildWorkflow::fromArray($binding->admission->toArray())->await();
            Workflow::activity(BoundaryActivity::class, 'outcome', [
                'binding' => $value,
                'outcome' => $outcome,
            ]);
        } elseif ($key !== 'deliver') {
            throw new LogicException('A proof-only transition must not dispatch work.');
        }
        return new ExecutionReceipt($execution, $operations);
    }

    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void
    {
        Workflow::activity(RequestScopeActivity::class, $this->identity->toArray(), $this->principal);
        // Scope callbacks registered within execute() run first. Check only this step's original subset:
        // verifying the whole scope here would block earlier steps before their callbacks can run.
        Workflow::activity(BoundaryActivity::class, 'verify-step', [
            'execution' => $originalExecution->toArray(),
            'key' => $context->transition->key,
        ]);
    }
}
