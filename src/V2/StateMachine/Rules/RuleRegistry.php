<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Rules;

use InvalidArgumentException;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\StateMachine\Definition\ConditionDefinition;
use Workflow\V2\StateMachine\Definition\RuleReference;

/**
 * Trusted bootstrap supplies rule objects. Recorded configuration can select only their exact keys.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class RuleRegistry
{
    /**
     * @var array<string, array<string, ConditionRule>>
     */
    private array $rules;

    /**
     * @param array<string, array<string, ConditionRule>> $rules
     */
    public function __construct(array $rules)
    {
        foreach ($rules as $type => $versions) {
            if (! is_string($type) || preg_match('/\A[a-z][a-z0-9._-]{0,127}\z/D', $type) !== 1
                || ! is_array($versions)) {
                throw new InvalidArgumentException('Invalid registered rule type.');
            }
            foreach ($versions as $version => $rule) {
                if ((! is_string($version) && ! is_int($version)) || ! $rule instanceof ConditionRule) {
                    throw new InvalidArgumentException('Registered rule versions require ConditionRule objects.');
                }
                PortableDescriptor::identifier((string) $version, 'rule version');
            }
        }
        $this->rules = array_map(static fn (array $versions): array =>
            array_map(static fn (ConditionRule $rule): ConditionRule => $rule, $versions), $rules);
    }

    public function evaluate(RuleReference $reference, RecordedFacts $facts): RuleEvaluation
    {
        $rule = $this->rules[$reference->type][$reference->version]
            ?? throw new LogicException('The original rule version is not registered.');
        $rule->assertParameters($reference->parameters);
        $result = $rule->evaluate($reference, $facts);
        if (! hash_equals($reference->fingerprint(), $result->rule->fingerprint())
            || $result->snapshotId !== $facts->snapshotId) {
            throw new LogicException('The rule result belongs to another configuration or fact snapshot.');
        }
        foreach ($result->missingFacts as $name) {
            if ($facts->has($name)) {
                throw new LogicException('The rule result contradicts the recorded fact set.');
            }
        }
        foreach ($result->decision->evidence as $reference) {
            if (! in_array($reference, $facts->evidence, true)) {
                throw new LogicException('The rule result cites evidence outside its recorded fact snapshot.');
            }
        }
        return $result;
    }

    public function evaluateCondition(ConditionDefinition $condition, RecordedFacts $facts): ConditionEvaluation
    {
        if ($condition->rule !== null) {
            return ConditionEvaluation::leaf($condition, $this->evaluate($condition->rule, $facts));
        }
        return ConditionEvaluation::group($condition, array_map(
            fn (ConditionDefinition $child): ConditionEvaluation => $this->evaluateCondition($child, $facts),
            $condition->children,
        ));
    }
}
