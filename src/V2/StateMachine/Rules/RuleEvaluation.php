<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Rules;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\StateMachine\Definition\RuleReference;

/**
 * An explicit result channel: rule/version/parameters, recorded facts and missing evidence stay correlated.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class RuleEvaluation
{
    /**
     * @var list<string>
     */
    public array $missingFacts;

    /**
     * @param list<string> $missingFacts
     */
    public function __construct(
        public RuleReference $rule,
        public string $snapshotId,
        public Decision $decision,
        array $missingFacts = [],
    ) {
        PortableDescriptor::identifier($snapshotId, 'fact snapshot');
        if (! array_is_list($missingFacts)
            || ($missingFacts !== [] && $decision->verdict !== Verdict::Unknown)) {
            throw new InvalidArgumentException('Missing facts require a unique list and an unknown rule outcome.');
        }
        foreach ($missingFacts as $name) {
            if (! is_string($name)) {
                throw new InvalidArgumentException('Missing fact names must be strings.');
            }
            PortableDescriptor::identifier($name, 'missing fact');
        }
        if (count(array_unique($missingFacts)) !== count($missingFacts)) {
            throw new InvalidArgumentException('Missing fact names must be unique.');
        }
        $this->missingFacts = array_map(static fn (string $name): string => $name, $missingFacts);
        PortableDescriptor::encode($this->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule->toArray(),
            'snapshotId' => $this->snapshotId,
            'decision' => $this->decision->toArray(),
            'missingFacts' => $this->missingFacts,
        ];
    }
}
