<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Admission;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** @experimental A retained admission receipt, never proof that compensation has finished. */
final readonly class RootBinding
{
    public function __construct(
        public RootReservation $reservation,
        public string $commandId,
    ) {
        PortableDescriptor::identifier($commandId, 'root start command');
    }

    public function toArray(): array
    {
        return [
            'version' => 1,
            'reservation' => $this->reservation->toArray(),
            'commandId' => $this->commandId,
        ];
    }

    public static function fromArray(array $value): self
    {
        RootFormat::keys($value, ['version', 'reservation', 'commandId']);
        RootFormat::strings($value, ['commandId']);
        if ($value['version'] !== 1 || ! is_array($value['reservation'])) {
            throw new InvalidArgumentException('Unsupported root admission receipt.');
        }
        return new self(RootReservation::fromArray($value['reservation']), $value['commandId']);
    }
}
