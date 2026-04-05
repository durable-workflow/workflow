<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum HistoryEventType: string
{
    case StartAccepted = 'StartAccepted';
    case StartRejected = 'StartRejected';
    case WorkflowStarted = 'WorkflowStarted';
    case WorkflowContinuedAsNew = 'WorkflowContinuedAsNew';
    case SignalWaitOpened = 'SignalWaitOpened';
    case SignalReceived = 'SignalReceived';
    case SignalApplied = 'SignalApplied';
    case UpdateAccepted = 'UpdateAccepted';
    case UpdateApplied = 'UpdateApplied';
    case UpdateCompleted = 'UpdateCompleted';
    case RepairRequested = 'RepairRequested';
    case CancelRequested = 'CancelRequested';
    case WorkflowCancelled = 'WorkflowCancelled';
    case TerminateRequested = 'TerminateRequested';
    case WorkflowTerminated = 'WorkflowTerminated';
    case ActivityScheduled = 'ActivityScheduled';
    case ActivityCompleted = 'ActivityCompleted';
    case ActivityFailed = 'ActivityFailed';
    case TimerScheduled = 'TimerScheduled';
    case TimerFired = 'TimerFired';
    case WorkflowCompleted = 'WorkflowCompleted';
    case WorkflowFailed = 'WorkflowFailed';
}
