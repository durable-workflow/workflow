<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum CommandOutcome: string
{
    case StartedNew = 'started_new';
    case ReturnedExistingActive = 'returned_existing_active';
    case RejectedDuplicate = 'rejected_duplicate';
    case SignalReceived = 'signal_received';
    case UpdateCompleted = 'update_completed';
    case UpdateFailed = 'update_failed';
    case RepairDispatched = 'repair_dispatched';
    case RepairNotNeeded = 'repair_not_needed';
    case Cancelled = 'cancelled';
    case Terminated = 'terminated';
    case Archived = 'archived';
    case ArchiveNotNeeded = 'archive_not_needed';
    case RejectedNotStarted = 'rejected_not_started';
    case RejectedNotActive = 'rejected_not_active';
    case RejectedNotCurrent = 'rejected_not_current';
    case RejectedRunNotClosed = 'rejected_run_not_closed';
    case RejectedUnknownSignal = 'rejected_unknown_signal';
    case RejectedUnknownUpdate = 'rejected_unknown_update';
    case RejectedInvalidArguments = 'rejected_invalid_arguments';
    case RejectedCompatibilityBlocked = 'rejected_compatibility_blocked';
    case RejectedPendingSignal = 'rejected_pending_signal';
    case RejectedWorkflowDefinitionUnavailable = 'rejected_workflow_definition_unavailable';
}
