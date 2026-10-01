<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

/** What an awaiting workflow does when its operation receives cancellation. */
enum CancellationPolicy: string
{
    case TryCancel = 'try_cancel';
    case WaitCancellationCompleted = 'wait_cancellation_completed';
    case Abandon = 'abandon';
}
