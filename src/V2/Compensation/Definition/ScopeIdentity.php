<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Definition;

final readonly class ScopeIdentity
{
    public function __construct(
        public string $application,
        public ?string $namespace,
        public string $key,
        public string $generation,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if ($name === 'namespace' && $value === null) {
                continue;
            }
            PortableDescriptor::identifier($value, $name);
        }
    }

    public function id(): string
    {
        return hash('sha256', PortableDescriptor::encode($this->toArray()));
    }

    public function recoveryInstanceId(): string
    {
        return 'dw-compensation-' . $this->id();
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $value): self
    {
        if (array_keys($value) !== ['application', 'namespace', 'key', 'generation']) {
            $keys = array_keys($value);
            sort($keys, SORT_STRING);
            if ($keys !== ['application', 'generation', 'key', 'namespace']) {
                throw new \InvalidArgumentException('Invalid compensation scope identity shape.');
            }
        }
        return new self($value['application'], $value['namespace'], $value['key'], $value['generation']);
    }
}
