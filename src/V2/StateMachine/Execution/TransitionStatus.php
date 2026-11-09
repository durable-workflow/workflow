<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Execution;

enum TransitionStatus: string
{
    case Unavailable = 'unavailable';
    case Ambiguous = 'ambiguous';
    case Blocked = 'blocked';
    case Unknown = 'unknown';
    case Pending = 'pending';
    case Committed = 'committed';
    case Conflict = 'conflict';
    case CancellationRequested = 'cancellation_requested';
}
