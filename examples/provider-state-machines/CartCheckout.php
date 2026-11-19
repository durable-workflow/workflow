<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use LogicException;
use RuntimeException;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\RegisteredObligation;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;

#[Type('example.provider.order.cart.v1')]
#[Signal('funding-milestone')]
#[Signal('reconcile-stock')]
#[Signal('fulfill')]
#[Signal('physical-milestone')]
final class CartCheckout extends AuthorizationCheckout
{
    public function handle(string $key): array
    {
        $machine = new Machine($this, Workflow::activity(BoundaryActivity::class, 'initialize', [
            'arguments' => [$key],
        ]));
        try {
            $machine->registerOwner();
            $plan = CartPlan::fromArray(Workflow::activity(CartBoundaryActivity::class, 'plan', []));
            // Both stock duties precede Payment, preserving refund-before-stock-release in reverse order.
            $initial = $machine->scope->register($plan->obligation('initial'));
            $late = $machine->scope->register($plan->obligation('late'));
            $initialObserved = $this->attemptReservation($initial);
            $binding = $this->startPayment($machine);
            $step = $this->selectReservation($plan, $initial, $late, $initialObserved, $binding);
            Workflow::activity(CartBoundaryActivity::class, 'associate', [
                'binding' => $binding,
                'step' => $step,
            ]);
            return $this->checkout($machine, true, new CartPreparationStep());
        } catch (WorkflowCancellationRequestedException|BusinessCancellationRequested $error) {
            Workflow::cancellationShield(static fn () => Workflow::activity(CartBoundaryActivity::class, 'cancel', [
                'reason' => 'cart-cancelled',
            ]));
            Workflow::cancellationShield(fn () => $this->compensate());
            // verifyCompensation() returns only once every obligation is verified; otherwise its activity fails.
            Workflow::cancellationShield(static fn () => $machine->scope->verifyCompensation());
            return Workflow::cancellationShield(static fn () => $machine->transition('cancel')->toArray());
        }
    }

    private function startPayment(Machine $machine): array
    {
        $child = $machine->initialization['children'][0];
        $identity = BindingIdentity::fromArray($child['binding']);
        if ($identity->slot !== 'payment') {
            throw new LogicException('The cart must retain its original Payment child slot.');
        }
        $reservation = $machine->scope->reserveChild(
            'payment',
            ScopeIdentity::fromArray($child['scope']),
            $identity
        );
        $started = $reservation->start(...$child['input']);
        return (new ChildBinding($identity, $started))->toArray();
    }

    private function selectReservation(
        CartPlan $plan,
        RegisteredObligation $initial,
        RegisteredObligation $late,
        bool $initialObserved,
        array $binding
    ): string {
        $selectedLate = false;
        $lateStarted = false;
        $lateObserved = false;
        for ($wake = 0; $wake < 32; ++$wake) {
            [$facts, $initialObserved] = $this->observeInitial($initial, $initialObserved, $binding);
            $initialDeadlinePassed = $facts['initial']['deadlinePassed'];
            if ($this->initialReady($plan, $facts)) {
                return 'initial';
            }
            $selectedLate = $this->expireIfDue($facts, $selectedLate, $binding);
            if ($selectedLate && $facts['payment']['confirmed']) {
                [$facts, $lateObserved] = $this->observeLate($late, $lateStarted, $lateObserved, $facts, $binding);
                $lateStarted = true;
                if ($facts['late']['reservationStatus'] === Status::Confirmed->value && ! $facts['late']['released']) {
                    return 'late';
                }
            }
            $this->waitForChange($plan, $facts, $selectedLate, $initialDeadlinePassed);
        }
        throw new LogicException('The bounded original cart reconciliation remains unresolved.');
    }

    private function observeInitial(RegisteredObligation $initial, bool $observed, array $binding): array
    {
        $facts = Workflow::activity(CartBoundaryActivity::class, 'observe', [
            'binding' => $binding,
        ]);
        $stock = $facts['initial'];
        if (! $observed && $stock['reservationStatus'] !== Status::Unknown->value) {
            $observed = $this->attemptReservation($initial);
        }
        if ($stock['reservationStatus'] === Status::Refused->value) {
            throw new BusinessCancellationRequested('The original cart reservation was definitively refused.');
        }
        return [$facts, $observed];
    }

    private function initialReady(CartPlan $plan, array $facts): bool
    {
        $stock = $facts['initial'];
        return $facts['payment']['confirmed'] && $facts['paidBeforeInitialExpiry'] && ! $stock['released']
            && ! $stock['admissionClosed'] && $stock['reservationStatus'] === Status::Confirmed->value
            && $stock['heldQuantity'] === $plan->initial->terms->quantity;
    }

    private function expireIfDue(array $facts, bool $selectedLate, array $binding): bool
    {
        $stock = $facts['initial'];
        if ($stock['deadlinePassed'] && ! $selectedLate
            && (! $facts['paidBeforeInitialExpiry'] || $stock['admissionClosed'] || $stock['released'])) {
            $expired = Workflow::activity(CartBoundaryActivity::class, 'expire', [
                'binding' => $binding,
            ]);
            if ($expired['released']) {
                Workflow::activity(CartBoundaryActivity::class, 'select-late', []);
                $selectedLate = true;
            }
        }
        return $selectedLate;
    }

    private function observeLate(
        RegisteredObligation $late,
        bool $started,
        bool $observed,
        array $facts,
        array $binding
    ): array {
        if (! $started || (! $observed && $facts['late']['reservationStatus'] !== Status::Unknown->value)) {
            $observed = $this->attemptReservation($late);
        }
        $facts = Workflow::activity(CartBoundaryActivity::class, 'observe', [
            'binding' => $binding,
        ]);
        if ($facts['late']['reservationStatus'] === Status::Refused->value) {
            throw new BusinessCancellationRequested('The new post-expiry reservation was definitively refused.');
        }
        return [$facts, $observed];
    }

    private function waitForChange(CartPlan $plan, array $facts, bool $selectedLate, bool $initialDeadlinePassed): void
    {
        $now = max($facts['initial']['observedAt'], $facts['late']['observedAt']);
        if ($now >= $plan->paymentObservationDeadline) {
            throw new BusinessCancellationRequested('The original cart payment observation window expired.');
        }
        $next = $selectedLate || $initialDeadlinePassed ? $plan->paymentObservationDeadline : min(
            $plan->initial->terms->expiresAt,
            $plan->paymentObservationDeadline
        );
        $wait = max(1, $next - $now);
        $selected = Workflow::select([
            'money' => static fn () => Workflow::await('funding-milestone', $wait),
            'stock' => static fn () => Workflow::await('reconcile-stock', $wait),
        ]);
        $selected->result();
        foreach ($selected->remaining() as $handle) {
            $handle->cancel();
        }
    }

    private function attemptReservation(RegisteredObligation $stock): bool
    {
        try {
            $stock->execute(new ActivityOptions(maxAttempts: 1));
            return true;
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'scope_forward_unknown') {
                throw $error;
            }
            // Keep the native obligation uncertain. Only a later recorded provider observation may
            // resume its same-key execution; an unknown outcome cannot authorize refund or checkout.
            return false;
        }
    }
}
