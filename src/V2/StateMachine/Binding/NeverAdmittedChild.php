<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Binding;

/**
 * A repository result only. Callers must reread the authoritative
 * reservation before settling an obligation; deserialized values are not evidence.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class NeverAdmittedChild
{
    public function __construct(
        public ChildReservation $reservation,
        public string $closureId
    ) {
    }
}
