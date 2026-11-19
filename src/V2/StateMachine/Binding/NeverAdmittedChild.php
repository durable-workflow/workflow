<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Binding;

/**
 * @experimental A repository result only. Callers must reread the authoritative
 * reservation before settling an obligation; deserialized values are not evidence.
 */
final readonly class NeverAdmittedChild
{
    public function __construct(
        public ChildReservation $reservation,
        public string $closureId
    ) {
    }
}
