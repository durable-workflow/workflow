<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Original carrier collection and merchant remittance are different obligations and deadlines. */
final readonly class CodDescriptor
{
    public const BEFORE = 'collect-before-recipient-handover';

    public const AFTER = 'collect-after-recipient-handover';

    public function __construct(
        public Identity $identity,
        public ShipmentDescriptor $shipment,
        public string $reference,
        public string $collectionPoint,
        public int $amountMinor,
        public string $currency,
        public int $collectionDeadline,
        public int $remittanceDeadline,
        public string $handoverPolicy = self::BEFORE,
    ) {
        PortableDescriptor::identifier($reference, 'original carrier payment reference');
        PortableDescriptor::identifier($collectionPoint, 'original carrier collection point');
        if (! $identity->sameOwner($shipment->booking->identity)
            || $identity->key() === $shipment->booking->identity->key()) {
            throw new InvalidArgumentException('COD must retain its original purchase and distinct Shipment booking.');
        }
        if ($amountMinor <= 0 || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
            || $collectionDeadline <= 0 || $remittanceDeadline < $collectionDeadline) {
            throw new InvalidArgumentException('COD requires exact positive money and original ordered deadlines.');
        }
        if (! in_array($handoverPolicy, [self::BEFORE, self::AFTER], true)) {
            throw new InvalidArgumentException('The original carrier handover contract is unavailable.');
        }
    }

    public function toArray(): array
    {
        return [
            'identity' => $this->identity->toArray(),
            'shipment' => $this->shipment->toArray(),
            'reference' => $this->reference,
            'collectionPoint' => $this->collectionPoint,
            'amountMinor' => $this->amountMinor,
            'currency' => $this->currency,
            'collectionDeadline' => $this->collectionDeadline,
            'remittanceDeadline' => $this->remittanceDeadline,
            'handoverPolicy' => $this->handoverPolicy,
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
        if ($keys !== ['amountMinor', 'collectionDeadline', 'collectionPoint', 'currency', 'handoverPolicy',
            'identity', 'reference', 'remittanceDeadline', 'shipment']
            || ! is_array($value['identity']) || ! is_array($value['shipment'])
            || ! is_string($value['reference']) || ! is_string($value['collectionPoint'])
            || ! is_int($value['amountMinor']) || ! is_string($value['currency'])
            || ! is_int($value['collectionDeadline']) || ! is_int($value['remittanceDeadline'])
            || ! is_string($value['handoverPolicy'])) {
            throw new InvalidArgumentException('COD descriptor fields are missing, unexpected or coerced.');
        }
        return new self(
            Identity::fromArray($value['identity']),
            ShipmentDescriptor::fromArray($value['shipment']),
            $value['reference'],
            $value['collectionPoint'],
            $value['amountMinor'],
            $value['currency'],
            $value['collectionDeadline'],
            $value['remittanceDeadline'],
            $value['handoverPolicy']
        );
    }
}
