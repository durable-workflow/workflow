<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AuthorizationTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
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
        public ?AuthorizationTerms $authorization = null,
        public ?int $fulfillmentDeadline = null,
    ) {
        foreach (['client', 'subject', 'generation', 'sku'] as $key) {
            PortableDescriptor::identifier($this->{$key}, $key);
        }
        if ($amountMinor <= 0 || $quantity <= 0 || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new InvalidArgumentException(
                'The example needs positive integer amounts and quantities and a currency.'
            );
        }
        if ($authorization !== null && ($authorization->amountMinor !== $amountMinor
            || $authorization->currency !== $currency)) {
            throw new InvalidArgumentException(
                'The authorization must preserve the original order amount and currency.'
            );
        }
        if ($fulfillmentDeadline !== null && $fulfillmentDeadline <= 0) {
            throw new InvalidArgumentException('The original fulfillment deadline must be a positive Unix timestamp.');
        }
    }

    public function toArray(): array
    {
        $value = get_object_vars($this);
        if ($this->fulfillmentDeadline === null) {
            unset($value['fulfillmentDeadline']);
        }
        if ($this->authorization === null) {
            unset($value['authorization']);
        } else {
            $value['authorization'] = $this->authorization->toArray();
        }
        return $value;
    }

    public function subjectKey(): string
    {
        return hash('sha256', PortableDescriptor::encode([$this->client, $this->subject, $this->generation]));
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        $expected = ['amountMinor', 'client', 'currency', 'generation', 'quantity', 'sku', 'subject'];
        if (array_key_exists('fulfillmentDeadline', $value)) {
            if (! is_int($value['fulfillmentDeadline'])) {
                throw new InvalidArgumentException('The original fulfillment deadline must be an integer.');
            }
            $expected[] = 'fulfillmentDeadline';
            sort($expected);
        }
        if (array_key_exists('authorization', $value)) {
            if (! is_array($value['authorization'])) {
                throw new InvalidArgumentException('The original authorization must contain its immutable terms.');
            }
            $expected[] = 'authorization';
            sort($expected);
        }
        if ($keys !== $expected) {
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
        if (array_key_exists('authorization', $value)) {
            $value['authorization'] = AuthorizationTerms::fromArray($value['authorization']);
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
        if ($role === 'payment' && $this->authorization !== null) {
            throw new InvalidArgumentException('An authorized payment requires its explicit financial descriptor.');
        }
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

    /**
     * @return array{authorize: array, capture: array, void: array, refund: array}
     */
    public function financialDescriptor(): array
    {
        if ($this->authorization === null) {
            throw new InvalidArgumentException('An original authorization assignment is required.');
        }
        $id = fn (string $action): Identity => new Identity(
            $this->client,
            $this->subject,
            $this->generation,
            'payment.' . $action
        );
        $authorization = $id('authorize');
        $capture = $id('capture-authorized');
        $terms = $this->authorization->toArray();
        return [
            'authorize' => (new Request($authorization, 'authorize', $terms))->toArray(),
            'capture' => (new Request($capture, 'capture-authorized', $terms, $authorization))->toArray(),
            'void' => (new Request($id('void-authorization'), 'void-authorization', $terms, $authorization))->toArray(),
            'refund' => (new Request(
                $id('refund-authorized'),
                'refund-authorized',
                $this->authorization->captureTerms(),
                $capture
            ))->toArray(),
        ];
    }

    public function physicalDescriptor(): array
    {
        $identity = fn (string $action): Identity => new Identity(
            $this->client,
            $this->subject,
            $this->generation,
            'shipment.full.' . $action
        );
        $terms = [
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'carrier' => 'synthetic-carrier',
        ];
        $booking = $identity('book');
        $return = $identity('return');
        return (new ShipmentDescriptor(
            new Request($booking, 'ship-book', $terms),
            new Request($identity('cancel'), 'ship-cancel-booking', $terms, $booking),
            new Request($return, 'request-return', $terms, $booking),
            new Request($identity('restock'), 'restock', $terms, $return),
            new Request($identity('deduct'), 'deduct-stock', $terms, $booking)
        ))->toArray();
    }
}
