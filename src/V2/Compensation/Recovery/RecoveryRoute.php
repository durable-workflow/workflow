<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Recovery;

/**
 * Where ScopeRecovery::request() sent an accepted request.
 */
enum RecoveryRoute: string
{
    /** The originating run is open and was asked to cancel; its saga compensates. */
    case Origin = 'origin';

    /** The canonical recovery workflow compensates the retained obligations. */
    case Recovery = 'recovery';

    /** The scope was already verified; nothing was started. */
    case Verified = 'verified';
}
