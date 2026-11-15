<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Trusted application assignment, persisted before starting any machine; never selected from workflow input. */
final readonly class OrderTerms
{
    public function __construct(
        public string $client,
        public string $subject,
        public string $generation,
        public int $amountMinor,
        public string $currency,
        public string $sku,
        public int $quantity,
    ) {
        foreach (['client', 'subject', 'generation', 'sku'] as $key) {
            PortableDescriptor::identifier($this->{$key}, $key);
        }
        if ($amountMinor <= 0 || $quantity <= 0 || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new InvalidArgumentException(
                'The example needs positive integer amounts and quantities and a currency.'
            );
        }
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function subjectKey(): string
    {
        return hash('sha256', PortableDescriptor::encode([$this->client, $this->subject, $this->generation]));
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['amountMinor', 'client', 'currency', 'generation', 'quantity', 'sku', 'subject']) {
            throw new InvalidArgumentException('The original order terms have unexpected or missing fields.');
        }
        foreach (['client', 'subject', 'generation', 'currency', 'sku'] as $key) {
            if (! is_string($value[$key])) {
                throw new InvalidArgumentException('The original order identity and currency must be strings.');
            }
        }
        if (! is_int($value['amountMinor']) || ! is_int($value['quantity'])) {
            throw new InvalidArgumentException('The original amount and quantity must be integer values.');
        }
        return new self(...$value);
    }

    /**
     * One original operation per role in this policy. Neither native task IDs nor retry attempts select a key.
     * This full-refund example does not select tax allocation, partial refunds, stock deduction or restocking.
     *
     * @return array{forward: array<string, mixed>, compensation: array<string, mixed>}
     */
    public function descriptor(string $role): array
    {
        [$forward, $undo, $terms] = match ($role) {
            'order' => [
                'reserve', 'release', [
                    'sku' => $this->sku,
                    'quantity' => $this->quantity,
                ]],
            'payment' => [
                'capture', 'refund', [
                    'amountMinor' => $this->amountMinor,
                    'currency' => $this->currency,
                ]],
            'shipment' => ['book', 'cancel-booking', [
                'sku' => $this->sku,
                'quantity' => $this->quantity,
                'carrier' => 'synthetic-carrier',
                'stage' => 'before-handover',
            ]],
            default => throw new InvalidArgumentException('The original provider role is unavailable.'),
        };
        $identity = new Identity($this->client, $this->subject, $this->generation, $role . '.' . $forward);
        return [
            'forward' => (new Request($identity, $forward, $terms))->toArray(),
            'compensation' => (new Request(
                new Identity($this->client, $this->subject, $this->generation, $role . '.' . $undo),
                $undo,
                $terms,
                $identity,
            ))->toArray(),
        ];
    }
}
