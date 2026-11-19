<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Recovery;

/**
 * State of automatic recovery after the originating run closes.
 */
enum AutomaticRecovery: string
{
    /** No accepted, unverified request is waiting on a closed origin. */
    case NotNeeded = 'not_needed';

    /** The origin closed with an accepted request; the next watchdog pass routes it. */
    case Pending = 'pending';

    /** A request is bound to canonical recovery. */
    case Routed = 'routed';

    /** Re-authorizing the original principal was refused; nothing was started. */
    case RecoveryAuthorizationFailed = 'recovery_authorization_failed';
}
