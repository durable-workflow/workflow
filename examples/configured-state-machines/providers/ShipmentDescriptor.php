<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Full-parcel example. Booking, return and inventory admission keep separate original identities. */
final readonly class ShipmentDescriptor
{
    public function __construct(
        public Request $booking,
        public Request $cancel,
        public Request $return,
        public Request $restock,
    ) {
        $expected = [
            'booking' => 'ship-book',
            'cancel' => 'ship-cancel-booking',
            'return' => 'request-return',
            'restock' => 'restock',
        ];
        foreach ($expected as $key => $action) {
            $request = $this->{$key};
            if ($request->action !== $action || ! $booking->identity->sameOwner($request->identity)
                || $booking->terms !== $request->terms) {
                throw new InvalidArgumentException(
                    'The physical shipment graph must preserve its original owner and terms.'
                );
            }
            $target = $key === 'restock' ? $return->identity : $booking->identity;
            if ($key !== 'booking' && $request->target?->toArray() !== $target->toArray()) {
                throw new InvalidArgumentException('The physical shipment target is not its original predecessor.');
            }
        }
        if (count(
            array_unique(array_map(static fn (Request $r): string => $r->identity->key(), $this->requests()))
        ) !== 4) {
            throw new InvalidArgumentException('Physical shipment operations need distinct original identities.');
        }
    }

    public function requests(): array
    {
        return get_object_vars($this);
    }

    public function toArray(): array
    {
        return array_map(static fn (Request $r): array => $r->toArray(), $this->requests());
    }

    public function fingerprint(): string
    {
        return hash('sha256', PortableDescriptor::encode($this->toArray()));
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['booking', 'cancel', 'restock', 'return']) {
            throw new InvalidArgumentException('The physical shipment graph fields are malformed.');
        }
        foreach ($value as $request) {
            if (! is_array($request)) {
                throw new InvalidArgumentException('Physical shipment requests must be explicit objects.');
            }
        }
        return new self(...array_map(Request::fromArray(...), $value));
    }
}
