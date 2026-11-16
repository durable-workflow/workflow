<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Immutable original reservation and release, including the predecessor of a later reservation. */
final readonly class CartReservationDescriptor
{
    public CartReservationTerms $terms;

    public function __construct(
        public Request $reserve,
        public Request $release,
    ) {
        $terms = CartReservationTerms::fromArray($reserve->terms);
        if ($reserve->action !== 'reserve' || $release->action !== 'release'
            || $release->target?->toArray() !== $reserve->identity->toArray()
            || PortableDescriptor::encode($reserve->terms) !== PortableDescriptor::encode($release->terms)) {
            throw new InvalidArgumentException('A cart release must retain its original reservation and exact lease.');
        }
        $terms->assertRequest($reserve);
        $terms->assertRequest($release);
        $this->terms = $terms;
    }

    public function toArray(): array
    {
        return [
            'reserve' => $this->reserve->toArray(),
            'release' => $this->release->toArray(),
        ];
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['release', 'reserve'] || ! is_array($value['reserve']) || ! is_array($value['release'])) {
            throw new InvalidArgumentException('The original cart reservation descriptor is malformed.');
        }
        return new self(Request::fromArray($value['reserve']), Request::fromArray($value['release']));
    }
}
