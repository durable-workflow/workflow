<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Recovery;

/**
 * Public compensation status of one obligation.
 *
 * @api Stable compensation-scope API (#1063). See docs/api-stability.md#compensation-scopes.
 */
enum ObligationStatus: string
{
    /** Compensation has not produced an outcome yet. */
    case Pending = 'pending';

    /** The last attempt could not verify compensation; native retries or a new request continue. */
    case Unresolved = 'unresolved';

    /** The handler reported that compensation definitely cannot complete. */
    case Failed = 'failed';

    /** Compensation is verified with evidence. */
    case Verified = 'verified';
}
