<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** An explicit refund portion of one original incoming transfer, never a card capture. */
final readonly class IncomingRefundTerms
{
    public function __construct(
        public IncomingPaymentDescriptor $original,
        public string $transfer,
        public int $amountMinor,
    ) {
        PortableDescriptor::identifier($transfer, 'original incoming transfer');
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('An incoming refund requires positive integer money.');
        }
    }

    public function toArray(): array
    {
        return [
            'original' => $this->original->toArray(),
            'transfer' => $this->transfer,
            'amountMinor' => $this->amountMinor,
            'currency' => $this->original->currency,
        ];
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['amountMinor', 'currency', 'original', 'transfer']
            || ! is_int($value['amountMinor']) || ! is_string($value['currency'])
            || ! is_array($value['original']) || ! is_string($value['transfer'])) {
            throw new InvalidArgumentException('Incoming refund terms have missing, unexpected or coerced fields.');
        }
        $original = IncomingPaymentDescriptor::fromArray($value['original']);
        if ($value['currency'] !== $original->currency) {
            throw new InvalidArgumentException('Incoming refunds preserve the original transfer currency.');
        }
        return new self($original, $value['transfer'], $value['amountMinor']);
    }
}
