<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** One fixed stock lease. A successor is another operation, never an extension of this lease. */
final readonly class CartReservationTerms
{
    public const CONTRACT = 'provider.cart-reservation.v1';

    public function __construct(
        public string $cartId,
        public string $sku,
        public int $quantity,
        public int $createdAt,
        public int $expiresAt,
        public string $releaseOperation,
        public ?Identity $previous = null,
    ) {
        foreach (['cartId', 'sku', 'releaseOperation'] as $field) {
            PortableDescriptor::identifier($this->{$field}, $field);
        }
        if ($quantity <= 0 || $createdAt < 0 || $expiresAt <= $createdAt) {
            throw new InvalidArgumentException('A cart lease needs positive stock and an original absolute interval.');
        }
    }

    public function toArray(): array
    {
        return [
            'contract' => self::CONTRACT,
            'cartId' => $this->cartId,
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'createdAt' => $this->createdAt,
            'expiresAt' => $this->expiresAt,
            'releaseOperation' => $this->releaseOperation,
            'previous' => $this->previous?->toArray(),
        ];
    }

    public static function selected(array $value): bool
    {
        return is_string($value['contract'] ?? null)
            && str_starts_with($value['contract'], 'provider.cart-reservation.');
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== [
            'cartId',
            'contract',
            'createdAt',
            'expiresAt',
            'previous',
            'quantity',
            'releaseOperation',
            'sku',
        ]
            || $value['contract'] !== self::CONTRACT) {
            throw new InvalidArgumentException('The original cart lease contract is unavailable or malformed.');
        }
        foreach (['cartId', 'sku', 'releaseOperation'] as $field) {
            if (! is_string($value[$field])) {
                throw new InvalidArgumentException('Cart lease identities must be strings.');
            }
        }
        foreach (['quantity', 'createdAt', 'expiresAt'] as $field) {
            if (! is_int($value[$field])) {
                throw new InvalidArgumentException('Cart quantities and absolute deadlines must be integers.');
            }
        }
        if ($value['previous'] !== null && ! is_array($value['previous'])) {
            throw new InvalidArgumentException('A successor must name the original reservation identity.');
        }
        return new self(
            $value['cartId'],
            $value['sku'],
            $value['quantity'],
            $value['createdAt'],
            $value['expiresAt'],
            $value['releaseOperation'],
            $value['previous'] === null ? null : Identity::fromArray($value['previous'])
        );
    }

    public function assertRequest(Request $request): void
    {
        if (! in_array($request->action, ['reserve', 'release'], true)
            || ($request->action === 'release' && $request->identity->operation !== $this->releaseOperation)
            || ($request->action === 'reserve' && $request->identity->operation === $this->releaseOperation)
            || ($this->previous !== null && (! $request->identity->sameOwner($this->previous)
                || $request->identity->key() === $this->previous->key()))) {
            throw new InvalidArgumentException('The cart request changed its original owner or operation.');
        }
    }
}
