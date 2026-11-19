<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Rules;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\StateMachine\Definition\ConditionDefinition;

/**
 * Complete immutable explanation; compound verdicts are derived from their correlated children.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class ConditionEvaluation
{
    /**
     * @var list<self>
     */
    public array $children;

    /**
     * @param list<self> $children
     */
    private function __construct(
        public ConditionDefinition $condition,
        public string $snapshotId,
        public Decision $decision,
        public ?RuleEvaluation $rule,
        array $children,
    ) {
        $this->children = array_map(static fn (self $child): self => $child, $children);
        PortableDescriptor::encode($this->toArray());
    }

    public static function leaf(ConditionDefinition $condition, RuleEvaluation $result): self
    {
        if ($condition->rule === null || ! hash_equals($condition->rule->fingerprint(), $result->rule->fingerprint())) {
            throw new InvalidArgumentException('The rule explanation belongs to another condition.');
        }
        return new self($condition, $result->snapshotId, $result->decision, $result, []);
    }

    /**
     * @param non-empty-list<self> $children
     */
    public static function group(ConditionDefinition $condition, array $children): self
    {
        if ($condition->rule !== null || ! array_is_list($children)
            || count($children) !== count($condition->children)) {
            throw new InvalidArgumentException('The condition explanation has the wrong child structure.');
        }
        $snapshot = null;
        $evidence = [];
        $verdicts = [];
        foreach ($children as $index => $child) {
            if (! $child instanceof self || ! hash_equals(
                $condition->children[$index]->fingerprint(),
                $child->condition->fingerprint(),
            ) || ($snapshot !== null && $snapshot !== $child->snapshotId)) {
                throw new InvalidArgumentException(
                    'Condition explanations must share the exact structure and snapshot.'
                );
            }
            $snapshot = $child->snapshotId;
            array_push($evidence, ...$child->decision->evidence);
            $verdicts[] = $child->decision->verdict;
        }
        $verdict = self::verdict($condition->kind, $verdicts);
        return new self($condition, $snapshot, new Decision(
            $verdict,
            'conditions_' . $condition->kind . '_' . $verdict->value,
            array_values(array_unique($evidence)),
        ), null, $children);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $header = [
            'version' => 1,
            'kind' => $this->condition->kind,
            'configurationFingerprint' => $this->condition->fingerprint(),
        ];
        return $this->rule !== null ? $header + $this->rule->toArray() : $header + [
            'snapshotId' => $this->snapshotId,
            'decision' => $this->decision->toArray(),
            'children' => array_map(static fn (self $child): array => $child->toArray(), $this->children),
        ];
    }

    /**
     * @param non-empty-list<Verdict> $values
     */
    private static function verdict(string $kind, array $values): Verdict
    {
        if ($kind === 'not') {
            return match ($values[0]) {
                Verdict::Satisfied => Verdict::Unsatisfied,
                Verdict::Unsatisfied => Verdict::Satisfied,
                Verdict::Unknown => Verdict::Unknown,
            };
        }
        $decisive = $kind === 'all' ? Verdict::Unsatisfied : Verdict::Satisfied;
        if (in_array($decisive, $values, true)) {
            return $decisive;
        }
        if (in_array(Verdict::Unknown, $values, true)) {
            return Verdict::Unknown;
        }
        return $kind === 'all' ? Verdict::Satisfied : Verdict::Unsatisfied;
    }
}
