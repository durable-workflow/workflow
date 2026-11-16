<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** An original receivable, independent of card authorization and native task/attempt identities. */
final readonly class IncomingPaymentDescriptor
{
    public function __construct(
        public Identity $identity,
        public string $reference,
        public int $amountMinor,
        public string $currency,
        public int $deadline,
    ) {
        PortableDescriptor::identifier($reference, 'original incoming payment reference');
        if ($amountMinor <= 0 || $deadline <= 0 || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new InvalidArgumentException('A receivable requires positive integer money and a fixed deadline.');
        }
    }

    public function toArray(): array
    {
        return [
            'identity' => $this->identity->toArray(),
            'reference' => $this->reference,
            'amountMinor' => $this->amountMinor,
            'currency' => $this->currency,
            'deadline' => $this->deadline,
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
        if ($keys !== ['amountMinor', 'currency', 'deadline', 'identity', 'reference']
            || ! is_array($value['identity']) || ! is_string($value['reference'])
            || ! is_int($value['amountMinor']) || ! is_string($value['currency']) || ! is_int($value['deadline'])) {
            throw new InvalidArgumentException('Incoming payment terms have unexpected, missing or coerced fields.');
        }
        return new self(
            Identity::fromArray($value['identity']),
            $value['reference'],
            $value['amountMinor'],
            $value['currency'],
            $value['deadline']
        );
    }
}
