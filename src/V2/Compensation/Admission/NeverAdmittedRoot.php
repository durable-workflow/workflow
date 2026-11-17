<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Admission;

use Workflow\V2\Compensation\Definition\PortableDescriptor;

/**
 * @experimental Authoritative only when returned by the store after locking and
 * rereading the permanent fence. Constructed/deserialized values grant no authority.
 */
final readonly class NeverAdmittedRoot
{
    public function __construct(
        public RootReservation $reservation,
        public string $closureId,
    ) {
        PortableDescriptor::identifier($closureId, 'root closure');
    }
}
