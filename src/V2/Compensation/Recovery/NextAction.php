<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Recovery;

/**
 * What an operator or host should do next with a scope.
 */
enum NextAction: string
{
    case None = 'none';

    /** The originating run is compensating; observe it. */
    case ObserveOrigin = 'observe_origin';

    /** A recovery run is open; observe it. */
    case ObserveRecovery = 'observe_recovery';

    /** The origin closed; the watchdog will route the accepted request on its next pass. */
    case AwaitAutomaticRecovery = 'await_automatic_recovery';

    /** Automatic recovery was refused or a recovery run failed; submit a new authorized request key. */
    case SubmitFreshAuthorizedRequest = 'submit_fresh_authorized_request';

    /** A recovery run completed but obligations stay unresolved; inspect them. */
    case InspectUnresolvedObligations = 'inspect_unresolved_obligations';
}
