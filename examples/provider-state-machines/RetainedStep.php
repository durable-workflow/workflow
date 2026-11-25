<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use LogicException;
use Workflow\V2\Compensation\Activities\RequestScopeActivity;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Compensation\RegisteredObligation;
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
        if ($this->initialization['role'] === 'order' && $context->transition->key === 'prepare') {
            return $this->prepare($context);
        }
        if ($this->initialization['role'] === 'order' && in_array($context->transition->key, ['pay', 'book'], true)) {
            $role = $context->transition->key === 'pay' ? 'payment' : 'shipment';
            return $this->executeChild($context, $role);
        }
        if ($context->transition->key === 'cancel') {
            throw new LogicException('The proof-only cancellation transition cannot dispatch work.');
        }
        $result = $this->scope->execute(
            new Obligation(
                'effect',
                $this->initialization['effectHandler'] ?? 'provider.effect',
                '1',
                $this->initialization['descriptor']
            )
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

    private function prepare(TransitionContext $context): ExecutionReceipt
    {
        if ($context->transition->handler !== 'prepare.' . $this->initialization['recipe']
            || $context->transition->handlerVersion !== '1') {
            throw new LogicException('The original checkout policy action changed.');
        }
        // Register stock before Payment in both policies. Forward dispatch may happen later,
        // while native reverse compensation still requires refund before reservation release.
        $reservation = $this->scope->register(new Obligation(
            'effect',
            $this->initialization['stockHandler'] ?? 'provider.effect',
            '1',
            $this->initialization['descriptor']
        ));
        $operations = [];
        foreach (Catalogue::preparation($this->initialization['recipe']) as $action) {
            $next = match ($action) {
                'payment' => $this->executeChild($context, 'payment')
->operations,
                'reserve' => [$this->reserveOriginal($reservation), ...$this->selectStock()],
                default => throw new LogicException('The pinned checkout action is unavailable.'),
            };
            $operations = [...$operations, ...$next];
        }
        return new ExecutionReceipt($context->execution(), $operations);
    }

    /**
     * Apply the trusted original partial-stock policy inside this one admitted preparation.
     * Every allocation, deadline and refund observation is a recorded host result.
     */
    private function selectStock(): array
    {
        // The plan follows the confirmed reservation: after capture on payment-first, before it on
        // reserve-first. A cancel plan before capture leaves no money to settle.
        if (! isset($this->initialization['stockPolicy'])) {
            return [];
        }
        $plan = StockSelectionWait::plan($this->initialization['fulfillmentDeadline'] ?? null);
        return [
            'original-stock-plan:' . $plan['reservationFingerprint'] . ':' . $plan['allocationRevision'],
            ...$this->repayShortage($plan),
        ];
    }

    private function repayShortage(array $plan): array
    {
        if ($plan['requiredRefundUnits'] === []) {
            return [];
        }
        $obligation = Obligation::fromArray(Workflow::activity(BoundaryActivity::class, 'stock-shortage', [
            'observe' => false,
        ]));
        $result = $this->scope->execute($obligation);
        // Booking waits for confirmed repayment; processing, unknown and refused outcomes stay outstanding.
        for ($wake = 0; ($result['confirmedUnits'] ?? null) !== $plan['requiredRefundUnits']; ++$wake) {
            if ($wake >= 32) {
                throw new LogicException('The bounded original shortage repayment wait remains unresolved.');
            }
            Workflow::await('reconcile-stock');
            $result = Workflow::activity(BoundaryActivity::class, 'stock-shortage', [
                'observe' => true,
            ]);
        }
        return ['original-shortage-repayment:' . $obligation->fingerprint()];
    }

    private function reserveOriginal(RegisteredObligation $reservation): string
    {
        $result = $reservation->execute();
        // A known pending reservation stays inside this one admitted native execution.
        // Each observation is recorded; a signal never re-admits or repeats the command.
        while (true) {
            $stock = Workflow::activity(BoundaryActivity::class, 'stock-refusal', []);
            if ($stock['confirmed']) {
                return 'original-provider:' . $result['operation'];
            }
            if ($stock['refused']) {
                Workflow::activity(BoundaryActivity::class, 'cancel-stock-refusal', []);
                throw new BusinessCancellationRequested('The original reservation was definitively refused.');
            }
            Workflow::await('reconcile-stock');
        }
    }

    private function executeChild(TransitionContext $context, string $role): ExecutionReceipt
    {
        $execution = $context->execution();
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
}
