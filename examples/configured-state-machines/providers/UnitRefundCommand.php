<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Business command identity remains fixed across native tasks, retries and reconciliation. */
final readonly class UnitRefundCommand
{
    public array $units;

    public function __construct(
        public OriginalUnitAmounts $original,
        public string $requestKey,
        array $units,
    ) {
        PortableDescriptor::identifier($requestKey, 'original unit refund business request');
        $this->units = $original->ordinals($units);
    }

    public function id(): string
    {
        return hash('sha256', PortableDescriptor::encode([
            'unit-refund-v1', $this->original->capture->identity->toArray(), $this->requestKey,
        ]));
    }

    public function toArray(): array
    {
        return [
            'version' => 1,
            'original' => $this->original->toArray(),
            'requestKey' => $this->requestKey,
            'units' => $this->units,
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', PortableDescriptor::encode($this->toArray()));
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['original', 'requestKey', 'units', 'version'] || $value['version'] !== 1
            || ! is_array($value['original']) || ! is_string($value['requestKey']) || ! is_array($value['units'])) {
            throw new \InvalidArgumentException('The original unit refund command fields are malformed.');
        }
        return new self(OriginalUnitAmounts::fromArray($value['original']), $value['requestKey'], $value['units']);
    }
}
