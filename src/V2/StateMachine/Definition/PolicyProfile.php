<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Definition;

use InvalidArgumentException;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/**
 * A pinned application definition. Selection and authorization belong to the application.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class PolicyProfile
{
    public string $machine;

    public string $definitionVersion;

    public string $profile;

    public string $profileVersion;

    /**
     * @var non-empty-list<string>
     */
    public array $states;

    /**
     * @var list<TransitionDefinition>
     */
    public array $transitions;

    /**
     * @var array<string, ConditionDefinition>
     */
    public array $conditions;

    public ?MachineDefinition $definition;

    private ?ExecutionLimits $executionLimits;

    /**
     * @param non-empty-list<string> $states
     * @param list<TransitionDefinition> $transitions
     * @param array<string, ConditionDefinition> $conditions
     */
    public function __construct(
        string|MachineDefinition $machine,
        string $definitionVersion,
        string $profile,
        string $profileVersion,
        array $states,
        array $transitions,
        array $conditions = [],
        ?ExecutionLimits $limits = null,
    ) {
        $definition = $machine instanceof MachineDefinition ? $machine : null;
        $machine = $definition?->machine ?? $machine;
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
        foreach ($conditions as $name => $condition) {
            if (! is_string($name) || ! $condition instanceof ConditionDefinition) {
                throw new InvalidArgumentException('Named policy conditions require condition definitions.');
            }
            PortableDescriptor::identifier($name, 'policy condition');
        }
        $definition?->assertProfile($machine, $definitionVersion, $states, $transitions, $conditions);
        $this->machine = $machine;
        $this->definitionVersion = $definitionVersion;
        $this->profile = $profile;
        $this->profileVersion = $profileVersion;
        $this->states = array_map(static fn (string $state): string => $state, $states);
        $this->transitions = array_map(static fn (TransitionDefinition $transition): TransitionDefinition =>
            $transition, $transitions);
        $this->conditions = array_map(static fn (ConditionDefinition $condition): ConditionDefinition =>
            $condition, $conditions);
        $this->definition = $definition;
        $this->executionLimits = $limits;
    }

    /**
     * @param list<TransitionDefinition> $transitions
     * @param array<string, ConditionDefinition> $conditions
     */
    public static function forDefinition(
        MachineDefinition $definition,
        string $profile,
        string $profileVersion,
        array $transitions,
        array $conditions = [],
        ?ExecutionLimits $limits = null,
    ): self {
        return new self(
            $definition,
            $definition->version,
            $profile,
            $profileVersion,
            $definition->stateIds(),
            $transitions,
            $conditions,
            $limits
        );
    }

    public function limits(): ?ExecutionLimits
    {
        return $this->executionLimits;
    }

    /**
     * Return a new pinned profile; the original assignment remains unchanged.
     */
    public function withLimits(ExecutionLimits $limits): self
    {
        return new self(
            $this->definition ?? $this->machine,
            $this->definitionVersion,
            $this->profile,
            $this->profileVersion,
            $this->states,
            $this->transitions,
            $this->conditions,
            $limits
        );
    }

    public function fingerprint(): string
    {
        $material = [
            $this->machine, $this->definitionVersion, $this->profile, $this->profileVersion,
            $this->states, array_map(
                static fn (TransitionDefinition $transition): array => get_object_vars($transition),
                $this->transitions
            ),
        ];
        // Preserve recorded fingerprints for the existing, condition-free profiles.
        if ($this->conditions !== []) {
            $conditions = array_map(static fn (ConditionDefinition $condition): string =>
                $condition->fingerprint(), $this->conditions);
            ksort($conditions, SORT_STRING);
            $material[] = [
                'condition_fingerprints' => $conditions,
            ];
        }
        if ($this->definition !== null) {
            $material[] = [
                'machine_definition_fingerprint' => $this->definition->fingerprint(),
            ];
        }
        $limits = $this->limits();
        if ($limits !== null) {
            $material[] = [
                'execution_limits' => $limits->toArray(),
            ];
        }
        return hash('sha256', json_encode($material, JSON_THROW_ON_ERROR));
    }

    public function condition(string $name): ConditionDefinition
    {
        return $this->conditions[$name] ?? throw new LogicException('The pinned policy condition is unavailable.');
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
