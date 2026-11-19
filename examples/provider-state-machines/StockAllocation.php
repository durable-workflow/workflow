<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use InvalidArgumentException;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Per-unit warehouse data and policy planning; never execution or settlement evidence. */
final readonly class StockAllocation
{
    public const PARTIAL = 'fulfill-partial';

    public const WAIT = 'wait-complete';

    public const CANCEL = 'cancel-all';

    public function __construct(
        public Request $reservation,
        public array $units,
    ) {
        $quantity = $reservation->terms['quantity'] ?? null;
        if ($reservation->action !== 'reserve' || ! is_int($quantity) || $quantity < 1 || $quantity > 1000
            || ! array_is_list($units) || count($units) !== $quantity) {
            throw new InvalidArgumentException('Stock allocation must retain every original requested unit.');
        }
        foreach ($units as $state) {
            if (! in_array($state, ['held', 'unavailable', 'damaged', 'missing'], true)) {
                throw new InvalidArgumentException('Each original unit needs an explicit warehouse disposition.');
            }
        }
    }

    /**
     * The caller supplies its pinned original reservation and an explicit business policy.
     */
    public function decide(Request $original, string $policy): array
    {
        if ($original->fingerprint() !== $this->reservation->fingerprint()) {
            throw new LogicException('Warehouse allocation belongs to another original reservation.');
        }
        if (! in_array($policy, [self::PARTIAL, self::WAIT, self::CANCEL], true)) {
            throw new InvalidArgumentException('A partial allocation requires its explicit business policy.');
        }
        $groups = array_fill_keys(['held', 'unavailable', 'damaged', 'missing'], []);
        foreach ($this->units as $unit => $state) {
            $groups[$state][] = $unit;
        }
        $action = match (true) {
            $groups['damaged'] !== [] || $groups['missing'] !== [] => 'cancel',
            $groups['unavailable'] === [] => 'fulfill',
            $policy === self::WAIT => 'wait',
            $policy === self::CANCEL || $groups['held'] === [] => 'cancel',
            default => 'fulfill',
        };
        return [
            'version' => 1,
            'status' => 'planned',
            'reservationFingerprint' => $original->fingerprint(),
            'requestedQuantity' => count($this->units),
            'policy' => $policy,
            'action' => $action,
            'fulfillUnits' => $action === 'fulfill' ? $groups['held'] : [],
            'requiredRefundUnits' => match ($action) {
                'cancel' => array_keys($this->units),
                'fulfill' => $groups['unavailable'],
                default => [],
            },
            'releaseUnits' => $action === 'cancel' ? $groups['held'] : [],
            'heldUnits' => $groups['held'],
            'unavailableUnits' => $groups['unavailable'],
            'damagedUnits' => $groups['damaged'],
            'missingUnits' => $groups['missing'],
        ];
    }

    public function toArray(): array
    {
        return [
            'version' => 1,
            'reservation' => $this->reservation->toArray(),
            'units' => $this->units,
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
        if ($keys !== ['reservation', 'units', 'version'] || $value['version'] !== 1
            || ! is_array($value['reservation']) || ! is_array($value['units'])) {
            throw new InvalidArgumentException('The original stock allocation fields are malformed.');
        }
        return new self(Request::fromArray($value['reservation']), $value['units']);
    }
}
