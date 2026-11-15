<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Compensation\Activities\RequestScopeActivity;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Execution\ExecutionReceipt;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Transition\AssessedTransitionStep;
use Workflow\V2\StateMachine\Transition\TransitionContext;
use Workflow\V2\Workflow;

/** Payment, booking and Order all implement the same four-operation transition contract. */
final class RetainedStep extends AssessedTransitionStep
{
    public function __construct(
        private readonly DurableScope $scope,
        private readonly ScopeIdentity $identity,
        private readonly array $initialization,
    ) {
    }

    public function execute(TransitionContext $context): ExecutionReceipt
    {
        $execution = $context->execution();
        if ($this->initialization['role'] === 'order' && in_array($context->transition->key, ['pay', 'book'], true)) {
            $role = $context->transition->key === 'pay' ? 'payment' : 'shipment';
            foreach ($this->initialization['children'] as $child) {
                $binding = BindingIdentity::fromArray($child['binding']);
                if ($binding->slot !== $role) {
                    continue;
                }
                if (! $binding->parentExecution->equals($execution)) {
                    throw new LogicException('The child belongs to a different original state-entry execution.');
                }
                $reservation = $this->scope->reserveChild($role, ScopeIdentity::fromArray($child['scope']), $binding);
                $started = $reservation->start(...$child['input']);
                $outcome = $started->await();
                if (! is_array($outcome)) {
                    throw new LogicException('The child did not return its original business publication.');
                }
                Workflow::activity(BoundaryActivity::class, 'observe', [
                    'binding' => (new ChildBinding($binding, $started))->toArray(),
                    'outcome' => $outcome,
                ]);
                return new ExecutionReceipt($execution, ['original-child:' . $started->childRunId]);
            }
            throw new LogicException('The pinned child slot is unavailable.');
        }
        if ($context->transition->key === 'cancel') {
            throw new LogicException('The proof-only cancellation transition cannot dispatch work.');
        }
        $result = $this->scope->execute(
            new Obligation('effect', 'provider.effect', '1', $this->initialization['descriptor'])
        );
        return new ExecutionReceipt($execution, ['original-provider:' . $result['operation']]);
    }

    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void
    {
        Workflow::activity(RequestScopeActivity::class, $this->identity->toArray(), $this->initialization['principal']);
        // Nested native obligation callbacks run first. Verify this original step, not earlier parent work.
        Workflow::activity(BoundaryActivity::class, 'verify-step', [
            'execution' => $originalExecution->toArray(),
            'key' => $context->transition->key,
        ]);
    }
}
