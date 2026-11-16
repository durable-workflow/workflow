<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AuthorizationTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentJournal;
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
        public ?IncomingPaymentDescriptor $incomingPayment = null,
        public string $incomingAdmissionContract = IncomingPaymentJournal::OPEN,
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
        $this->assertIncomingPayment();
    }

    public function toArray(): array
    {
        $value = get_object_vars($this);
        if ($this->incomingPayment === null) {
            unset($value['incomingPayment'], $value['incomingAdmissionContract']);
        } else {
            $value['incomingPayment'] = $this->incomingPayment->toArray();
        }
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
        [$value, $incomingKeys] = self::decodeIncoming($value);
        $keys = array_keys($value);
        sort($keys);
        $expected = ['amountMinor', 'client', 'currency', 'generation', 'quantity', 'sku', 'subject', ...$incomingKeys];
        sort($expected);
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
        if ($role === 'payment' && $this->incomingPayment !== null) {
            throw new InvalidArgumentException('Incoming payment cannot become an immediate card charge.');
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

    public function incomingDescriptor(): array
    {
        if ($this->incomingPayment === null) {
            throw new InvalidArgumentException('The original incoming-payment assignment is unavailable.');
        }
        return [
            'original' => $this->incomingPayment->toArray(),
            'admissionContract' => $this->incomingAdmissionContract,
        ];
    }

    private function assertIncomingPayment(): void
    {
        $incoming = $this->incomingPayment;
        if ($incoming === null) {
            if ($this->incomingAdmissionContract !== IncomingPaymentJournal::OPEN) {
                throw new InvalidArgumentException('An incoming admission contract requires its original receivable.');
            }
            return;
        }
        if ($this->authorization !== null || $incoming->identity->client !== $this->client
            || $incoming->identity->subject !== $this->subject || $incoming->identity->generation !== $this->generation
            || $incoming->amountMinor !== $this->amountMinor || $incoming->currency !== $this->currency) {
            throw new InvalidArgumentException('Incoming payment must preserve the original purchase owner and money.');
        }
        if (! in_array(
            $this->incomingAdmissionContract,
            [IncomingPaymentJournal::OPEN, IncomingPaymentJournal::CLOSABLE],
            true
        )) {
            throw new InvalidArgumentException('The original incoming admission contract is unavailable.');
        }
    }

    /**
     * @return array{array, list<string>}
     */
    private static function decodeIncoming(array $value): array
    {
        if (! array_key_exists('incomingPayment', $value) && ! array_key_exists('incomingAdmissionContract', $value)) {
            return [$value, []];
        }
        if (! is_array($value['incomingPayment'] ?? null) || ! is_string($value['incomingAdmissionContract'] ?? null)) {
            throw new InvalidArgumentException(
                'Incoming assignment requires both its original receivable and provider contract.'
            );
        }
        $value['incomingPayment'] = IncomingPaymentDescriptor::fromArray($value['incomingPayment']);
        return [$value, ['incomingPayment', 'incomingAdmissionContract']];
    }
}
