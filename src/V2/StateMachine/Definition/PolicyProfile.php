<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Definition;

use InvalidArgumentException;

/** A pinned application definition. Selection and authorization belong to the application. */
final readonly class PolicyProfile
{
    /**
     * @param non-empty-list<string> $states
     * @param list<TransitionDefinition> $transitions
     */
    public function __construct(
        public string $machine,
        public string $definitionVersion,
        public string $profile,
        public string $profileVersion,
        public array $states,
        public array $transitions,
    ) {
        foreach ([$machine, $definitionVersion, $profile, $profileVersion] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Definition and profile identifiers must not be empty.');
            }
        }
        if ($states === [] || ! array_is_list($states) || ! array_is_list($transitions)) {
            throw new InvalidArgumentException('States and transitions must be lists.');
        }
        foreach ($states as $state) {
            if (! is_string($state) || trim($state) === '') {
                throw new InvalidArgumentException('State identifiers must be non-empty strings.');
            }
        }
        if (count(array_unique($states)) !== count($states)) {
            throw new InvalidArgumentException('State identifiers must be unique.');
        }
        $keys = [];
        foreach ($transitions as $transition) {
            if (! $transition instanceof TransitionDefinition || in_array($transition->key, $keys, true)) {
                throw new InvalidArgumentException('Transition definitions must have unique keys.');
            }
            foreach ([...$transition->from, $transition->to] as $state) {
                if (! in_array($state, $states, true)) {
                    throw new InvalidArgumentException('A transition cannot reference an undeclared state.');
                }
            }
            $keys[] = $transition->key;
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->machine, $this->definitionVersion, $this->profile, $this->profileVersion,
            $this->states, array_map(
                static fn (TransitionDefinition $transition): array => get_object_vars($transition),
                $this->transitions
            ),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<TransitionDefinition>
     */
    public function candidates(string $event, string $state): array
    {
        return array_values(array_filter($this->transitions, static fn (TransitionDefinition $transition): bool =>
            $transition->event === $event && in_array($state, $transition->from, true)));
    }
}
