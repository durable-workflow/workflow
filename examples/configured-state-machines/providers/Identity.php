<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

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
}
