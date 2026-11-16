<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

final readonly class ShipmentTerms
{
    public function __construct(
        public string $sku,
        public int $quantity,
        public string $carrier
    ) {
        PortableDescriptor::identifier($sku, 'original shipment SKU');
        PortableDescriptor::identifier($carrier, 'original carrier');
        if ($quantity <= 0) {
            throw new InvalidArgumentException('The original physical quantity must be positive.');
        }
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['carrier', 'quantity', 'sku'] || ! is_string($value['sku'])
            || ! is_int($value['quantity']) || ! is_string($value['carrier'])) {
            throw new InvalidArgumentException('The physical shipment terms are malformed.');
        }
        return new self($value['sku'], $value['quantity'], $value['carrier']);
    }
}
