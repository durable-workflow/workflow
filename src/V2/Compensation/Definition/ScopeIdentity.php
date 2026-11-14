<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Definition;

use InvalidArgumentException;

/**
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final readonly class ScopeIdentity
{
    public function __construct(
        public string $application,
        public ?string $namespace,
        public string $key,
        public string $generation,
    ) {
        foreach ([
            'application' => $application,
            'namespace' => $namespace,
            'key' => $key,
            'generation' => $generation,
        ] as $name => $value) {
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
        return [
            'version' => 1,
            'application' => $this->application,
            'namespace' => $this->namespace,
            'key' => $this->key,
            'generation' => $this->generation,
        ];
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'application',
            'generation',
            'key',
            'namespace',
            'version',
        ] || ($value['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported compensation scope identity format.');
        }
        return new self($value['application'], $value['namespace'], $value['key'], $value['generation']);
    }
}
