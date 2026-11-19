<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Recovery;

/**
 * Public status of a compensation scope, as reported by ScopeRecovery.
 *
 * @api Stable compensation-scope API (#1063). See docs/api-stability.md#compensation-scopes.
 */
enum ScopeStatus: string
{
    /** Forward work may still register and run; no compensation was requested. */
    case Open = 'open';

    /** Compensation was requested and is not verified yet. */
    case Requested = 'requested';

    /** Every obligation is verified. */
    case Verified = 'verified';

    /** Verified and explicitly released; only the tombstone remains. */
    case Released = 'released';
}
