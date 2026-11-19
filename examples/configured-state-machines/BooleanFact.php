<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use LogicException;
use Workflow\V2\StateMachine\Definition\RuleReference;
use Workflow\V2\StateMachine\Rules\ConditionRule;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\RuleEvaluation;
use Workflow\V2\StateMachine\Rules\Verdict;

/** Pure rule over recorded data; no container, configuration, database or provider access. */
final class BooleanFact implements ConditionRule
{
    public function assertParameters(array $parameters): void
    {
        $keys = array_keys($parameters);
        sort($keys);
        if ($keys !== ['expected', 'fact'] || ! is_string($parameters['fact'])
            || ! is_bool($parameters['expected'])) {
            throw new LogicException('The pinned boolean rule requires its fact name and expected boolean.');
        }
    }

    public function evaluate(RuleReference $rule, RecordedFacts $facts): RuleEvaluation
    {
        $this->assertParameters($rule->parameters);
        $name = $rule->parameters['fact'];
        if (! $facts->has($name)) {
            return new RuleEvaluation($rule, $facts->snapshotId, new Decision(
                Verdict::Unknown,
                'required_fact_missing',
            ), [$name]);
        }
        $value = $facts->values[$name];
        if (! is_bool($value)) {
            return new RuleEvaluation($rule, $facts->snapshotId, new Decision(
                Verdict::Unknown,
                'fact_type_incompatible',
                [$facts->evidence[$name]]
            ));
        }
        return new RuleEvaluation($rule, $facts->snapshotId, new Decision(
            $value === $rule->parameters['expected'] ? Verdict::Satisfied : Verdict::Unsatisfied,
            'recorded_boolean_assessed',
            [$facts->evidence[$name]],
        ));
    }
}
