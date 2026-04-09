<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum HistoryEventType: string
{
    case StartAccepted = 'StartAccepted';
    case StartRejected = 'StartRejected';
    case WorkflowStarted = 'WorkflowStarted';
    case WorkflowContinuedAsNew = 'WorkflowContinuedAsNew';
    case ChildWorkflowScheduled = 'ChildWorkflowScheduled';
    case ChildRunStarted = 'ChildRunStarted';
    case ChildRunCompleted = 'ChildRunCompleted';
    case ChildRunFailed = 'ChildRunFailed';
    case ChildRunCancelled = 'ChildRunCancelled';
    case ChildRunTerminated = 'ChildRunTerminated';
    case ConditionWaitOpened = 'ConditionWaitOpened';
    case ConditionWaitSatisfied = 'ConditionWaitSatisfied';
    case ConditionWaitTimedOut = 'ConditionWaitTimedOut';
    case SignalWaitOpened = 'SignalWaitOpened';
    case SignalReceived = 'SignalReceived';
    case SignalApplied = 'SignalApplied';
    case UpdateAccepted = 'UpdateAccepted';
    case UpdateRejected = 'UpdateRejected';
    case UpdateApplied = 'UpdateApplied';
    case UpdateCompleted = 'UpdateCompleted';
    case RepairRequested = 'RepairRequested';
    case CancelRequested = 'CancelRequested';
    case WorkflowCancelled = 'WorkflowCancelled';
    case TerminateRequested = 'TerminateRequested';
    case WorkflowTerminated = 'WorkflowTerminated';
    case ArchiveRequested = 'ArchiveRequested';
    case WorkflowArchived = 'WorkflowArchived';
    case ActivityScheduled = 'ActivityScheduled';
    case ActivityStarted = 'ActivityStarted';
    case ActivityHeartbeatRecorded = 'ActivityHeartbeatRecorded';
    case ActivityRetryScheduled = 'ActivityRetryScheduled';
    case ActivityCompleted = 'ActivityCompleted';
    case ActivityFailed = 'ActivityFailed';
    case SideEffectRecorded = 'SideEffectRecorded';
    case VersionMarkerRecorded = 'VersionMarkerRecorded';
    case TimerScheduled = 'TimerScheduled';
    case TimerFired = 'TimerFired';
    case WorkflowCompleted = 'WorkflowCompleted';
    case WorkflowFailed = 'WorkflowFailed';
}
