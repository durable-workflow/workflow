<?php

declare(strict_types=1);

namespace Workflow\States;

use Exception;

class StateMachine
{
    /**
     * @var string[]
     */
    private array $states = [];

    /**
     * @var array<string, array{from: string, to: string}>
     */
    private array $transitions = [];

    private ?string $currentState = null;

    public function addState(string $state): void
    {
        if (! in_array($state, $this->states, true)) {
            $this->states[] = $state;
        }
    }

    public function addTransition(string $action, string $fromState, string $toState): void
    {
        $this->transitions[$action] = [
            'from' => $fromState,
            'to' => $toState,
        ];
    }

    public function initialize(?string $initialState = null): void
    {
        if ($initialState !== null) {
            $this->currentState = $initialState;

            return;
        }

        if (count($this->states) > 0) {
            $this->currentState = $this->states[0];
        }
    }

    public function getCurrentState(): ?string
    {
        return $this->currentState;
    }

    public function canApply(string $action): bool
    {
        return isset($this->transitions[$action])
            && $this->transitions[$action]['from'] === $this->currentState;
    }

    public function apply(string $action): void
    {
        if (! $this->canApply($action)) {
            throw new Exception('Transition not found.');
        }

        $this->currentState = $this->transitions[$action]['to'];
    }

    public function canTransition(string $fromState, string $toState): bool
    {
        foreach ($this->transitions as $transition) {
            if ($transition['from'] === $fromState && $transition['to'] === $toState) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    public function transitionableStates(string $fromState): array
    {
        $states = [];

        foreach ($this->transitions as $transition) {
            if ($transition['from'] !== $fromState) {
                continue;
            }

            $states[] = $transition['to'];
        }

        return $states;
    }
}
