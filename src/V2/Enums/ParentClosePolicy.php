<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum ParentClosePolicy: string
{
    /**
     * Children continue running independently after the parent closes.
     * This is the default behavior.
     */
    case Abandon = 'abandon';

    /**
     * Legacy terminal cancellation. Recorded request_cancel histories retain
     * this behavior. Use RequestCancellation for cooperative cleanup.
     */
    case RequestCancel = 'request_cancel';

    /**
     * Request cooperative cleanup with the parent's original cancellation
     * lineage and deadline. A normally closed parent starts one shared budget.
     */
    case RequestCancellation = 'request_cancellation';

    /**
     * Send a terminate command to open children when the parent closes.
     */
    case Terminate = 'terminate';
}
