<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** A closed union: payer collection at the original carrier point, or merchant receipt for that collection. */
final readonly class CodReceipt
{
    public const COLLECTION = 'carrier-collection';

    public const REMITTANCE = 'merchant-remittance';

    public function __construct(
        public string $payment,
        public string $receipt,
        public string $kind,
        public int $amountMinor,
        public string $currency,
        public int $occurredAt,
        public string $target,
    ) {
        foreach (['payment', 'receipt', 'target'] as $field) {
            PortableDescriptor::identifier($this->{$field}, 'original COD ' . $field);
        }
        if (! in_array($kind, [self::COLLECTION, self::REMITTANCE], true)
            || $amountMinor <= 0 || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1 || $occurredAt <= 0) {
            throw new InvalidArgumentException('A COD fact requires a supported kind and exact positive money/time.');
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
        if ($keys !== ['amountMinor', 'currency', 'kind', 'occurredAt', 'payment', 'receipt', 'target']
            || ! is_string($value['payment']) || ! is_string($value['receipt']) || ! is_string($value['kind'])
            || ! is_int($value['amountMinor']) || ! is_string($value['currency'])
            || ! is_int($value['occurredAt']) || ! is_string($value['target'])) {
            throw new InvalidArgumentException('COD receipt fields are missing, unexpected or coerced.');
        }
        return new self(...$value);
    }
}
