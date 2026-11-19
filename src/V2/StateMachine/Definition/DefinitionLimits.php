<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Definition;

use InvalidArgumentException;
use Workflow\V2\StateMachine\Execution\ResourceLimitExceeded;

/**
 * Pinned bounds on a shared machine and its configured policy conditions.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class DefinitionLimits
{
    public function __construct(
        public int $states,
        public int $transitions,
        public int $childSlots,
        public int $ruleNodes,
    ) {
        if ($states < 1 || $transitions < 0 || $childSlots < 0 || $ruleNodes < 1) {
            throw new InvalidArgumentException(
                'Machine definition limits require positive state/rule bounds and nonnegative edge/slot bounds.'
            );
        }
    }

    public function guard(string $dimension, int $count): void
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Machine resource counts must be nonnegative.');
        }
        $limit = match ($dimension) {
            'states' => $this->states,
            'transitions' => $this->transitions,
            'child_slots' => $this->childSlots,
            'rule_nodes' => $this->ruleNodes,
            default => throw new InvalidArgumentException('Unknown machine definition budget.'),
        };
        if ($count > $limit) {
            throw new ResourceLimitExceeded($dimension, $count, $limit);
        }
    }

    /**
     * @param array<string, ConditionDefinition> $conditions
     */
    public function guardRules(ConditionDefinition $invariants, array $conditions): void
    {
        $count = $invariants->nodeCount();
        $this->guard('rule_nodes', $count);
        foreach ($conditions as $condition) {
            if (! $condition instanceof ConditionDefinition) {
                throw new InvalidArgumentException('Policy conditions require typed condition definitions.');
            }
            $nodes = $condition->nodeCount();
            if ($nodes > $this->ruleNodes - $count) {
                throw new ResourceLimitExceeded('rule_nodes', $count + $nodes, $this->ruleNodes);
            }
            $count += $nodes;
        }
    }

    /**
     * @param list<TransitionDefinition> $transitions
     */
    public function guardEdges(array $transitions): void
    {
        $count = 0;
        foreach ($transitions as $transition) {
            $edges = count($transition->from);
            if ($edges > $this->transitions - $count) {
                throw new ResourceLimitExceeded('transitions', $count + $edges, $this->transitions);
            }
            $count += $edges;
        }
    }

    public function toArray(): array
    {
        return [
            'version' => 1,
            'states' => $this->states,
            'transitions' => $this->transitions,
            'childSlots' => $this->childSlots,
            'ruleNodes' => $this->ruleNodes,
        ];
    }
}
