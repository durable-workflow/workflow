<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Definition;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Immutable configuration selects a registered rule version, never executable code. */
final readonly class RuleReference
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public string $type,
        public string $version,
        public array $parameters = [],
    ) {
        if (preg_match('/\A[a-z][a-z0-9._-]{0,127}\z/D', $type) !== 1) {
            throw new InvalidArgumentException('A rule requires a registered type, not a PHP class or callable.');
        }
        PortableDescriptor::identifier($version, 'rule version');
        foreach (array_keys($parameters) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Rule parameters require named fields.');
            }
            PortableDescriptor::identifier($key, 'rule parameter');
        }
        PortableDescriptor::encode($this->toArray());
    }

    public function fingerprint(): string
    {
        return hash('sha256', PortableDescriptor::encode($this->toArray()));
    }

    /**
     * @return array{type: string, version: string, parameters: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'version' => $this->version,
            'parameters' => $this->parameters,
        ];
    }
}
