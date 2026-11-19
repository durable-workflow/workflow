<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Definition;

use InvalidArgumentException;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Rules\ConditionEvaluation;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\RuleRegistry;

/**
 * A shared, pinned machine contract. Profiles select edges and handlers within this definition.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class MachineDefinition
{
    /**
     * @var non-empty-list<MachineState>
     */
    public array $states;

    /**
     * @var list<string>
     */
    public array $transitionKeys;

    /**
     * @var list<ChildSlot>
     */
    public array $slots;

    /**
     * @param non-empty-list<MachineState> $states
     * @param list<string> $transitionKeys
     * @param list<ChildSlot> $slots
     */
    public function __construct(
        public string $machine,
        public string $version,
        array $states,
        array $transitionKeys,
        public ConditionDefinition $invariants,
        array $slots = [],
        public ?DefinitionLimits $limits = null,
    ) {
        if (trim($machine) === '' || trim($version) === '') {
            throw new InvalidArgumentException('Machine identity and definition version must not be empty.');
        }
        $limits?->guard('states', count($states));
        $limits?->guard('transitions', count($transitionKeys));
        $limits?->guard('child_slots', count($slots));
        $limits?->guardRules($invariants, []);
        $this->states = self::stateDefinitions($states);
        $this->transitionKeys = self::transitionIdentities($transitionKeys);
        $this->slots = self::childSlots($slots, $this->stateIds());
        PortableDescriptor::encode($this->toArray());
    }

    /**
     * @return non-empty-list<string>
     */
    public function stateIds(): array
    {
        return array_map(static fn (MachineState $state): string => $state->id, $this->states);
    }

    public function fingerprint(): string
    {
        return hash('sha256', PortableDescriptor::encode($this->toArray()));
    }

    public function toArray(): array
    {
        return [
            'version' => 1,
            'machine' => $this->machine,
            'definitionVersion' => $this->version,
            'states' => array_map(static fn (MachineState $state): array => $state->toArray(), $this->states),
            'transitions' => $this->transitionKeys,
            'invariantFingerprint' => $this->invariants->fingerprint(),
            'slots' => array_map(static fn (ChildSlot $slot): array => $slot->toArray(), $this->slots),
            ...($this->limits === null ? [] : [
                'limits' => $this->limits->toArray(),
            ]),
        ];
    }

    /**
     * Collect facts at a recorded boundary; this method itself evaluates only immutable data.
     */
    public function assess(RuleRegistry $registry, RecordedFacts $facts): ConditionEvaluation
    {
        return $registry->evaluateCondition($this->invariants, $facts);
    }

    public function slot(string $key): ChildSlot
    {
        foreach ($this->slots as $slot) {
            if ($slot->key === $key) {
                return $slot;
            }
        }
        throw new LogicException('The pinned machine child slot is unavailable.');
    }

    /**
     * @param list<string> $states @param list<TransitionDefinition> $transitions
     */
    public function assertProfile(
        string $machine,
        string $version,
        array $states,
        array $transitions,
        array $conditions = []
    ): void {
        if ($machine !== $this->machine || $version !== $this->version || $states !== $this->stateIds()) {
            throw new InvalidArgumentException('The policy cannot redefine its shared machine identity or states.');
        }
        foreach ($transitions as $transition) {
            if (! $transition instanceof TransitionDefinition || ! in_array(
                $transition->key,
                $this->transitionKeys,
                true
            )) {
                throw new InvalidArgumentException('The policy transition is outside its shared machine definition.');
            }
        }
        $this->limits?->guardRules($this->invariants, $conditions);
        $this->limits?->guardEdges($transitions);
    }

    /**
     * Pure definition compatibility, not host authorization, native admission or outcome evidence.
     */
    public function assertBinding(PolicyProfile $parent, BindingIdentity $binding, PolicyProfile $child): void
    {
        $slot = $this->slot($binding->slot);
        if ($parent->definition?->fingerprint() !== $this->fingerprint()
            || $binding->parentExecution->policyFingerprint !== $parent->fingerprint()
            || ! in_array($binding->hostingState, $slot->hostingStates, true)
            || $child->machine !== $slot->childMachine || $child->definitionVersion !== $slot->childDefinitionVersion
            || $binding->childPolicyFingerprint !== $child->fingerprint()
            || $binding->requiredOutcome !== $slot->requiredOutcome) {
            throw new LogicException('The child binding does not implement its pinned machine slot.');
        }
    }

    /**
     * @return non-empty-list<MachineState>
     */
    private static function stateDefinitions(array $states): array
    {
        if ($states === [] || ! array_is_list($states)) {
            throw new InvalidArgumentException('Machine states require a nonempty ordered list.');
        }
        $ids = [];
        foreach ($states as $state) {
            if (! $state instanceof MachineState || in_array($state->id, $ids, true)) {
                throw new InvalidArgumentException('Machine states require distinct typed identities.');
            }
            $ids[] = $state->id;
        }
        return array_map(static fn (MachineState $state): MachineState => $state, $states);
    }

    /**
     * @return list<string>
     */
    private static function transitionIdentities(array $transitionKeys): array
    {
        if (! array_is_list($transitionKeys)) {
            throw new InvalidArgumentException('Machine transition identities require an ordered list.');
        }
        foreach ($transitionKeys as $key) {
            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException('Machine transition identities must be strings.');
            }
        }
        if (count(array_unique($transitionKeys)) !== count($transitionKeys)) {
            throw new InvalidArgumentException('Machine transition identities must be distinct.');
        }
        return array_map(static fn (string $key): string => $key, $transitionKeys);
    }

    /**
     * @param list<string> $ids @return list<ChildSlot>
     */
    private static function childSlots(array $slots, array $ids): array
    {
        if (! array_is_list($slots)) {
            throw new InvalidArgumentException('Machine child slots require an ordered list.');
        }
        $keys = [];
        foreach ($slots as $slot) {
            if (! $slot instanceof ChildSlot || in_array($slot->key, $keys, true)) {
                throw new InvalidArgumentException('A child slot requires a distinct typed identity.');
            }
            foreach ($slot->hostingStates as $state) {
                if (! in_array($state, $ids, true)) {
                    throw new InvalidArgumentException('A child slot cannot host an undeclared parent state.');
                }
            }
            $keys[] = $slot->key;
        }
        return array_map(static fn (ChildSlot $slot): ChildSlot => $slot, $slots);
    }
}
