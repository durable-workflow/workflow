<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum CommandOutcome: string
{
    case StartedNew = 'started_new';
    case ReturnedExistingActive = 'returned_existing_active';
    case RejectedDuplicate = 'rejected_duplicate';
    case SignalReceived = 'signal_received';
    case RepairDispatched = 'repair_dispatched';
    case RepairNotNeeded = 'repair_not_needed';
    case Cancelled = 'cancelled';
    case Terminated = 'terminated';
    case RejectedNotStarted = 'rejected_not_started';
    case RejectedNotActive = 'rejected_not_active';
    case RejectedNotCurrent = 'rejected_not_current';
}
