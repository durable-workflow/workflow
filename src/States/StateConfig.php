<?php

declare(strict_types=1);

namespace Workflow\States;

use InvalidArgumentException;

final class StateConfig
{
    public string $baseStateClass;

    public ?string $defaultStateClass = null;

    /**
     * @var array<string, bool>
     */
    public array $allowedTransitions = [];

    /**
     * @var string[]
     */
    public array $registeredStates = [];

    public bool $shouldIgnoreSameState = false;

    public function __construct(string $baseStateClass)
    {
        $this->baseStateClass = $baseStateClass;
    }

    public function default(string $defaultStateClass): self
    {
        $this->defaultStateClass = $defaultStateClass;

        return $this;
    }

    public function ignoreSameState(): self
    {
        $this->shouldIgnoreSameState = true;

        return $this;
    }

    public function allowTransition($from, string $to): self
    {
        if (is_array($from)) {
            foreach ($from as $fromState) {
                $this->allowTransition($fromState, $to);
            }

            return $this;
        }

        if (! is_subclass_of($from, $this->baseStateClass)) {
            throw new InvalidArgumentException("{$from} does not extend {$this->baseStateClass}.");
        }

        if (! is_subclass_of($to, $this->baseStateClass)) {
            throw new InvalidArgumentException("{$to} does not extend {$this->baseStateClass}.");
        }

        $this->allowedTransitions[$this->createTransitionKey($from, $to)] = true;

        return $this;
    }

    /**
     * @param array<int, array<int, string>> $transitions
     */
    public function allowTransitions(array $transitions): self
    {
        foreach ($transitions as $transition) {
            $this->allowTransition($transition[0], $transition[1]);
        }

        return $this;
    }

    public function isTransitionAllowed(string $fromMorphClass, string $toMorphClass): bool
    {
        if ($this->shouldIgnoreSameState && $fromMorphClass === $toMorphClass) {
            return true;
        }

        return $this->stateMachine()
            ->canTransition($fromMorphClass, $toMorphClass);
    }

    /**
     * @return string[]
     */
    public function transitionableStates(string $fromMorphClass): array
    {
        return $this->stateMachine()
            ->transitionableStates($fromMorphClass);
    }

    /**
     * @param string|string[] $stateClass
     */
    public function registerState($stateClass): self
    {
        if (is_array($stateClass)) {
            foreach ($stateClass as $state) {
                $this->registerState($state);
            }

            return $this;
        }

        if (! is_subclass_of($stateClass, $this->baseStateClass)) {
            throw new InvalidArgumentException("{$stateClass} does not extend {$this->baseStateClass}.");
        }

        $this->registeredStates[] = $stateClass;

        return $this;
    }

    private function createTransitionKey(string $from, string $to): string
    {
        if (is_subclass_of($from, $this->baseStateClass)) {
            $from = $from::getMorphClass();
        }

        if (is_subclass_of($to, $this->baseStateClass)) {
            $to = $to::getMorphClass();
        }

        return "{$from}->{$to}";
    }

    private function stateMachine(): StateMachine
    {
        $stateMachine = new StateMachine();

        foreach (array_keys($this->allowedTransitions) as $allowedTransition) {
            [$from, $to] = explode('->', $allowedTransition, 2);

            $stateMachine->addState($from);
            $stateMachine->addState($to);
            $stateMachine->addTransition($allowedTransition, $from, $to);
        }

        return $stateMachine;
    }
}
