<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Binding;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;

/** @experimental Immutable retained participation, not an authorization or settlement receipt. */
final readonly class ChildScopeParticipation
{
    public function __construct(
        public ScopeIdentity $parent,
        public ScopeIdentity $child,
        public string $obligationKey,
    ) {
        PortableDescriptor::identifier($obligationKey, 'child obligation key');
        if ($parent->application !== $child->application || $parent->namespace !== $child->namespace
            || $parent->id() === $child->id()) {
            throw new InvalidArgumentException(
                'A retained child needs another scope in the same application and namespace.'
            );
        }
        PortableDescriptor::encode($this->toArray());
    }

    public function toArray(): array
    {
        return [
            'version' => 1,
            'parent' => $this->parent->toArray(),
            'child' => $this->child->toArray(),
            'obligationKey' => $this->obligationKey,
        ];
    }

    public static function fromArray(array $value): self
    {
        if (count($value) !== 4 || ($value['version'] ?? null) !== 1
            || ! is_array($value['parent'] ?? null) || ! is_array($value['child'] ?? null)
            || ! is_string($value['obligationKey'] ?? null)) {
            throw new InvalidArgumentException('Unsupported retained child participation descriptor.');
        }
        return new self(
            ScopeIdentity::fromArray($value['parent']),
            ScopeIdentity::fromArray($value['child']),
            $value['obligationKey'],
        );
    }
}
