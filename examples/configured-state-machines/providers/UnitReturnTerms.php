<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/**
 * Original delivered booking terms plus one explicit customer return key and unit ordinals.
 * The return and its restocking keep identities distinct from the full-parcel return graph.
 */
final readonly class UnitReturnTerms
{
    public const REQUEST = 'request-unit-return';

    public const RESTOCK = 'restock-units';

    /**
     * Recovery of a carrier-damaged parcel before recipient handover; never a customer return.
     */
    public const RECOVER = 'recover-damaged-units';

    /**
     * Release of an unused replacement reservation; it targets that original reservation.
     */
    public const RELEASE = 'release';

    public array $units;

    public function __construct(
        public string $sku,
        public int $quantity,
        public string $carrier,
        public string $returnKey,
        array $units,
    ) {
        $original = new ShipmentTerms($sku, $quantity, $carrier);
        PortableDescriptor::identifier($returnKey, 'original customer return key');
        if (strlen($returnKey) > 48 || ! array_is_list($units) || $units === [] || count(
            $units
        ) > $original->quantity) {
            throw new InvalidArgumentException(
                'A unit return needs a bounded key and explicit original unit ordinals.'
            );
        }
        foreach ($units as $position => $unit) {
            if (! is_int(
                $unit
            ) || $unit < 0 || $unit >= $quantity || ($position > 0 && $unit <= $units[$position - 1])) {
                throw new InvalidArgumentException('Returned units must be ascending unique original unit ordinals.');
            }
        }
        $this->units = $units;
    }

    public function shipment(): array
    {
        return (new ShipmentTerms($this->sku, $this->quantity, $this->carrier))->toArray();
    }

    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'carrier' => $this->carrier,
            'returnKey' => $this->returnKey,
            'units' => $this->units,
        ];
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['carrier', 'quantity', 'returnKey', 'sku', 'units'] || ! is_string($value['sku'])
            || ! is_int($value['quantity']) || ! is_string($value['carrier']) || ! is_string($value['returnKey'])
            || ! is_array($value['units'])) {
            throw new InvalidArgumentException('The unit return terms are malformed.');
        }
        return new self($value['sku'], $value['quantity'], $value['carrier'], $value['returnKey'], $value['units']);
    }

    /**
     * The return request belongs to the original booking owner, under an identity derived from the return key.
     */
    public static function request(Request $booking, string $returnKey, array $units): Request
    {
        if ($booking->action !== 'ship-book') {
            throw new InvalidArgumentException('A unit return must name its original booking.');
        }
        $original = ShipmentTerms::fromArray($booking->terms);
        $terms = new self($original->sku, $original->quantity, $original->carrier, $returnKey, $units);
        return new Request(self::identity(
            $booking,
            'return-units',
            $returnKey
        ), self::REQUEST, $terms->toArray(), $booking->identity);
    }

    /**
     * The carrier returns a parcel confirmed damaged before handover; same owner, distinct recovery identity.
     */
    public static function recovery(Request $booking, string $recoveryKey, array $units): Request
    {
        $request = self::request($booking, $recoveryKey, $units);
        return new Request(
            self::identity($booking, 'recover-units', $recoveryKey),
            self::RECOVER,
            $request->terms,
            $booking->identity
        );
    }

    /**
     * Restocking names only accepted saleable units of that same return or damaged-parcel recovery.
     */
    public static function restock(Request $return, array $units): Request
    {
        if (! in_array($return->action, [self::REQUEST, self::RECOVER], true)) {
            throw new InvalidArgumentException('Unit restocking must name its original unit return.');
        }
        $original = self::fromArray($return->terms);
        $terms = new self($original->sku, $original->quantity, $original->carrier, $original->returnKey, $units);
        if (array_diff($terms->units, $original->units) !== []) {
            throw new InvalidArgumentException('Unit restocking cannot name units outside its original return.');
        }
        return new Request(
            self::identity(
                $return,
                $return->action === self::RECOVER ? 'restock-recovered-units' : 'restock-units',
                $original->returnKey
            ),
            self::RESTOCK,
            $terms->toArray(),
            $return->identity
        );
    }

    private static function identity(Request $owner, string $stage, string $returnKey): Identity
    {
        return new Identity(
            $owner->identity->client,
            $owner->identity->subject,
            $owner->identity->generation,
            'shipment.full.' . $stage . '.' . $returnKey
        );
    }
}
