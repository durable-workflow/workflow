<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Definition;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/**
 * Bounded condition structure over registered rules, with no expression evaluation or class loading.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class ConditionDefinition
{
    public const MAX_NODES = 64;

    /**
     * @var list<self>
     */
    public array $children;

    /**
     * @param list<self> $children
     */
    private function __construct(
        public string $kind,
        public ?RuleReference $rule,
        array $children,
    ) {
        $this->children = array_map(static fn (self $child): self => $child, $children);
        $count = 0;
        self::count($this, $count);
        PortableDescriptor::encode($this->toArray());
    }

    public static function rule(RuleReference $rule): self
    {
        return new self('rule', $rule, []);
    }

    /**
     * @param non-empty-list<self> $children
     */
    public static function all(array $children): self
    {
        return self::group('all', $children);
    }

    /**
     * @param non-empty-list<self> $children
     */
    public static function any(array $children): self
    {
        return self::group('any', $children);
    }

    public static function not(self $child): self
    {
        return new self('not', null, [$child]);
    }

    public function fingerprint(): string
    {
        return hash('sha256', PortableDescriptor::encode($this->toArray()));
    }

    public function nodeCount(): int
    {
        $count = 0;
        self::count($this, $count);
        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->rule !== null ? [
            'version' => 1,
            'kind' => 'rule',
            'rule' => $this->rule->toArray(),
        ]
            : [
                'version' => 1,
                'kind' => $this->kind,
                'children' => array_map(static fn (self $child): array => $child->toArray(), $this->children),
            ];
    }

    private static function group(string $kind, array $children): self
    {
        if ($children === [] || ! array_is_list($children)) {
            throw new InvalidArgumentException('A condition group requires a nonempty list.');
        }
        foreach ($children as $child) {
            if (! $child instanceof self) {
                throw new InvalidArgumentException('Condition groups contain only condition definitions.');
            }
        }
        return new self($kind, null, $children);
    }

    private static function count(self $node, int &$count): void
    {
        if (++$count > self::MAX_NODES) {
            throw new InvalidArgumentException('The condition structure exceeds its node limit.');
        }
        foreach ($node->children as $child) {
            self::count($child, $count);
        }
    }
}
