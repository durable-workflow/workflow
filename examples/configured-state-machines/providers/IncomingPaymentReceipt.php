<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** One authoritative provider transfer, never an application signal or a fabricated capture command. */
final readonly class IncomingPaymentReceipt
{
    public function __construct(
        public string $receivable,
        public string $transfer,
        public int $amountMinor,
        public string $currency,
        public int $receivedAt,
    ) {
        PortableDescriptor::identifier($receivable, 'original receivable');
        PortableDescriptor::identifier($transfer, 'original provider transfer');
        if ($amountMinor <= 0 || $receivedAt <= 0 || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new InvalidArgumentException('An incoming receipt requires positive integer money and receipt time.');
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
        if ($keys !== ['amountMinor', 'currency', 'receivable', 'receivedAt', 'transfer']
            || ! is_string($value['receivable']) || ! is_string($value['transfer'])
            || ! is_int($value['amountMinor']) || ! is_string($value['currency']) || ! is_int($value['receivedAt'])) {
            throw new InvalidArgumentException('Incoming receipt fields are missing, unexpected or coerced.');
        }
        return new self(...$value);
    }
}
