<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Business identity supplied by the application, independent of any task or retry attempt. */
final readonly class Identity
{
    public function __construct(
        public string $client,
        public string $subject,
        public string $generation,
        public string $operation,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            PortableDescriptor::identifier($value, $name);
        }
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function key(): string
    {
        return hash('sha256', PortableDescriptor::encode($this->toArray()));
    }

    public function sameOwner(self $other): bool
    {
        return $this->client === $other->client && $this->subject === $other->subject
            && $this->generation === $other->generation;
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['client', 'generation', 'operation', 'subject']) {
            throw new InvalidArgumentException('The provider identity fields are malformed.');
        }
        foreach ($value as $part) {
            if (! is_string($part)) {
                throw new InvalidArgumentException('Provider identities require string fields.');
            }
        }
        return new self($value['client'], $value['subject'], $value['generation'], $value['operation']);
    }
}
