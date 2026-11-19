<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Immutable command terms; the simulator does not choose the application's money or inventory policy. */
final readonly class Request
{
    public array $terms;

    public function __construct(
        public Identity $identity,
        public string $action,
        array $terms,
        public ?Identity $target = null,
    ) {
        if (! in_array($action, [
            'capture', 'refund', 'book', 'cancel-booking', 'reserve', 'release',
            'authorize', 'capture-authorized', 'void-authorization', 'refund-authorized',
            'ship-book', 'ship-cancel-booking', 'request-return', 'restock', 'deduct-stock',
            'refund-incoming', 'refund-cod', UnitReturnTerms::REQUEST, UnitReturnTerms::RESTOCK,
            UnitReturnTerms::RECOVER, ShipmentLedger::CLAIM,
        ], true)) {
            throw new InvalidArgumentException('The synthetic provider action is unavailable.');
        }
        $targeted = in_array($action, [
            'refund', 'cancel-booking', 'release', 'capture-authorized', 'void-authorization', 'refund-authorized',
            'ship-cancel-booking', 'request-return', 'restock', 'deduct-stock',
            'refund-incoming', 'refund-cod', UnitReturnTerms::REQUEST, UnitReturnTerms::RESTOCK,
            UnitReturnTerms::RECOVER, ShipmentLedger::CLAIM,
        ], true);
        if ($targeted !== ($target !== null) || ($target !== null && (! $identity->sameOwner($target)
            || $identity->key() === $target->key()))) {
            throw new InvalidArgumentException('Compensation must name another original operation of the same owner.');
        }
        if (CartReservationTerms::selected($terms)) {
            CartReservationTerms::fromArray($terms)->assertRequest($this);
        }
        if (in_array($action, ['authorize', 'capture-authorized', 'void-authorization'], true)) {
            AuthorizationTerms::fromArray($terms);
        }
        if ($action === 'refund-incoming') {
            $incoming = IncomingRefundTerms::fromArray($terms);
            if ($target?->toArray() !== $incoming->original->identity->toArray()) {
                throw new InvalidArgumentException('An incoming refund must target its original receivable.');
            }
        }
        if ($action === 'refund-cod') {
            $cod = CodRefundTerms::fromArray($terms);
            if ($target?->toArray() !== $cod->original->identity->toArray()) {
                throw new InvalidArgumentException('A COD customer refund must target its original carrier payment.');
            }
        }
        if (in_array(
            $action,
            ['ship-book', 'ship-cancel-booking', 'request-return', 'restock', 'deduct-stock'],
            true
        )) {
            ShipmentTerms::fromArray($terms);
        }
        if (in_array($action, [UnitReturnTerms::REQUEST, UnitReturnTerms::RESTOCK, UnitReturnTerms::RECOVER], true)) {
            UnitReturnTerms::fromArray($terms);
        }
        if ($action === ShipmentLedger::CLAIM) {
            ShipmentLedger::claimTerms($terms);
        }
        $this->terms = PortableDescriptor::snapshot($terms);
        PortableDescriptor::encode($this->toArray());
    }

    public function toArray(): array
    {
        return [
            'identity' => $this->identity->toArray(),
            'action' => $this->action,
            'terms' => $this->terms,
            'target' => $this->target?->toArray(),
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
        if ($keys !== ['action', 'identity', 'target', 'terms'] || ! is_string($value['action'])
            || ! is_array($value['identity']) || ! is_array($value['terms'])
            || ($value['target'] !== null && ! is_array($value['target']))) {
            throw new InvalidArgumentException('The provider request fields are malformed.');
        }
        return new self(
            Identity::fromArray($value['identity']),
            $value['action'],
            $value['terms'],
            $value['target'] === null ? null : Identity::fromArray($value['target']),
        );
    }
}
