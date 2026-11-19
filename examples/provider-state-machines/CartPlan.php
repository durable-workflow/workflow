<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CartReservationDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Trusted purchase assignment: one original cart lease and one distinct, predeclared late-payment lease. */
final readonly class CartPlan
{
    public const VERSION = 'provider-cart-checkout-v1';

    public function __construct(
        public string $orderReference,
        public CartReservationDescriptor $initial,
        public CartReservationDescriptor $late,
        public int $paymentObservationDeadline,
    ) {
        PortableDescriptor::identifier($orderReference, 'formal Order reference');
        $first = $initial->terms;
        $next = $late->terms;
        $identities = [$initial->reserve->identity, $initial->release->identity,
            $late->reserve->identity, $late->release->identity];
        if ($first->previous !== null || $next->previous?->toArray() !== $initial->reserve->identity->toArray()
            || ! $initial->reserve->identity->sameOwner($late->reserve->identity)
            || $first->cartId !== $next->cartId || $first->sku !== $next->sku || $first->quantity !== $next->quantity
            || $next->createdAt !== $first->expiresAt || $paymentObservationDeadline <= $first->expiresAt
            || $paymentObservationDeadline >= $next->expiresAt
            || count(array_unique(array_map(static fn (Identity $id): string => $id->key(), $identities))) !== 4) {
            throw new InvalidArgumentException(
                'The cart plan changed its original owner, stock, deadlines or operations.'
            );
        }
    }

    public function assertOrder(OrderTerms $terms): void
    {
        $owner = new Identity($terms->client, $terms->subject, $terms->generation, 'cart-owner');
        if (! $owner->sameOwner($this->initial->reserve->identity)
            || $terms->sku !== $this->initial->terms->sku || $terms->quantity !== $this->initial->terms->quantity
            || $terms->incomingPayment === null || $terms->incomingPayment->deadline !== $this->paymentObservationDeadline
            || $terms->authorization !== null || $terms->codPayment !== null || $terms->advanceBalance !== null
            || $terms->fulfillmentDeadline === null || $terms->fulfillmentDeadline < $this->late->terms->expiresAt) {
            throw new InvalidArgumentException(
                'Cart checkout must retain its original purchase and bounded bank policy.'
            );
        }
    }

    public function obligation(string $step): Obligation
    {
        $lease = $this->lease($step);
        return new Obligation('cart.' . $step, 'provider.cart', '1', [
            'forward' => $lease->reserve->toArray(),
            'compensation' => $lease->release->toArray(),
        ]);
    }

    public function lease(string $step): CartReservationDescriptor
    {
        return match ($step) {
            'initial' => $this->initial,
            'late' => $this->late,
            default => throw new InvalidArgumentException('The original cart lease step is unavailable.'),
        };
    }

    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'orderReference' => $this->orderReference,
            'initial' => $this->initial->toArray(),
            'late' => $this->late->toArray(),
            'paymentObservationDeadline' => $this->paymentObservationDeadline,
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
        if ($keys !== ['initial', 'late', 'orderReference', 'paymentObservationDeadline', 'version']
            || $value['version'] !== self::VERSION || ! is_string($value['orderReference'])
            || ! is_int($value['paymentObservationDeadline']) || ! is_array($value['initial'])
            || ! is_array($value['late'])) {
            throw new InvalidArgumentException('The original cart checkout assignment is malformed.');
        }
        return new self(
            $value['orderReference'],
            CartReservationDescriptor::fromArray($value['initial']),
            CartReservationDescriptor::fromArray($value['late']),
            $value['paymentObservationDeadline']
        );
    }
}
