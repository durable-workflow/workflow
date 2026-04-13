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
     * Send a cancel command to open children when the parent closes.
     */
    case RequestCancel = 'request_cancel';

    /**
     * Send a terminate command to open children when the parent closes.
     */
    case Terminate = 'terminate';
}
