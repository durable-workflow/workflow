<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** A carrier-origin refund of one original payer collection; never a card capture or merchant remittance. */
final readonly class CodRefundTerms
{
    public function __construct(
        public CodDescriptor $original,
        public string $collection,
        public int $amountMinor,
    ) {
        PortableDescriptor::identifier($collection, 'original carrier collection receipt');
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('A COD customer refund requires positive integer money.');
        }
    }

    public function toArray(): array
    {
        return [
            'original' => $this->original->toArray(),
            'collection' => $this->collection,
            'amountMinor' => $this->amountMinor,
            'currency' => $this->original->currency,
        ];
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['amountMinor', 'collection', 'currency', 'original']
            || ! is_int($value['amountMinor']) || ! is_string($value['currency'])
            || ! is_array($value['original']) || ! is_string($value['collection'])) {
            throw new InvalidArgumentException('COD refund terms have missing, unexpected or coerced fields.');
        }
        $original = CodDescriptor::fromArray($value['original']);
        if ($value['currency'] !== $original->currency) {
            throw new InvalidArgumentException('COD customer refunds preserve the original collection currency.');
        }
        return new self($original, $value['collection'], $value['amountMinor']);
    }
}
