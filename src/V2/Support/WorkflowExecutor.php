<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\ChildCallStatus;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Exceptions\ConditionWaitDefinitionMismatchException;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Exceptions\UnresolvedWorkflowFailureException;
use Workflow\V2\Exceptions\UnsupportedWorkflowYieldException;
use Workflow\V2\Exceptions\WorkflowTimeoutException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Workflow;
use Workflow\WorkflowMetadata;

final class WorkflowExecutor
{
    public function run(WorkflowRun $run, WorkflowTask $task): ?WorkflowTask
    {
        if ($this->timeoutIfDeadlineExpired($run, $task)) {
            return null;
        }

        $workflowClass = WorkflowDefinitionFingerprint::resolveClassForRun($run);
        $workflow = new $workflowClass($run);
        $entryMethod = EntryMethod::forWorkflow($workflow);
        $arguments = $workflow->resolveMethodDependencies($run->workflowArguments(), $entryMethod);

        try {
            $workflowExecution = WorkflowExecution::start($workflow, $arguments, $run->started_at);
        } catch (Throwable $throwable) {
            $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

            return null;
        }

        if (! $workflowExecution->valid()) {
            try {
                $this->completeRun($run, $task, $workflowExecution->getReturn());
            } catch (Throwable $throwable) {
                $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);
            }

            return null;
        }

        $current = $workflowExecution->current();

        $sequence = 1;
        $this->syncWorkflowCursor($workflow, $sequence);
        $historySequenceAtTaskStart = $run->last_history_sequence ?? 0;

        while (true) {
            $eventsInTransaction = ($run->last_history_sequence ?? 0) - $historySequenceAtTaskStart;

            if ($eventsInTransaction > 0) {
                try {
                    StructuralLimits::guardHistoryTransactionSize($eventsInTransaction);
                    $this->logApproachingLimit(
                        StructuralLimits::warnApproachingHistoryTransaction($eventsInTransaction),
                        $run
                    );
                } catch (StructuralLimitExceededException $limitExceeded) {
                    $this->failRun($run, $task, $limitExceeded, 'workflow_run', $run->id);

                    return null;
                }
            }

            if (! $workflowExecution->valid()) {
                try {
                    $this->syncWorkflowCursor($workflow, $sequence);
                    $this->completeRun($run, $task, $workflowExecution->getReturn());
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);
                }

                return null;
            }

            if ($current instanceof LocalActivityCall) {
                $this->syncWorkflowCursorForCurrent($workflow, $run, $current, $sequence);

                if (! $this->applyRecordedUpdates($run, $workflow, $sequence, $task)) {
                    return $this->restartAfterPendingUpdateFailure($run, $task);
                }

                if (! $this->ensureStepHistoryCompatible(
                    $run,
                    $task,
                    $sequence,
                    WorkflowStepHistory::LOCAL_ACTIVITY,
                    ['activity_type' => $current->activity],
                )) {
                    return null;
                }

                $localOutcome = (new LocalActivityExecutor())->execute($run, $task, $sequence, $current);

                if ($localOutcome['status'] === 'waiting') {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);

                    return $this->waitForNextResumeSource($run, $task);
                }

                $activityCompletion = $localOutcome['event'];

                if (! $activityCompletion instanceof WorkflowHistoryEvent) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);

                    return $this->waitForNextResumeSource($run, $task);
                }

                try {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    if ($activityCompletion->event_type === HistoryEventType::ActivityCompleted) {
                        $current = $workflowExecution->send(
                            $this->activityResult($activityCompletion, $run),
                            $activityCompletion->recorded_at,
                        );
                    } else {
                        $failureId = $activityCompletion->payload['failure_id'] ?? null;

                        $current = $workflowExecution->throw(
                            $this->activityException($activityCompletion, null, $run),
                            $activityCompletion->recorded_at,
                        );

                        $this->recordFailureHandled(
                            $run,
                            $task,
                            is_string($failureId) ? $failureId : null,
                            $sequence,
                            $activityCompletion->payload,
                        );
                    }
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                $run->load([
                    'instance',
                    'activityExecutions',
                    'timers',
                    'failures',
                    'tasks',
                    'commands',
                    'updates',
                    'historyEvents',
                    'childLinks.childRun.instance.currentRun',
                    'childLinks.childRun.failures',
                    'childLinks.childRun.historyEvents',
                ]);

                ++$sequence;
                continue;
            }

            if ($current instanceof ActivityCall) {
                $this->syncWorkflowCursorForCurrent($workflow, $run, $current, $sequence);

                if (! $this->applyRecordedUpdates($run, $workflow, $sequence, $task)) {
                    return $this->restartAfterPendingUpdateFailure($run, $task);
                }

                if (! $this->ensureStepHistoryCompatible(
                    $run,
                    $task,
                    $sequence,
                    WorkflowStepHistory::ACTIVITY,
                    ['activity_type' => $current->activity],
                )) {
                    return null;
                }

                $activityCompletion = $this->activityCompletionEvent($run, $sequence);

                if ($activityCompletion !== null) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        if ($activityCompletion->event_type === HistoryEventType::ActivityCompleted) {
                            $current = $workflowExecution->send(
                                $this->activityResult($activityCompletion, $run),
                                $activityCompletion->recorded_at,
                            );
                        } else {
                            $failureId = $activityCompletion->payload['failure_id'] ?? null;

                            $current = $workflowExecution->throw(
                                $this->activityException($activityCompletion, null, $run),
                                $activityCompletion->recorded_at,
                            );

                            $this->recordFailureHandled(
                                $run,
                                $task,
                                is_string($failureId) ? $failureId : null,
                                $sequence,
                                $activityCompletion->payload,
                            );
                        }
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    ++$sequence;
                    continue;
                }

                if ($this->activityOpenEvent($run, $sequence) !== null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return $this->waitForNextResumeSource($run, $task);
                }

                /** @var ActivityExecution|null $execution */
                $execution = $run->activityExecutions->firstWhere('sequence', $sequence);

                if ($execution === null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return $this->scheduleActivityOrFailRun($run, $task, $sequence, $current);
                }

                if (in_array($execution->status, [ActivityStatus::Pending, ActivityStatus::Running], true)) {
                    if (! $this->ensureTypedStepHistoryRecorded(
                        $run,
                        $task,
                        $sequence,
                        WorkflowStepHistory::ACTIVITY
                    )) {
                        return null;
                    }

                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return $this->waitForNextResumeSource($run, $task);
                }

                if (! $this->ensureTypedStepHistoryRecorded($run, $task, $sequence, WorkflowStepHistory::ACTIVITY)) {
                    return null;
                }

                try {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    if ($execution->status === ActivityStatus::Completed) {
                        $current = $workflowExecution->send($execution->activityResult(), $execution->closed_at);
                    } else {
                        $failure = $run->failures
                            ->firstWhere('source_id', $execution->id);

                        $current = $workflowExecution->throw(
                            $this->activityException(null, $execution, $run),
                            $execution->closed_at,
                        );

                        $this->recordFailureHandled(
                            $run,
                            $task,
                            $failure instanceof WorkflowFailure ? $failure->id : null,
                            $sequence,
                            [
                                'source_kind' => 'activity_execution',
                                'source_id' => $execution->id,
                                'exception_class' => $failure?->exception_class,
                                'message' => $failure?->message,
                            ],
                        );
                    }
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                ++$sequence;
                continue;
            }

            if ($current instanceof AwaitCall || $current instanceof AwaitWithTimeoutCall) {
                $this->syncWorkflowCursorForCurrent($workflow, $run, $current, $sequence);

                if (! $this->applyRecordedUpdates($run, $workflow, $sequence, $task)) {
                    return $this->restartAfterPendingUpdateFailure($run, $task);
                }

                try {
                    ConditionWaits::assertReplayCompatible($run, $sequence, $current);
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                $resolutionEvent = $this->conditionWaitResolutionEvent($run, $sequence);

                if ($resolutionEvent !== null) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        $current = $workflowExecution->send(
                            $resolutionEvent->event_type === HistoryEventType::ConditionWaitSatisfied,
                            $resolutionEvent->recorded_at,
                        );
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    ++$sequence;
                    continue;
                }

                $waitId = $this->conditionWaitId($run, $sequence) ?? (string) Str::ulid();

                $this->recordConditionWaitOpened(
                    $run,
                    $task,
                    $sequence,
                    $waitId,
                    $current instanceof AwaitWithTimeoutCall ? $current->seconds : null,
                    $current->conditionKey,
                    $current->conditionDefinitionFingerprint,
                );

                /** @var WorkflowTimer|null $timeoutTimer */
                $timeoutTimer = $current instanceof AwaitWithTimeoutCall
                    ? $run->timers->firstWhere('sequence', $sequence)
                    : null;
                $timeoutScheduledEvent = $current instanceof AwaitWithTimeoutCall
                    ? $this->conditionTimeoutScheduledEvent($run, $sequence)
                    : null;
                $timeoutFiredEvent = $current instanceof AwaitWithTimeoutCall
                    ? $this->conditionTimeoutFiredEvent($run, $sequence)
                    : null;

                if (
                    $current instanceof AwaitWithTimeoutCall
                    && (
                        $timeoutFiredEvent !== null
                        || ($timeoutScheduledEvent === null && $timeoutTimer?->status === TimerStatus::Fired)
                    )
                ) {
                    $this->recordConditionWaitTimedOut(
                        $run,
                        $task,
                        $sequence,
                        $waitId,
                        $timeoutTimer,
                        self::intValue($timeoutFiredEvent?->payload['delay_seconds'] ?? null) ?? $current->seconds,
                        $current->conditionKey,
                        $current->conditionDefinitionFingerprint,
                        $this->stringValue($timeoutFiredEvent?->payload['timer_id'] ?? null),
                    );

                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        $current = $workflowExecution->send(
                            false,
                            $timeoutFiredEvent?->recorded_at ?? $timeoutTimer?->fired_at,
                        );
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    ++$sequence;
                    continue;
                }

                try {
                    $conditionSatisfied = ($current->condition)() === true;
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                if ($conditionSatisfied) {
                    if ($timeoutTimer !== null) {
                        $this->cancelConditionTimeout($run, $task, $timeoutTimer);
                    }

                    $satisfiedEvent = $this->recordConditionWaitSatisfied(
                        $run,
                        $task,
                        $sequence,
                        $waitId,
                        $timeoutTimer,
                        $current
                    );

                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        $current = $workflowExecution->send(true, $satisfiedEvent->recorded_at);
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    ++$sequence;
                    continue;
                }

                if ($current instanceof AwaitWithTimeoutCall) {
                    if ($current->seconds === 0) {
                        $timeoutTimer = $this->fireImmediateConditionTimeout(
                            $run,
                            $task,
                            $sequence,
                            $waitId,
                            $current,
                        );

                        $timedOutEvent = $this->recordConditionWaitTimedOut(
                            $run,
                            $task,
                            $sequence,
                            $waitId,
                            $timeoutTimer,
                            $current->seconds,
                            $current->conditionKey,
                            $current->conditionDefinitionFingerprint,
                        );

                        try {
                            $this->syncWorkflowCursor($workflow, $sequence + 1);
                            $current = $workflowExecution->send(false, $timedOutEvent->recorded_at);
                        } catch (Throwable $throwable) {
                            $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                            return null;
                        }

                        ++$sequence;
                        continue;
                    }

                    if ($timeoutTimer === null) {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        return $this->scheduleConditionTimeout($run, $task, $sequence, $waitId, $current);
                    }
                }

                $this->syncWorkflowCursor($workflow, $sequence + 1);
                return $this->waitForNextResumeSource($run, $task, true);
            }

            if ($current instanceof SideEffectCall) {
                $this->syncWorkflowCursorForCurrent($workflow, $run, $current, $sequence);

                if (! $this->applyRecordedUpdates($run, $workflow, $sequence, $task)) {
                    return $this->restartAfterPendingUpdateFailure($run, $task);
                }

                if (! $this->ensureStepHistoryCompatible($run, $task, $sequence, WorkflowStepHistory::SIDE_EFFECT)) {
                    return null;
                }

                $sideEffectEvent = $this->sideEffectEvent($run, $sequence);

                try {
                    if ($sideEffectEvent === null) {
                        $sideEffectEvent = $this->recordSideEffect($run, $task, $sequence, $current);
                    }

                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    $current = $workflowExecution->send(
                        $this->sideEffectResult($sideEffectEvent, $run),
                        $sideEffectEvent->recorded_at,
                    );
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                ++$sequence;
                continue;
            }

            if ($current instanceof VersionCall) {
                $this->syncWorkflowCursorForCurrent($workflow, $run, $current, $sequence);

                if (! $this->applyRecordedUpdates($run, $workflow, $sequence, $task)) {
                    return $this->restartAfterPendingUpdateFailure($run, $task);
                }

                $versionMarkerEvent = $this->versionMarkerEvent($run, $sequence);

                try {
                    $resolution = VersionResolver::resolve($run, $versionMarkerEvent, $current, $sequence);
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                // Only assert VERSION_MARKER history shape on paths that will
                // occupy a history slot at this sequence (recorded / fresh).
                // The legacy-default path records nothing and does not advance
                // the workflow sequence — the next yield (activity, timer…)
                // legitimately shares this slot, so checking for
                // VERSION_MARKER-compatibility here would spuriously reject
                // legacy replays that have since produced ACTIVITY/TIMER
                // events at the same sequence.
                if ($resolution->advancesSequence
                    && ! $this->ensureStepHistoryCompatible(
                        $run,
                        $task,
                        $sequence,
                        WorkflowStepHistory::VERSION_MARKER,
                        ['change_id' => $current->changeId],
                    )
                ) {
                    return null;
                }

                $version = $resolution->version;

                try {
                    if ($resolution->shouldRecordMarker) {
                        $versionMarkerEvent = $this->recordVersionMarker($run, $task, $sequence, $current, $version);
                    }

                    $this->syncWorkflowCursor($workflow, $sequence + ($resolution->advancesSequence ? 1 : 0));
                    $current = $workflowExecution->send(
                        $current->resolveValue($version),
                        $versionMarkerEvent?->recorded_at
                    );
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                if ($resolution->advancesSequence) {
                    ++$sequence;
                }

                continue;
            }

            if ($current instanceof UpsertSearchAttributesCall) {
                $this->syncWorkflowCursorForCurrent($workflow, $run, $current, $sequence);

                if (! $this->applyRecordedUpdates($run, $workflow, $sequence, $task)) {
                    return $this->restartAfterPendingUpdateFailure($run, $task);
                }

                if (! $this->ensureStepHistoryCompatible(
                    $run,
                    $task,
                    $sequence,
                    WorkflowStepHistory::SEARCH_ATTRIBUTES_UPSERT
                )) {
                    return null;
                }

                $upsertEvent = $this->searchAttributesUpsertedEvent($run, $sequence);

                try {
                    if ($upsertEvent === null) {
                        $upsertEvent = $this->recordSearchAttributesUpserted($run, $task, $sequence, $current);
                    }

                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    $current = $workflowExecution->send(null, $upsertEvent?->recorded_at);
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                ++$sequence;
                continue;
            }

            if ($current instanceof UpsertMemoCall) {
                $this->syncWorkflowCursorForCurrent($workflow, $run, $current, $sequence);

                if (! $this->applyRecordedUpdates($run, $workflow, $sequence, $task)) {
                    return $this->restartAfterPendingUpdateFailure($run, $task);
                }

                if (! $this->ensureStepHistoryCompatible($run, $task, $sequence, WorkflowStepHistory::MEMO_UPSERT)) {
                    return null;
                }

                $memoEvent = $this->memoUpsertedEvent($run, $sequence);

                try {
                    if ($memoEvent === null) {
                        $memoEvent = $this->recordMemoUpserted($run, $task, $sequence, $current);
                    }

                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    $current = $workflowExecution->send(null, $memoEvent?->recorded_at);
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                ++$sequence;
                continue;
            }

            if ($current instanceof TimerCall) {
                $this->syncWorkflowCursorForCurrent($workflow, $run, $current, $sequence);

                if (! $this->applyRecordedUpdates($run, $workflow, $sequence, $task)) {
                    return $this->restartAfterPendingUpdateFailure($run, $task);
                }

                if (! $this->ensureStepHistoryCompatible($run, $task, $sequence, WorkflowStepHistory::TIMER)) {
                    return null;
                }

                $timerFired = $this->timerFiredEvent($run, $sequence);

                if ($timerFired !== null) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        $current = $workflowExecution->send(true, $timerFired->recorded_at);
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    ++$sequence;
                    continue;
                }

                if ($this->timerScheduledEvent($run, $sequence) !== null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);

                    return $this->waitForNextResumeSource($run, $task);
                }

                /** @var WorkflowTimer|null $timer */
                $timer = $run->timers->firstWhere('sequence', $sequence);

                if ($timer === null) {
                    if ($current->seconds === 0) {
                        $fired = $this->fireImmediateTimer($run, $task, $sequence, $current);

                        try {
                            $this->syncWorkflowCursor($workflow, $sequence + 1);
                            $current = $workflowExecution->send(true, $fired->recorded_at);
                        } catch (Throwable $throwable) {
                            $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                            return null;
                        }

                        ++$sequence;

                        continue;
                    }

                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return $this->scheduleTimer($run, $task, $sequence, $current);
                }

                if ($timer->status === TimerStatus::Pending) {
                    if (! $this->ensureTypedStepHistoryRecorded($run, $task, $sequence, WorkflowStepHistory::TIMER)) {
                        return null;
                    }

                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return $this->waitForNextResumeSource($run, $task);
                }

                if (! $this->ensureTypedStepHistoryRecorded($run, $task, $sequence, WorkflowStepHistory::TIMER)) {
                    return null;
                }

                try {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    $current = $workflowExecution->send(true, $timer->fired_at);
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                ++$sequence;
                continue;
            }

            if ($current instanceof SignalCall) {
                $this->syncWorkflowCursorForCurrent($workflow, $run, $current, $sequence);

                if (! $this->applyRecordedUpdates($run, $workflow, $sequence, $task)) {
                    return $this->restartAfterPendingUpdateFailure($run, $task);
                }

                if (! $this->ensureStepHistoryCompatible(
                    $run,
                    $task,
                    $sequence,
                    WorkflowStepHistory::SIGNAL_WAIT,
                    ['signal_name' => $current->name],
                )) {
                    return null;
                }

                $signalEvent = $this->appliedSignalEvent($run, $sequence, $current);

                if ($signalEvent !== null) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        $current = $workflowExecution->send(
                            $this->signalValue($signalEvent, $run),
                            $signalEvent->recorded_at,
                        );
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    ++$sequence;
                    continue;
                }

                /** @var WorkflowTimer|null $timeoutTimer */
                $timeoutTimer = $current->timeoutSeconds !== null
                    ? $run->timers->firstWhere('sequence', $sequence)
                    : null;
                $timeoutScheduledEvent = $current->timeoutSeconds !== null
                    ? $this->signalTimeoutScheduledEvent($run, $sequence, $current->name)
                    : null;
                $timeoutFiredEvent = $current->timeoutSeconds !== null
                    ? $this->signalTimeoutFiredEvent($run, $sequence, $current->name)
                    : null;
                $signalWaitId = $this->signalWaitId($run, $sequence, $current) ?? (string) Str::ulid();
                $signalCommand = $this->pendingSignalCommand($run, $current);

                $timeoutHasWon = (
                    $current->timeoutSeconds !== null
                    && (
                        $timeoutFiredEvent !== null
                        || ($timeoutScheduledEvent === null && $timeoutTimer?->status === TimerStatus::Fired)
                    )
                );

                if ($timeoutHasWon && $signalCommand !== null && $timeoutFiredEvent !== null) {
                    $signalReceivedEvent = $this->signalReceivedEventForCommand($run, $signalCommand);

                    if (
                        $signalReceivedEvent !== null
                        && $signalReceivedEvent->sequence < $timeoutFiredEvent->sequence
                    ) {
                        $timeoutHasWon = false;
                    }
                }

                if ($timeoutHasWon) {
                    $this->recordSignalWait($run, $task, $sequence, $current, $signalWaitId);

                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        $current = $workflowExecution->send(
                            null,
                            $timeoutFiredEvent?->recorded_at ?? $timeoutTimer?->fired_at,
                        );
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    ++$sequence;
                    continue;
                }

                if ($signalCommand !== null) {
                    $signalWaitId = $this->signalWaitIdForCommand($run, $signalCommand, $current->name);

                    $this->recordSignalWait($run, $task, $sequence, $current, $signalWaitId);

                    if ($timeoutTimer !== null) {
                        $this->cancelSignalTimeout($run, $task, $timeoutTimer);
                    }

                    $signalEvent = $this->applySignal(
                        $run,
                        $task,
                        $sequence,
                        $current,
                        $signalCommand,
                        $signalWaitId,
                    );

                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        $current = $workflowExecution->send(
                            $this->signalValue($signalEvent, $run),
                            $signalEvent?->recorded_at,
                        );
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    ++$sequence;
                    continue;
                }

                $this->recordSignalWait($run, $task, $sequence, $current, $signalWaitId);

                if ($current->timeoutSeconds !== null) {
                    if ($current->timeoutSeconds === 0) {
                        $firedTimer = $this->fireImmediateSignalTimeout(
                            $run,
                            $task,
                            $sequence,
                            $signalWaitId,
                            $current
                        );

                        try {
                            $this->syncWorkflowCursor($workflow, $sequence + 1);
                            $current = $workflowExecution->send(null, $firedTimer->fired_at);
                        } catch (Throwable $throwable) {
                            $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                            return null;
                        }

                        ++$sequence;
                        continue;
                    }

                    if ($timeoutTimer === null) {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        return $this->scheduleSignalTimeout($run, $task, $sequence, $signalWaitId, $current);
                    }
                }

                $this->syncWorkflowCursor($workflow, $sequence + 1);
                return $this->waitForNextResumeSource($run, $task, true);
            }

            if ($current instanceof ServiceOperationCall) {
                $this->syncWorkflowCursorForCurrent($workflow, $run, $current, $sequence);

                if (! $this->applyRecordedUpdates($run, $workflow, $sequence, $task)) {
                    return $this->restartAfterPendingUpdateFailure($run, $task);
                }

                if (! $this->ensureStepHistoryCompatible(
                    $run,
                    $task,
                    $sequence,
                    WorkflowStepHistory::SERVICE_OPERATION,
                    ['operation_name' => $current->operationName],
                )) {
                    return null;
                }

                $serviceEvent = $this->serviceOperationEvent($run, $sequence);

                if ($serviceEvent === null) {
                    try {
                        $serviceEvent = $this->recordServiceOperationEvent($run, $task, $sequence, $current);
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }
                }

                if (
                    $serviceEvent->event_type === HistoryEventType::ServiceCallStarted
                    && ! self::serviceOperationStartedEventIsVisible($serviceEvent, $current)
                ) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);

                    return $this->waitForNextResumeSource($run, $task);
                }

                try {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);

                    if (in_array($serviceEvent->event_type, [
                        HistoryEventType::ServiceCallFailed,
                        HistoryEventType::ServiceCallCancelled,
                    ], true)) {
                        $current = $workflowExecution->throw(
                            $this->serviceOperationException($serviceEvent),
                            $serviceEvent->recorded_at,
                        );

                        $this->recordFailureHandled(
                            $run,
                            $task,
                            null,
                            $sequence,
                            $serviceEvent->payload,
                        );
                    } else {
                        $current = $workflowExecution->send(
                            $this->serviceOperationResult($serviceEvent, $run),
                            $serviceEvent->recorded_at,
                        );
                    }
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                ++$sequence;
                continue;
            }

            if ($current instanceof ChildWorkflowCall) {
                $this->syncWorkflowCursorForCurrent($workflow, $run, $current, $sequence);

                if (! $this->applyRecordedUpdates($run, $workflow, $sequence, $task)) {
                    return $this->restartAfterPendingUpdateFailure($run, $task);
                }

                if (! $this->ensureStepHistoryCompatible(
                    $run,
                    $task,
                    $sequence,
                    WorkflowStepHistory::CHILD_WORKFLOW,
                    ['child_workflow_type' => $current->workflow],
                )) {
                    return null;
                }

                $resolutionEvent = ChildRunHistory::resolutionEventForSequence($run, $sequence);
                $childRun = ChildRunHistory::childRunForSequence($run, $sequence);

                if ($resolutionEvent !== null) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        if ($resolutionEvent->event_type === HistoryEventType::ChildRunCompleted) {
                            $current = $workflowExecution->send(
                                ChildRunHistory::outputForResolution($resolutionEvent, $childRun),
                                $resolutionEvent->recorded_at,
                            );
                        } else {
                            $failureId = $resolutionEvent->payload['failure_id'] ?? null;
                            $current = $workflowExecution->throw(
                                ChildRunHistory::exceptionForResolution($resolutionEvent, $childRun),
                                $resolutionEvent->recorded_at,
                            );

                            $this->recordFailureHandled(
                                $run,
                                $task,
                                is_string($failureId) ? $failureId : null,
                                $sequence,
                                $this->childFailureHandledPayload($resolutionEvent),
                            );
                        }
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    ++$sequence;
                    continue;
                }

                if ($childRun === null) {
                    if (ChildRunHistory::scheduledEventForSequence($run, $sequence) !== null
                        || ChildRunHistory::startedEventForSequence($run, $sequence) !== null) {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        return $this->waitForNextResumeSource($run, $task);
                    }

                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return $this->scheduleChildWorkflowOrFailRun($run, $task, $sequence, $current);
                }

                $childStatus = ChildRunHistory::resolvedStatus(null, $childRun);

                if (in_array($childStatus, [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting], true)) {
                    if (! $this->ensureTypedStepHistoryRecorded(
                        $run,
                        $task,
                        $sequence,
                        WorkflowStepHistory::CHILD_WORKFLOW
                    )) {
                        return null;
                    }

                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return $this->waitForNextResumeSource($run, $task);
                }

                if (! $this->ensureTypedStepHistoryRecorded(
                    $run,
                    $task,
                    $sequence,
                    WorkflowStepHistory::CHILD_WORKFLOW
                )) {
                    return null;
                }

                $resolutionEvent = $this->recordChildResolution($run, $task, $sequence, $childRun);

                try {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    if ($resolutionEvent->event_type === HistoryEventType::ChildRunCompleted) {
                        $current = $workflowExecution->send(
                            ChildRunHistory::outputForResolution($resolutionEvent, $childRun),
                            $resolutionEvent->recorded_at,
                        );
                    } else {
                        $failureId = $resolutionEvent->payload['failure_id'] ?? null;
                        $current = $workflowExecution->throw(
                            ChildRunHistory::exceptionForResolution($resolutionEvent, $childRun),
                            $resolutionEvent->recorded_at,
                        );

                        $this->recordFailureHandled(
                            $run,
                            $task,
                            is_string($failureId) ? $failureId : null,
                            $sequence,
                            $this->childFailureHandledPayload($resolutionEvent),
                        );
                    }
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                ++$sequence;
                continue;
            }

            if ($current instanceof AllCall) {
                $this->syncWorkflowCursorForCurrent($workflow, $run, $current, $sequence);

                if (! $this->applyRecordedUpdates($run, $workflow, $sequence, $task)) {
                    return $this->restartAfterPendingUpdateFailure($run, $task);
                }

                $leafDescriptors = $current->leafDescriptors($sequence);
                $groupSize = count($leafDescriptors);

                try {
                    StructuralLimits::guardCommandBatchSize($groupSize);
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                if ($groupSize === 0) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence);
                        $current = $workflowExecution->send($current->nestedResults([]));
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    continue;
                }

                foreach ($leafDescriptors as $descriptor) {
                    $call = $descriptor['call'];
                    $itemSequence = $sequence + $descriptor['offset'];

                    if (! $this->ensureStepHistoryCompatible(
                        $run,
                        $task,
                        $itemSequence,
                        $call instanceof ActivityCall
                            ? WorkflowStepHistory::ACTIVITY
                            : WorkflowStepHistory::CHILD_WORKFLOW,
                        $call instanceof ActivityCall
                            ? ['activity_type' => $call->activity]
                            : ['child_workflow_type' => $call->workflow],
                    )) {
                        return null;
                    }
                }

                if (! $this->ensureParallelGroupHistoryCompatible($run, $task, $sequence, $leafDescriptors)) {
                    return null;
                }

                $scheduledTasks = [];
                $pending = false;
                $results = [];
                $failure = null;
                $successTime = null;

                foreach ($leafDescriptors as $descriptor) {
                    $call = $descriptor['call'];
                    $offset = $descriptor['offset'];
                    $itemSequence = $sequence + $offset;
                    $parallelMetadata = ParallelChildGroup::payloadForPath($descriptor['group_path']);

                    if ($call instanceof ActivityCall) {
                        $activityCompletion = $this->activityCompletionEvent($run, $itemSequence);

                        if ($activityCompletion !== null) {
                            if ($activityCompletion->event_type === HistoryEventType::ActivityCompleted) {
                                $results[$offset] = $this->activityResult($activityCompletion, $run);
                                $successTime = $this->latestReplayTime(
                                    $successTime,
                                    $activityCompletion->recorded_at ?? $activityCompletion->created_at,
                                );

                                continue;
                            }

                            $failure = ParallelFailureSelector::select(
                                $failure,
                                $offset,
                                $this->activityException($activityCompletion, null, $run),
                                $activityCompletion->recorded_at?->getTimestampMs()
                                    ?? $activityCompletion->created_at?->getTimestampMs()
                                    ?? PHP_INT_MAX,
                                is_string($activityCompletion->payload['failure_id'] ?? null)
                                    ? $activityCompletion->payload['failure_id']
                                    : null,
                                $activityCompletion->payload,
                            );

                            continue;
                        }

                        if ($this->activityOpenEvent($run, $itemSequence) !== null) {
                            $pending = true;

                            continue;
                        }

                        /** @var ActivityExecution|null $execution */
                        $execution = $run->activityExecutions->firstWhere('sequence', $itemSequence);

                        if (! $execution instanceof ActivityExecution) {
                            $activityTask = $this->scheduleActivityOrFailRun(
                                $run,
                                $task,
                                $itemSequence,
                                $call,
                                $parallelMetadata,
                                false,
                            );
                            if (! $activityTask instanceof WorkflowTask) {
                                return null;
                            }

                            $scheduledTasks[] = $activityTask;
                            $pending = true;

                            continue;
                        }

                        if (in_array($execution->status, [
                            ActivityStatus::Pending,
                            ActivityStatus::Running,
                        ], true)) {
                            if (! $this->ensureTypedStepHistoryRecorded(
                                $run,
                                $task,
                                $itemSequence,
                                WorkflowStepHistory::ACTIVITY,
                            )) {
                                return null;
                            }

                            $pending = true;

                            continue;
                        }

                        if (! $this->ensureTypedStepHistoryRecorded(
                            $run,
                            $task,
                            $itemSequence,
                            WorkflowStepHistory::ACTIVITY,
                        )) {
                            return null;
                        }

                        if ($execution->status === ActivityStatus::Completed) {
                            $results[$offset] = $execution->activityResult();
                            $successTime = $this->latestReplayTime(
                                $successTime,
                                $execution->closed_at ?? $execution->updated_at ?? $execution->created_at,
                            );

                            continue;
                        }

                        /** @var WorkflowFailure|null $activityFailure */
                        $activityFailure = $run->failures->firstWhere('source_id', $execution->id);

                        $failure = ParallelFailureSelector::select(
                            $failure,
                            $offset,
                            $this->activityException(null, $execution, $run),
                            $execution->closed_at?->getTimestampMs() ?? PHP_INT_MAX,
                            $activityFailure?->id,
                            [
                                'source_kind' => 'activity_execution',
                                'source_id' => $execution->id,
                                'exception_class' => $activityFailure?->exception_class,
                                'message' => $activityFailure?->message,
                            ],
                        );

                        continue;
                    }

                    $resolutionEvent = ChildRunHistory::resolutionEventForSequence($run, $itemSequence);
                    $childRun = ChildRunHistory::childRunForSequence($run, $itemSequence);

                    if ($resolutionEvent === null && $childRun instanceof WorkflowRun) {
                        $childStatus = ChildRunHistory::resolvedStatus(null, $childRun);

                        if ($childStatus instanceof RunStatus && ! in_array($childStatus, [
                            RunStatus::Pending,
                            RunStatus::Running,
                            RunStatus::Waiting,
                        ], true)) {
                            if (! $this->ensureTypedStepHistoryRecorded(
                                $run,
                                $task,
                                $itemSequence,
                                WorkflowStepHistory::CHILD_WORKFLOW,
                            )) {
                                return null;
                            }

                            $resolutionEvent = $this->recordChildResolution($run, $task, $itemSequence, $childRun);
                        }
                    }

                    if ($resolutionEvent !== null) {
                        if ($resolutionEvent->event_type === HistoryEventType::ChildRunCompleted) {
                            $results[$offset] = ChildRunHistory::outputForResolution($resolutionEvent, $childRun);
                            $successTime = $this->latestReplayTime(
                                $successTime,
                                $resolutionEvent->recorded_at ?? $resolutionEvent->created_at,
                            );

                            continue;
                        }

                        $failure = ParallelFailureSelector::select(
                            $failure,
                            $offset,
                            ChildRunHistory::exceptionForResolution($resolutionEvent, $childRun),
                            $resolutionEvent->recorded_at?->getTimestampMs()
                                ?? $resolutionEvent->created_at?->getTimestampMs()
                                ?? PHP_INT_MAX,
                            is_string($resolutionEvent->payload['failure_id'] ?? null)
                                ? $resolutionEvent->payload['failure_id']
                                : null,
                            $this->childFailureHandledPayload($resolutionEvent),
                        );

                        continue;
                    }

                    if (! $childRun instanceof WorkflowRun) {
                        if (
                            ChildRunHistory::scheduledEventForSequence($run, $itemSequence) !== null
                            || ChildRunHistory::startedEventForSequence($run, $itemSequence) !== null
                        ) {
                            $pending = true;

                            continue;
                        }

                        $childTask = $this->scheduleChildWorkflowOrFailRun(
                            $run,
                            $task,
                            $itemSequence,
                            $call,
                            $parallelMetadata,
                            false,
                        );
                        if (! $childTask instanceof WorkflowTask) {
                            return null;
                        }

                        $scheduledTasks[] = $childTask;
                        $pending = true;

                        continue;
                    }

                    $childStatus = ChildRunHistory::resolvedStatus(null, $childRun);

                    if (! $childStatus instanceof RunStatus || in_array($childStatus, [
                        RunStatus::Pending,
                        RunStatus::Running,
                        RunStatus::Waiting,
                    ], true)) {
                        if (! $this->ensureTypedStepHistoryRecorded(
                            $run,
                            $task,
                            $itemSequence,
                            WorkflowStepHistory::CHILD_WORKFLOW,
                        )) {
                            return null;
                        }

                        $pending = true;

                        continue;
                    }

                    if (! $this->ensureTypedStepHistoryRecorded(
                        $run,
                        $task,
                        $itemSequence,
                        WorkflowStepHistory::CHILD_WORKFLOW,
                    )) {
                        return null;
                    }

                    if ($childStatus === RunStatus::Completed) {
                        $results[$offset] = ChildRunHistory::outputForChildRun($childRun);
                        $successTime = $this->latestReplayTime(
                            $successTime,
                            $childRun->closed_at ?? $childRun->updated_at ?? $childRun->created_at,
                        );

                        continue;
                    }

                    $failure = ParallelFailureSelector::select(
                        $failure,
                        $offset,
                        ChildRunHistory::exceptionForChildRun($childRun),
                        $childRun->closed_at?->getTimestampMs() ?? PHP_INT_MAX,
                    );
                }

                if ($scheduledTasks !== []) {
                    $this->logApproachingLimit(StructuralLimits::warnApproachingCommandBatch($groupSize), $run);
                }

                if ($failure !== null) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + $groupSize);
                        $failureTime = isset($failure['recorded_at']) && is_int(
                            $failure['recorded_at']
                        ) && $failure['recorded_at'] !== PHP_INT_MAX
                            ? \Carbon\Carbon::createFromTimestampMs($failure['recorded_at'])
                            : null;
                        $current = $workflowExecution->throw($failure['exception'], $failureTime);

                        $this->recordFailureHandled(
                            $run,
                            $task,
                            is_string($failure['failure_id'] ?? null) ? $failure['failure_id'] : null,
                            $sequence + (int) $failure['index'],
                            is_array($failure['failure_payload'] ?? null) ? $failure['failure_payload'] : [],
                        );
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    $sequence += $groupSize;

                    continue;
                }

                if (! $pending) {
                    ksort($results);

                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + $groupSize);
                        $current = $workflowExecution->send(
                            $current->nestedResults(array_values($results)),
                            $successTime,
                        );
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    $sequence += $groupSize;

                    continue;
                }

                $this->markRunWaiting($run, $task);

                foreach ($scheduledTasks as $scheduledTask) {
                    TaskDispatcher::dispatch($scheduledTask);
                }

                $this->syncWorkflowCursor($workflow, $sequence + $groupSize);
                return null;
            }

            if ($current instanceof ContinueAsNewCall) {
                if (! $this->ensureStepHistoryCompatible(
                    $run,
                    $task,
                    $sequence,
                    WorkflowStepHistory::CONTINUE_AS_NEW
                )) {
                    return null;
                }

                try {
                    return $this->continueAsNew($run, $task, $sequence, $current, $workflowClass);
                } catch (StructuralLimitExceededException $limitExceeded) {
                    $this->failRun($run, $task, $limitExceeded, 'workflow_run', $run->id);

                    return null;
                }
            }

            $this->failRun(
                $run,
                $task,
                new UnsupportedWorkflowYieldException(sprintf(
                    'Workflow %s yielded %s. v2 currently supports activity(), child(), async(), all(), parallel(), await(), signal(), timer(), sideEffect(), continueAsNew(), getVersion(), patched(), deprecatePatch(), upsertMemo(), and upsertSearchAttributes() only.',
                    $run->workflow_class,
                    get_debug_type($current),
                )),
                'workflow_run',
                $run->id,
            );

            return null;
        }
    }

    private function scheduleActivity(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        ActivityCall $activityCall,
        ?array $parallelMetadata = null,
        bool $parkRun = true,
    ): WorkflowTask {
        StructuralLimits::guardPendingActivities($run);
        $this->logApproachingLimit(StructuralLimits::warnApproachingPendingActivities($run), $run);

        EntryMethod::describeActivity($activityCall->activity);

        $options = $activityCall->options;

        $scheduleDeadlineAt = $options?->scheduleToStartTimeout !== null
            ? now()
                ->addSeconds($options->scheduleToStartTimeout)
            : null;

        $scheduleToCloseDeadlineAt = $options?->scheduleToCloseTimeout !== null
            ? now()
                ->addSeconds($options->scheduleToCloseTimeout)
            : null;

        // Activity arguments often contain consumer-side PHP objects (messages,
        // DTOs, value objects) that the v2 default Avro codec can only encode
        // by round-tripping through JSON. Persist the chosen codec beside the
        // activity row so later reads never depend on payload sniffing.
        $argumentsCodec = Serializer::chooseCodecForData($run->payload_codec, $activityCall->arguments);
        $serializedArguments = Serializer::serializeWithCodec($argumentsCodec, $activityCall->arguments);
        $this->logApproachingLimit(
            StructuralLimits::warnApproachingPayloadSize($serializedArguments),
            $run,
            [
                'payload_site' => 'activity_input',
                'activity_class' => $activityCall->activity,
            ],
        );
        $storedArguments = ExternalPayloads::externalizeForNamespace(
            $serializedArguments,
            $argumentsCodec,
            is_string($run->namespace) ? $run->namespace : null,
        );
        StructuralLimits::guardPayloadSize($storedArguments);

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'activity_class' => $activityCall->activity,
            'activity_type' => TypeRegistry::for($activityCall->activity),
            'status' => ActivityStatus::Pending->value,
            'attempt_count' => 0,
            'payload_codec' => $argumentsCodec,
            'arguments' => $storedArguments,
            'connection' => RoutingResolver::activityConnection($activityCall->activity, $run, $options),
            'queue' => RoutingResolver::activityQueue($activityCall->activity, $run, $options),
            'parallel_group_path' => self::parallelGroupPath($parallelMetadata),
            'activity_options' => $options?->toSnapshot(),
            'schedule_deadline_at' => $scheduleDeadlineAt,
            'schedule_to_close_deadline_at' => $scheduleToCloseDeadlineAt,
        ]);
        $activityClass = TypeRegistry::resolveActivityClass($execution->activity_class, $execution->activity_type);
        $activity = new $activityClass($execution, $run, $task->id);

        $execution->forceFill([
            'retry_policy' => ActivityRetryPolicy::snapshot($activity, $options),
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, array_merge([
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $sequence,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ], $parallelMetadata ?? []), $task);

        /** @var WorkflowTask $activityTask */
        $activityTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'connection' => $execution->connection,
            'queue' => $execution->queue,
            'compatibility' => $run->compatibility,
            ...TaskSchedulingFields::forActivity($run, $execution),
        ]);

        if ($parkRun) {
            $this->markRunWaiting($run, $task);
        }

        return $activityTask;
    }

    private function scheduleActivityOrFailRun(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        ActivityCall $activityCall,
        ?array $parallelMetadata = null,
        bool $parkRun = true,
    ): ?WorkflowTask {
        try {
            return $this->scheduleActivity($run, $task, $sequence, $activityCall, $parallelMetadata, $parkRun);
        } catch (Throwable $throwable) {
            $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

            return null;
        }
    }

    private function scheduleConditionTimeout(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        string $waitId,
        AwaitWithTimeoutCall $awaitWithTimeout,
    ): WorkflowTask {
        $fireAt = now()
            ->addSeconds($awaitWithTimeout->seconds);

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'status' => TimerStatus::Pending->value,
            'delay_seconds' => $awaitWithTimeout->seconds,
            'fire_at' => $fireAt,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, [
            'timer_id' => $timer->id,
            'sequence' => $sequence,
            'delay_seconds' => $timer->delay_seconds,
            'fire_at' => $timer->fire_at?->toJSON(),
            'timer_kind' => 'condition_timeout',
            'condition_wait_id' => $waitId,
            'condition_key' => $awaitWithTimeout->conditionKey,
            'condition_definition_fingerprint' => $awaitWithTimeout->conditionDefinitionFingerprint,
        ], $task);

        /** @var WorkflowTask $timerTask */
        $timerTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Timer->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $fireAt,
            'payload' => array_filter([
                'timer_id' => $timer->id,
                'condition_wait_id' => $waitId,
                'condition_key' => $awaitWithTimeout->conditionKey,
                'condition_definition_fingerprint' => $awaitWithTimeout->conditionDefinitionFingerprint,
            ], static fn (mixed $value): bool => $value !== null),
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
        ]);

        $this->markRunWaiting($run, $task, true);

        return $timerTask;
    }

    private function scheduleSignalTimeout(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        string $waitId,
        SignalCall $signalCall,
    ): WorkflowTask {
        $timeoutSeconds = $signalCall->timeoutSeconds ?? 0;
        $fireAt = now()
            ->addSeconds($timeoutSeconds);

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'status' => TimerStatus::Pending->value,
            'delay_seconds' => $timeoutSeconds,
            'fire_at' => $fireAt,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, [
            'timer_id' => $timer->id,
            'sequence' => $sequence,
            'delay_seconds' => $timer->delay_seconds,
            'fire_at' => $timer->fire_at?->toJSON(),
            'timer_kind' => 'signal_timeout',
            'signal_wait_id' => $waitId,
            'signal_name' => $signalCall->name,
        ], $task);

        /** @var WorkflowTask $timerTask */
        $timerTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Timer->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $fireAt,
            'payload' => [
                'timer_id' => $timer->id,
                'signal_wait_id' => $waitId,
                'signal_name' => $signalCall->name,
            ],
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
        ]);

        $this->markRunWaiting($run, $task, true);

        return $timerTask;
    }

    private function scheduleChildWorkflow(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        ChildWorkflowCall $childWorkflowCall,
        ?array $parallelMetadata = null,
        bool $parkRun = true,
    ): WorkflowTask {
        StructuralLimits::guardPendingChildren($run);
        $this->logApproachingLimit(StructuralLimits::warnApproachingPendingChildren($run), $run);

        $metadata = WorkflowMetadata::fromStartArguments($childWorkflowCall->arguments);
        $workflowType = TypeRegistry::for($childWorkflowCall->workflow);
        $commandContract = RunCommandContract::snapshot($childWorkflowCall->workflow);
        $now = now();

        // Inherit the parent's run codec for the child so the encoded
        // arguments (next line) match the codec stamped on the child run.
        // Falling back to the package default when the parent has none
        // keeps pre-pinned parents working.
        $preferredChildCodec = is_string($run->payload_codec) && $run->payload_codec !== ''
            ? $run->payload_codec
            : CodecRegistry::defaultCodec();
        // When the child arguments carry PHP-only values (e.g. a
        // SerializableClosure produced by async()), Avro cannot round-trip
        // them. Pick the actually-used codec so the row's `payload_codec`
        // matches what the blob was serialized with.
        $childCodec = Serializer::chooseCodecForData($preferredChildCodec, $metadata->arguments);
        $serializedChildArguments = Serializer::serializeWithCodec($childCodec, $metadata->arguments);
        $this->logApproachingLimit(
            StructuralLimits::warnApproachingPayloadSize($serializedChildArguments),
            $run,
            [
                'payload_site' => 'child_workflow_input',
                'child_workflow_class' => $childWorkflowCall->workflow,
            ],
        );
        $storedChildArguments = ExternalPayloads::externalizeForNamespace(
            $serializedChildArguments,
            $childCodec,
            is_string($run->namespace) ? $run->namespace : null,
        );
        StructuralLimits::guardPayloadSize($storedChildArguments);

        /** @var WorkflowInstance $childInstance */
        $childInstance = WorkflowInstance::query()->create([
            'workflow_class' => $childWorkflowCall->workflow,
            'workflow_type' => $workflowType,
            'namespace' => $run->namespace,
            'reserved_at' => $now,
            'started_at' => $now,
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => $childWorkflowCall->workflow,
            'workflow_type' => $workflowType,
            'namespace' => $run->namespace,
            'business_key' => null,
            'visibility_labels' => null,
            'status' => RunStatus::Pending->value,
            'compatibility' => $run->compatibility ?? WorkerCompatibility::current(),
            'payload_codec' => $childCodec,
            'arguments' => $storedChildArguments,
            'connection' => RoutingResolver::workflowConnection($childWorkflowCall->workflow, $metadata),
            'queue' => RoutingResolver::workflowQueue($childWorkflowCall->workflow, $metadata),
            'started_at' => $now,
            'last_progress_at' => $now,
            'last_history_sequence' => 0,
        ]);

        $this->inheritTypedVisibilityMetadata($run, $childRun);

        $childInstance->forceFill([
            'current_run_id' => $childRun->id,
        ])->save();
        $childCallId = (string) Str::ulid();

        $startCommand = $this->recordWorkflowStartCommand(
            $run,
            $sequence,
            $childInstance,
            $childRun,
            $metadata->arguments,
            $now,
            $childCallId,
        );

        $parentClosePolicy = $childWorkflowCall->options?->parentClosePolicy
            ?? ParentClosePolicy::Abandon;

        ChildRunHistory::recordChildCallStarted([
            'parent_workflow_run_id' => $run->id,
            'parent_workflow_instance_id' => $run->workflow_instance_id,
            'sequence' => $sequence,
            'child_workflow_type' => $workflowType,
            'child_workflow_class' => $childWorkflowCall->workflow,
            'parent_close_policy' => $parentClosePolicy->value,
            'connection' => $childRun->connection,
            'queue' => $childRun->queue,
            'compatibility' => $childRun->compatibility,
            'cancellation_propagation' => false,
            'status' => ChildCallStatus::Started,
            'scheduled_at' => $now,
            'started_at' => $now,
            'arguments' => [
                'payload' => $storedChildArguments,
                'payload_codec' => $childCodec,
            ],
            'metadata' => [
                'child_call_id' => $childCallId,
                'attempt_count' => 1,
            ],
            'resolved_child_instance_id' => $childInstance->id,
            'resolved_child_run_id' => $childRun->id,
        ]);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()->create([
            'id' => $childCallId,
            'link_type' => 'child_workflow',
            'sequence' => $sequence,
            'parent_workflow_instance_id' => $run->workflow_instance_id,
            'parent_workflow_run_id' => $run->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
            'parent_close_policy' => $parentClosePolicy->value,
            'parallel_group_path' => self::parallelGroupPath($parallelMetadata),
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ChildWorkflowScheduled, array_merge([
            'sequence' => $sequence,
            'workflow_link_id' => $link->id,
            'child_call_id' => $childCallId,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_class' => $childRun->workflow_class,
            'child_workflow_type' => $childRun->workflow_type,
            'parent_close_policy' => $parentClosePolicy->value,
        ], $parallelMetadata ?? []), $task);

        WorkflowHistoryEvent::record($run, HistoryEventType::ChildRunStarted, array_merge([
            'sequence' => $sequence,
            'workflow_link_id' => $link->id,
            'child_call_id' => $childCallId,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_class' => $childRun->workflow_class,
            'child_workflow_type' => $childRun->workflow_type,
            'child_run_number' => $childRun->run_number,
            'parent_close_policy' => $parentClosePolicy->value,
        ], $parallelMetadata ?? []), $task);

        WorkflowHistoryEvent::record($childRun, HistoryEventType::StartAccepted, [
            'workflow_command_id' => $startCommand->id,
            'workflow_instance_id' => $childRun->workflow_instance_id,
            'workflow_run_id' => $childRun->id,
            'workflow_class' => $childRun->workflow_class,
            'workflow_type' => $childRun->workflow_type,
            'outcome' => $startCommand->outcome?->value,
        ], null, $startCommand);

        WorkflowHistoryEvent::record($childRun, HistoryEventType::WorkflowStarted, [
            'workflow_class' => $childRun->workflow_class,
            'workflow_type' => $childRun->workflow_type,
            'workflow_instance_id' => $childRun->workflow_instance_id,
            'workflow_run_id' => $childRun->id,
            'workflow_command_id' => $startCommand->id,
            'workflow_definition_fingerprint' => WorkflowDefinition::fingerprint($childRun->workflow_class),
            'parent_workflow_instance_id' => $run->workflow_instance_id,
            'parent_workflow_run_id' => $run->id,
            'parent_sequence' => $sequence,
            'workflow_link_id' => $link->id,
            'child_call_id' => $childCallId,
            'declared_queries' => $commandContract['queries'],
            'declared_query_contracts' => $commandContract['query_contracts'],
            'declared_signals' => $commandContract['signals'],
            'declared_signal_contracts' => $commandContract['signal_contracts'],
            'declared_updates' => $commandContract['updates'],
            'declared_update_contracts' => $commandContract['update_contracts'],
            'declared_entry_method' => $commandContract['entry_method'],
            'declared_entry_mode' => $commandContract['entry_mode'],
            'declared_entry_declaring_class' => $commandContract['entry_declaring_class'],
        ], null, $startCommand);

        /** @var WorkflowTask $childTask */
        $childTask = WorkflowTask::query()->create([
            'workflow_run_id' => $childRun->id,
            'namespace' => $childRun->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $now,
            'payload' => [],
            'connection' => $childRun->connection,
            'queue' => $childRun->queue,
            'compatibility' => $childRun->compatibility,
        ]);

        if ($parkRun) {
            $this->markRunWaiting($run, $task);
        }

        $this->projectRun(
            $childRun->fresh([
                'instance',
                'tasks',
                'activityExecutions',
                'timers',
                'failures',
                'historyEvents',
                'childLinks.childRun.instance.currentRun',
                'childLinks.childRun.failures',
            ])
        );

        return $childTask;
    }

    private function scheduleChildWorkflowOrFailRun(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        ChildWorkflowCall $childWorkflowCall,
        ?array $parallelMetadata = null,
        bool $parkRun = true,
    ): ?WorkflowTask {
        try {
            return $this->scheduleChildWorkflow(
                $run,
                $task,
                $sequence,
                $childWorkflowCall,
                $parallelMetadata,
                $parkRun
            );
        } catch (Throwable $throwable) {
            $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

            return null;
        }
    }

    private function scheduleTimer(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        TimerCall $timerCall,
    ): WorkflowTask {
        StructuralLimits::guardPendingTimers($run);
        $this->logApproachingLimit(StructuralLimits::warnApproachingPendingTimers($run), $run);

        $fireAt = now()
            ->addSeconds($timerCall->seconds);

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'status' => TimerStatus::Pending->value,
            'delay_seconds' => $timerCall->seconds,
            'fire_at' => $fireAt,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, [
            'timer_id' => $timer->id,
            'sequence' => $sequence,
            'delay_seconds' => $timer->delay_seconds,
            'fire_at' => $timer->fire_at?->toJSON(),
        ], $task);

        /** @var WorkflowTask $timerTask */
        $timerTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Timer->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $fireAt,
            'payload' => [
                'timer_id' => $timer->id,
            ],
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
        ]);

        $this->markRunWaiting($run, $task);

        return $timerTask;
    }

    private function fireImmediateTimer(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        TimerCall $timerCall,
    ): WorkflowHistoryEvent {
        $recordedAt = now();

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'status' => TimerStatus::Fired->value,
            'delay_seconds' => $timerCall->seconds,
            'fire_at' => $recordedAt,
            'fired_at' => $recordedAt,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, [
            'timer_id' => $timer->id,
            'sequence' => $sequence,
            'delay_seconds' => $timer->delay_seconds,
            'fire_at' => $timer->fire_at?->toJSON(),
        ], $task);

        return WorkflowHistoryEvent::record($run, HistoryEventType::TimerFired, [
            'timer_id' => $timer->id,
            'sequence' => $sequence,
            'delay_seconds' => $timer->delay_seconds,
            'fired_at' => $timer->fired_at?->toJSON(),
        ], $task);
    }

    private function fireImmediateConditionTimeout(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        string $waitId,
        AwaitWithTimeoutCall $awaitWithTimeout,
    ): WorkflowTimer {
        $recordedAt = now();

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'status' => TimerStatus::Fired->value,
            'delay_seconds' => $awaitWithTimeout->seconds,
            'fire_at' => $recordedAt,
            'fired_at' => $recordedAt,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, [
            'timer_id' => $timer->id,
            'sequence' => $sequence,
            'delay_seconds' => $timer->delay_seconds,
            'fire_at' => $timer->fire_at?->toJSON(),
            'timer_kind' => 'condition_timeout',
            'condition_wait_id' => $waitId,
            'condition_key' => $awaitWithTimeout->conditionKey,
            'condition_definition_fingerprint' => $awaitWithTimeout->conditionDefinitionFingerprint,
        ], $task);

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerFired, [
            'timer_id' => $timer->id,
            'sequence' => $sequence,
            'delay_seconds' => $timer->delay_seconds,
            'fired_at' => $timer->fired_at?->toJSON(),
            'timer_kind' => 'condition_timeout',
            'condition_wait_id' => $waitId,
            'condition_key' => $awaitWithTimeout->conditionKey,
            'condition_definition_fingerprint' => $awaitWithTimeout->conditionDefinitionFingerprint,
        ], $task);

        return $timer;
    }

    private function fireImmediateSignalTimeout(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        string $waitId,
        SignalCall $signalCall,
    ): WorkflowTimer {
        $recordedAt = now();
        $timeoutSeconds = $signalCall->timeoutSeconds ?? 0;

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'status' => TimerStatus::Fired->value,
            'delay_seconds' => $timeoutSeconds,
            'fire_at' => $recordedAt,
            'fired_at' => $recordedAt,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, [
            'timer_id' => $timer->id,
            'sequence' => $sequence,
            'delay_seconds' => $timer->delay_seconds,
            'fire_at' => $timer->fire_at?->toJSON(),
            'timer_kind' => 'signal_timeout',
            'signal_wait_id' => $waitId,
            'signal_name' => $signalCall->name,
        ], $task);

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerFired, [
            'timer_id' => $timer->id,
            'sequence' => $sequence,
            'delay_seconds' => $timer->delay_seconds,
            'fired_at' => $timer->fired_at?->toJSON(),
            'timer_kind' => 'signal_timeout',
            'signal_wait_id' => $waitId,
            'signal_name' => $signalCall->name,
        ], $task);

        return $timer;
    }

    private function appliedSignalEvent(
        WorkflowRun $run,
        int $sequence,
        SignalCall $signalCall,
    ): ?WorkflowHistoryEvent {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::SignalApplied
                && ($event->payload['sequence'] ?? null) === $sequence
                && ($event->payload['signal_name'] ?? null) === $signalCall->name
        );

        return $event;
    }

    private function pendingSignalCommand(WorkflowRun $run, SignalCall $signalCall): ?WorkflowCommand
    {
        /** @var WorkflowCommand|null $command */
        $command = $run->commands
            ->filter(
                static fn (WorkflowCommand $command): bool => $command->command_type === CommandType::Signal
                    && $command->status === CommandStatus::Accepted
                    && $command->applied_at === null
                    && WorkflowPayloadDecoder::commandTargetName($command, [
                        'workflow_id' => $run->workflow_instance_id,
                        'run_id' => $run->id,
                        'signal_name' => $signalCall->name,
                    ]) === $signalCall->name
            )
            ->sort(static function (WorkflowCommand $left, WorkflowCommand $right): int {
                $leftSequence = $left->command_sequence ?? PHP_INT_MAX;
                $rightSequence = $right->command_sequence ?? PHP_INT_MAX;

                if ($leftSequence !== $rightSequence) {
                    return $leftSequence <=> $rightSequence;
                }

                $leftCreatedAt = $left->created_at?->getTimestampMs() ?? PHP_INT_MAX;
                $rightCreatedAt = $right->created_at?->getTimestampMs() ?? PHP_INT_MAX;

                if ($leftCreatedAt !== $rightCreatedAt) {
                    return $leftCreatedAt <=> $rightCreatedAt;
                }

                return $left->id <=> $right->id;
            })
            ->first();

        return $command;
    }

    private function signalReceivedEventForCommand(WorkflowRun $run, WorkflowCommand $command): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::SignalReceived
                && $event->workflow_command_id === $command->id
        );

        return $event;
    }

    private function applySignal(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        SignalCall $signalCall,
        WorkflowCommand $command,
        string $signalWaitId,
    ): WorkflowHistoryEvent {
        $value = $this->signalPayloadValue($command, $signalCall->name);
        $signal = WorkflowSignal::query()
            ->where('workflow_command_id', $command->id)
            ->first();

        $command->forceFill([
            'applied_at' => now(),
        ])->save();

        if ($signal instanceof WorkflowSignal) {
            $signal->forceFill([
                'signal_wait_id' => $signalWaitId,
                'status' => SignalStatus::Applied->value,
                'workflow_sequence' => $sequence,
                'applied_at' => $command->applied_at,
                'closed_at' => $command->applied_at,
            ])->save();
        }

        if ($command->message_sequence !== null) {
            MessageStreamCursor::advanceCursor($run, (int) $command->message_sequence, $task);
        }

        return WorkflowHistoryEvent::record($run, HistoryEventType::SignalApplied, array_filter([
            'workflow_command_id' => $command->id,
            'signal_id' => $signal?->id,
            'signal_name' => $signalCall->name,
            'signal_wait_id' => $signalWaitId,
            'sequence' => $sequence,
            'value' => Serializer::serializeWithCodec($run->payload_codec, $value),
        ], static fn (mixed $payloadValue): bool => $payloadValue !== null), $task, $command);
    }

    private function recordSignalWait(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        SignalCall $signalCall,
        ?string $signalWaitId = null,
    ): void {
        $alreadyRecorded = $run->historyEvents->contains(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::SignalWaitOpened
                && ($event->payload['sequence'] ?? null) === $sequence
                && ($event->payload['signal_name'] ?? null) === $signalCall->name
        );

        if ($alreadyRecorded) {
            return;
        }

        WorkflowHistoryEvent::record($run, HistoryEventType::SignalWaitOpened, array_filter([
            'signal_name' => $signalCall->name,
            'signal_wait_id' => $signalWaitId ?? (string) Str::ulid(),
            'sequence' => $sequence,
            'timeout_seconds' => $signalCall->timeoutSeconds,
        ], static fn (mixed $value): bool => $value !== null), $task);
    }

    private function signalWaitIdForCommand(WorkflowRun $run, WorkflowCommand $command, string $signalName): string
    {
        $receivedEvent = $this->signalReceivedEventForCommand($run, $command);

        $signalWaitId = $receivedEvent === null
            ? null
            : $this->stringValue($receivedEvent->payload['signal_wait_id'] ?? null);

        return $signalWaitId
            ?? SignalWaits::openWaitIdForName($run, $signalName)
            ?? SignalWaits::bufferedWaitIdForCommandId($command->id);
    }

    private function signalWaitId(WorkflowRun $run, int $sequence, SignalCall $signalCall): ?string
    {
        /** @var WorkflowHistoryEvent|null $openedEvent */
        $openedEvent = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::SignalWaitOpened
                && ($event->payload['sequence'] ?? null) === $sequence
                && ($event->payload['signal_name'] ?? null) === $signalCall->name
        );

        /** @var WorkflowHistoryEvent|null $timeoutEvent */
        $timeoutEvent = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => in_array(
                $event->event_type,
                [HistoryEventType::TimerScheduled, HistoryEventType::TimerFired, HistoryEventType::TimerCancelled],
                true,
            )
                && ($event->payload['timer_kind'] ?? null) === 'signal_timeout'
                && ($event->payload['sequence'] ?? null) === $sequence
                && ($event->payload['signal_name'] ?? null) === $signalCall->name
        );

        return $this->stringValue($openedEvent?->payload['signal_wait_id'] ?? null)
            ?? $this->stringValue($timeoutEvent?->payload['signal_wait_id'] ?? null)
            ?? SignalWaits::openWaitIdForName($run, $signalCall->name);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function isInternalTimeoutTimerKind(mixed $value): bool
    {
        return in_array($value, ['condition_timeout', 'signal_timeout'], true);
    }

    private function signalValue(WorkflowHistoryEvent $event, ?WorkflowRun $run = null): mixed
    {
        $serialized = $event->payload['value'] ?? null;

        if (! is_string($serialized)) {
            return null;
        }

        return WorkflowPayloadDecoder::unserializeWithRun($serialized, $run, [
            'workflow_id' => $run?->workflow_instance_id,
            'run_id' => $run?->id,
            'event_id' => $event->id,
            'signal_name' => $this->stringValue($event->payload['signal_name'] ?? null),
        ]);
    }

    private function signalPayloadValue(WorkflowCommand $command, string $signalName): mixed
    {
        $arguments = WorkflowPayloadDecoder::commandArguments($command, [
            'workflow_id' => $command->workflow_instance_id,
            'run_id' => $command->workflow_run_id,
            'signal_name' => $signalName,
        ]);

        if ($arguments === []) {
            return true;
        }

        if (count($arguments) === 1) {
            return $arguments[0];
        }

        return $arguments;
    }

    private function recordChildResolution(
        WorkflowRun $run,
        ?WorkflowTask $task,
        int $sequence,
        WorkflowRun $childRun,
    ): WorkflowHistoryEvent {
        $link = ChildRunHistory::latestLinkForSequence($run, $sequence);
        $eventType = match (ChildRunHistory::resolvedStatus(null, $childRun)) {
            RunStatus::Completed => HistoryEventType::ChildRunCompleted,
            RunStatus::Cancelled => HistoryEventType::ChildRunCancelled,
            RunStatus::Terminated => HistoryEventType::ChildRunTerminated,
            default => HistoryEventType::ChildRunFailed,
        };

        ChildRunHistory::markChildCallResolved($run, $sequence, $childRun);

        $alreadyRecorded = $run->historyEvents->contains(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === $eventType
                && ($event->payload['sequence'] ?? null) === $sequence
                && ($event->payload['child_workflow_run_id'] ?? null) === $childRun->id
        );

        if ($alreadyRecorded) {
            /** @var WorkflowHistoryEvent $event */
            $event = $run->historyEvents->first(
                static fn (WorkflowHistoryEvent $event): bool => $event->event_type === $eventType
                    && ($event->payload['sequence'] ?? null) === $sequence
                    && ($event->payload['child_workflow_run_id'] ?? null) === $childRun->id
            );

            return $event;
        }

        $childTerminalEvent = $childRun->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => in_array($event->event_type, [
                    HistoryEventType::WorkflowCompleted,
                    HistoryEventType::WorkflowFailed,
                    HistoryEventType::WorkflowCancelled,
                    HistoryEventType::WorkflowTerminated,
                ], true)
            )
            ->sortByDesc('sequence')
            ->first();
        $failure = $childRun->failures->first();
        $parallelMetadataPath = ChildRunHistory::parallelGroupPathForSequence($run, $sequence);
        $parallelMetadata = ParallelChildGroup::payloadForPath($parallelMetadataPath);
        $childOutput = $childTerminalEvent?->event_type === HistoryEventType::WorkflowCompleted
            ? $childTerminalEvent->payload['output'] ?? $childRun->output
            : null;
        $childOutputCodec = $childOutput !== null
            ? self::nonEmptyString($childTerminalEvent?->payload['payload_codec'] ?? null)
                ?? $childRun->outputPayloadCodec()
            : null;

        return WorkflowHistoryEvent::record($run, $eventType, array_filter([
            'sequence' => $sequence,
            'workflow_link_id' => $link?->id,
            'child_call_id' => ChildRunHistory::childCallIdForSequence($run, $sequence),
            'child_workflow_instance_id' => $childRun->workflow_instance_id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_class' => $childRun->workflow_class,
            'child_workflow_type' => $childRun->workflow_type,
            'child_run_number' => $childRun->run_number,
            'child_status' => $childRun->status->value,
            'closed_reason' => $childRun->closed_reason,
            'closed_at' => $childRun->closed_at?->toJSON(),
            'output' => $childOutput,
            'result' => $childOutput,
            'payload_codec' => $childOutputCodec,
            'failure_id' => $failure?->id,
            'failure_category' => match ($eventType) {
                HistoryEventType::ChildRunFailed => $failure?->failure_category ?? FailureCategory::ChildWorkflow->value,
                HistoryEventType::ChildRunCancelled => $failure?->failure_category ?? FailureCategory::Cancelled->value,
                HistoryEventType::ChildRunTerminated => $failure?->failure_category ?? FailureCategory::Terminated->value,
                default => null,
            },
            'exception' => $childTerminalEvent?->event_type === HistoryEventType::WorkflowFailed
                ? $childTerminalEvent->payload['exception'] ?? null
                : null,
            'exception_type' => $childTerminalEvent?->event_type === HistoryEventType::WorkflowFailed
                ? $childTerminalEvent->payload['exception_type'] ?? null
                : null,
            'exception_class' => $childTerminalEvent?->event_type === HistoryEventType::WorkflowFailed
                ? $childTerminalEvent->payload['exception_class'] ?? $failure?->exception_class
                : $failure?->exception_class,
            'message' => $childTerminalEvent?->event_type === HistoryEventType::WorkflowFailed
                ? $childTerminalEvent->payload['message'] ?? $failure?->message
                : $failure?->message,
            'code' => $childTerminalEvent?->event_type === HistoryEventType::WorkflowFailed
                ? $childTerminalEvent->payload['code'] ?? null
                : null,
            ...($parallelMetadata ?? []),
        ], static fn ($value): bool => $value !== null), $task);
    }

    private function waitForNextResumeSource(
        WorkflowRun $run,
        WorkflowTask $task,
        bool $signalsCanAdvance = false,
    ): ?WorkflowTask
    {
        $this->markRunWaiting($run, $task, $signalsCanAdvance);

        return null;
    }

    private function markRunWaiting(WorkflowRun $run, WorkflowTask $task, bool $signalsCanAdvance = false): void
    {
        $run->forceFill([
            'status' => RunStatus::Waiting,
        ])->save();

        $task->forceFill([
            'status' => TaskStatus::Completed,
            'lease_expires_at' => null,
        ])->save();

        $signalTask = $signalsCanAdvance
            ? $this->createPendingSignalResumeTask($run, self::workflowSignalIdForTask($task))
            : null;

        if ($signalTask instanceof WorkflowTask) {
            TaskDispatcher::dispatch($signalTask);
        }

        $this->projectRun(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
    }

    private function createPendingSignalResumeTask(
        WorkflowRun $run,
        ?string $alreadyAttemptedSignalId = null,
    ): ?WorkflowTask
    {
        if (self::hasOpenWorkflowTask($run->id)) {
            return null;
        }

        /** @var \Illuminate\Support\Collection<int, WorkflowSignal> $signals */
        $signals = WorkflowSignal::query()
            ->where('workflow_run_id', $run->id)
            ->where('status', SignalStatus::Received->value)
            ->whereNull('closed_at')
            ->orderByRaw('CASE WHEN command_sequence IS NULL THEN 1 ELSE 0 END')
            ->orderBy('command_sequence')
            ->orderBy('received_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $freshRun = $run->fresh(['historyEvents']) ?? $run;
        $hasAdvanceableConditionWait = self::hasAdvanceableConditionWait($freshRun);
        $openSignalWaitsById = self::openSignalWaitsById($freshRun);

        $eligibleSignals = $signals
            ->filter(
                static fn (WorkflowSignal $candidate): bool => self::signalCanAdvanceOpenWait(
                    $candidate,
                    $hasAdvanceableConditionWait,
                    $openSignalWaitsById,
                )
            )
            ->values();

        /** @var WorkflowSignal|null $signal */
        $signal = null;
        $afterAttemptedSignal = $alreadyAttemptedSignalId === null;

        foreach ($eligibleSignals as $candidate) {
            if ($afterAttemptedSignal) {
                $signal = $candidate;
                break;
            }

            if ($candidate->id === $alreadyAttemptedSignalId) {
                $afterAttemptedSignal = true;
            }
        }

        if (! $afterAttemptedSignal) {
            $signal = $eligibleSignals->first();
        }

        if (! $signal instanceof WorkflowSignal) {
            return null;
        }

        /** @var WorkflowTask $signalTask */
        $signalTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => WorkflowTaskPayload::forSignal($signal),
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
        ]);

        return $signalTask;
    }

    /**
     * @param array<string, string> $openSignalWaitsById
     */
    private static function signalCanAdvanceOpenWait(
        WorkflowSignal $signal,
        bool $hasAdvanceableConditionWait,
        array $openSignalWaitsById,
    ): bool {
        if ($hasAdvanceableConditionWait) {
            return true;
        }

        $signalWaitId = self::nonEmptyString($signal->signal_wait_id);

        return $signalWaitId !== null
            && ($openSignalWaitsById[$signalWaitId] ?? null) === $signal->signal_name;
    }

    private static function hasAdvanceableConditionWait(WorkflowRun $run): bool
    {
        foreach (ConditionWaits::forRun($run) as $wait) {
            if (($wait['status'] ?? null) === 'open' && ($wait['source_status'] ?? null) !== 'timeout_fired') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private static function openSignalWaitsById(WorkflowRun $run): array
    {
        $waits = [];

        foreach (SignalWaits::forRun($run) as $wait) {
            if (($wait['status'] ?? null) !== 'open') {
                continue;
            }

            $signalWaitId = self::nonEmptyString($wait['signal_wait_id'] ?? null);
            $signalName = self::nonEmptyString($wait['signal_name'] ?? null);

            if ($signalWaitId !== null && $signalName !== null) {
                $waits[$signalWaitId] = $signalName;
            }
        }

        return $waits;
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function workflowSignalIdForTask(WorkflowTask $task): ?string
    {
        $payload = is_array($task->payload) ? $task->payload : [];
        $signalId = $payload['workflow_signal_id'] ?? $payload['resume_source_id'] ?? null;

        return is_string($signalId) && $signalId !== ''
            ? $signalId
            : null;
    }

    private static function hasOpenWorkflowTask(string $runId): bool
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->exists();
    }

    private function continueAsNew(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        ContinueAsNewCall $continueAsNew,
        string $workflowClass,
    ): WorkflowTask {
        $now = now();
        $commandContract = RunCommandContract::snapshot($workflowClass);
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()
            ->lockForUpdate()
            ->findOrFail($run->workflow_instance_id);

        if ($instance->workflow_class !== $workflowClass) {
            $instance->forceFill([
                'workflow_class' => $workflowClass,
            ])->save();
        }

        $runTimeoutSeconds = $run->run_timeout_seconds;
        $executionDeadlineAt = $run->execution_deadline_at;
        $runDeadlineAt = $runTimeoutSeconds !== null
            ? $now->copy()
                ->addSeconds((int) $runTimeoutSeconds)
            : null;

        $continueAsNewArguments = Serializer::serializeWithCodec($run->payload_codec, $continueAsNew->arguments);
        $this->logApproachingLimit(
            StructuralLimits::warnApproachingPayloadSize($continueAsNewArguments),
            $run,
            [
                'payload_site' => 'continue_as_new_input',
                'target_workflow_class' => $workflowClass,
            ],
        );
        $storedContinueAsNewArguments = ExternalPayloads::externalizeForNamespace(
            $continueAsNewArguments,
            is_string($run->payload_codec) ? $run->payload_codec : CodecRegistry::defaultCodec(),
            is_string($run->namespace) ? $run->namespace : null,
        );
        StructuralLimits::guardPayloadSize($storedContinueAsNewArguments);

        /** @var WorkflowRun $continuedRun */
        $continuedRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $run->workflow_instance_id,
            'run_number' => $run->run_number + 1,
            'workflow_class' => $workflowClass,
            'workflow_type' => $run->workflow_type,
            'namespace' => $run->namespace,
            'business_key' => $run->business_key,
            'visibility_labels' => $run->visibility_labels,
            'run_timeout_seconds' => $runTimeoutSeconds,
            'execution_deadline_at' => $executionDeadlineAt,
            'run_deadline_at' => $runDeadlineAt,
            'status' => RunStatus::Pending->value,
            'compatibility' => $run->compatibility,
            'payload_codec' => $run->payload_codec,
            'arguments' => $storedContinueAsNewArguments,
            'connection' => $run->connection,
            'queue' => $run->queue,
            'started_at' => $now,
            'last_progress_at' => $now,
            'last_history_sequence' => 0,
        ]);

        $this->inheritTypedVisibilityMetadata($run, $continuedRun);

        $instance->forceFill([
            'current_run_id' => $continuedRun->id,
            'run_count' => $continuedRun->run_number,
        ])->save();

        MessageStreamCursor::transferCursor($run, $continuedRun);

        $childCallId = ChildRunHistory::childCallIdForRun($run);

        $startCommand = $this->recordWorkflowStartCommand(
            $run,
            $sequence,
            $instance,
            $continuedRun,
            $continueAsNew->arguments,
            $now,
            $childCallId,
        );
        $this->transferAcceptedUpdatesToContinuedRun($run, $continuedRun);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()->create([
            'link_type' => 'continue_as_new',
            'sequence' => $sequence,
            'parent_workflow_instance_id' => $run->workflow_instance_id,
            'parent_workflow_run_id' => $run->id,
            'child_workflow_instance_id' => $continuedRun->workflow_instance_id,
            'child_workflow_run_id' => $continuedRun->id,
            'is_primary_parent' => true,
        ]);

        $parentChildLinks = WorkflowLink::query()
            ->where('child_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->lockForUpdate()
            ->get();

        $parentRunsToProject = [];

        foreach ($parentChildLinks as $parentChildLink) {
            $continuedChildLink = WorkflowLink::query()->create([
                'link_type' => 'child_workflow',
                'sequence' => $parentChildLink->sequence,
                'parent_workflow_instance_id' => $parentChildLink->parent_workflow_instance_id,
                'parent_workflow_run_id' => $parentChildLink->parent_workflow_run_id,
                'child_workflow_instance_id' => $continuedRun->workflow_instance_id,
                'child_workflow_run_id' => $continuedRun->id,
                'is_primary_parent' => $parentChildLink->is_primary_parent,
                'parallel_group_path' => $parentChildLink->parallel_group_path,
                'parent_close_policy' => $parentChildLink->parent_close_policy,
            ]);

            if (
                ! is_string($parentChildLink->parent_workflow_run_id)
                || $parentChildLink->parent_workflow_run_id === ''
            ) {
                continue;
            }

            /** @var WorkflowRun|null $parentRun */
            $parentRun = WorkflowRun::query()
                ->lockForUpdate()
                ->find($parentChildLink->parent_workflow_run_id);

            if (
                ! $parentRun instanceof WorkflowRun
                || in_array($parentRun->status, [
                    RunStatus::Completed,
                    RunStatus::Failed,
                    RunStatus::Cancelled,
                    RunStatus::Terminated,
                ], true)
            ) {
                continue;
            }

            $parentRunsToProject[$parentRun->id] = true;
            $parentRun->loadMissing('historyEvents');
            ChildRunHistory::markChildCallContinued(
                $parentRun,
                (int) $parentChildLink->sequence,
                $continuedRun,
                $run,
                $childCallId,
            );

            $alreadyRecorded = $parentRun->historyEvents->contains(
                static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::ChildRunStarted
                    && ($event->payload['sequence'] ?? null) === $parentChildLink->sequence
                    && ($event->payload['child_workflow_run_id'] ?? null) === $continuedRun->id
            );

            if ($alreadyRecorded) {
                continue;
            }

            $parallelMetadata = is_int($parentChildLink->sequence)
                ? ParallelChildGroup::payloadForPath(
                    ChildRunHistory::parallelGroupPathForSequence($parentRun, $parentChildLink->sequence)
                )
                : [];

            WorkflowHistoryEvent::record($parentRun, HistoryEventType::ChildRunStarted, array_filter(array_merge([
                'sequence' => $parentChildLink->sequence,
                'workflow_link_id' => $continuedChildLink->id,
                'child_call_id' => $childCallId,
                'child_workflow_instance_id' => $continuedRun->workflow_instance_id,
                'child_workflow_run_id' => $continuedRun->id,
                'child_workflow_class' => $continuedRun->workflow_class,
                'child_workflow_type' => $continuedRun->workflow_type,
                'child_run_number' => $continuedRun->run_number,
                'parent_close_policy' => $continuedChildLink->parent_close_policy,
            ], $parallelMetadata), static fn ($value): bool => $value !== null));
        }

        $run->forceFill([
            'status' => RunStatus::Completed,
            'closed_reason' => 'continued',
            'closed_at' => $now,
            'last_progress_at' => $now,
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowContinuedAsNew, [
            'sequence' => $sequence,
            'continued_to_run_id' => $continuedRun->id,
            'continued_to_run_number' => $continuedRun->run_number,
            'workflow_link_id' => $link->id,
            'closed_reason' => 'continued',
        ], $task);

        $parentReference = ChildRunHistory::parentReferenceForRun($run);

        $continuedMemo = $continuedRun->typedMemos();
        $continuedSearchAttributes = $continuedRun->typedSearchAttributes();

        WorkflowHistoryEvent::record($continuedRun, HistoryEventType::StartAccepted, [
            'workflow_command_id' => $startCommand->id,
            'workflow_instance_id' => $continuedRun->workflow_instance_id,
            'workflow_run_id' => $continuedRun->id,
            'workflow_class' => $continuedRun->workflow_class,
            'workflow_type' => $continuedRun->workflow_type,
            'business_key' => $continuedRun->business_key,
            'visibility_labels' => $continuedRun->visibility_labels,
            'memo' => $continuedMemo,
            'search_attributes' => $continuedSearchAttributes,
            'outcome' => $startCommand->outcome?->value,
        ], null, $startCommand);

        WorkflowHistoryEvent::record($continuedRun, HistoryEventType::WorkflowStarted, [
            'workflow_class' => $continuedRun->workflow_class,
            'workflow_type' => $continuedRun->workflow_type,
            'workflow_instance_id' => $continuedRun->workflow_instance_id,
            'workflow_run_id' => $continuedRun->id,
            'workflow_command_id' => $startCommand->id,
            'business_key' => $continuedRun->business_key,
            'visibility_labels' => $continuedRun->visibility_labels,
            'memo' => $continuedMemo,
            'search_attributes' => $continuedSearchAttributes,
            'workflow_definition_fingerprint' => WorkflowDefinition::fingerprint($continuedRun->workflow_class),
            'continued_from_run_id' => $run->id,
            'workflow_link_id' => $link->id,
            'child_call_id' => $childCallId,
            'declared_queries' => $commandContract['queries'],
            'declared_query_contracts' => $commandContract['query_contracts'],
            'declared_signals' => $commandContract['signals'],
            'declared_signal_contracts' => $commandContract['signal_contracts'],
            'declared_updates' => $commandContract['updates'],
            'declared_update_contracts' => $commandContract['update_contracts'],
            'declared_entry_method' => $commandContract['entry_method'],
            'declared_entry_mode' => $commandContract['entry_mode'],
            'declared_entry_declaring_class' => $commandContract['entry_declaring_class'],
            'parent_workflow_instance_id' => $parentReference['parent_workflow_instance_id'] ?? null,
            'parent_workflow_run_id' => $parentReference['parent_workflow_run_id'] ?? null,
            'parent_sequence' => $parentReference['parent_sequence'] ?? null,
        ], null, $startCommand);

        /** @var WorkflowTask $continuedTask */
        $continuedTask = WorkflowTask::query()->create([
            'workflow_run_id' => $continuedRun->id,
            'namespace' => $continuedRun->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $now,
            'payload' => [],
            'connection' => $continuedRun->connection,
            'queue' => $continuedRun->queue,
            'compatibility' => $continuedRun->compatibility,
        ]);

        $task->forceFill([
            'status' => TaskStatus::Completed,
            'lease_expires_at' => null,
        ])->save();

        LifecycleEventDispatcher::workflowStarted($continuedRun);

        $this->projectRun(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
        foreach (array_keys($parentRunsToProject) as $parentRunId) {
            /** @var WorkflowRun|null $parentRun */
            $parentRun = WorkflowRun::query()->find($parentRunId);

            if (! $parentRun instanceof WorkflowRun) {
                continue;
            }

            $this->projectRun(
                $parentRun->fresh([
                    'instance',
                    'tasks',
                    'activityExecutions',
                    'timers',
                    'failures',
                    'historyEvents',
                    'childLinks.childRun.instance.currentRun',
                    'childLinks.childRun.failures',
                    'childLinks.childRun.historyEvents',
                ])
            );
        }
        $this->projectRun(
            $continuedRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        return $continuedTask;
    }

    private function transferAcceptedUpdatesToContinuedRun(WorkflowRun $closingRun, WorkflowRun $continuedRun): void
    {
        $updates = WorkflowUpdate::query()
            ->where('workflow_run_id', $closingRun->id)
            ->where('target_scope', 'instance')
            ->where('status', UpdateStatus::Accepted->value)
            ->whereNull('workflow_sequence')
            ->lockForUpdate()
            ->get();

        foreach ($updates as $update) {
            if (! $update instanceof WorkflowUpdate) {
                continue;
            }

            /** @var WorkflowCommand|null $command */
            $command = $update->workflow_command_id === null
                ? null
                : WorkflowCommand::query()
                    ->lockForUpdate()
                    ->find($update->workflow_command_id);

            $update->forceFill([
                'workflow_run_id' => $continuedRun->id,
                'resolved_workflow_run_id' => $continuedRun->id,
            ])->save();

            if ($command instanceof WorkflowCommand
                && $command->command_type === CommandType::Update
                && $command->status === CommandStatus::Accepted
            ) {
                $command->forceFill([
                    'workflow_run_id' => $continuedRun->id,
                    'resolved_workflow_run_id' => $continuedRun->id,
                ])->save();
            }

            WorkflowHistoryEvent::record($continuedRun, HistoryEventType::UpdateAccepted, [
                'workflow_command_id' => $command?->id,
                'update_id' => $update->id,
                'workflow_instance_id' => $continuedRun->workflow_instance_id,
                'workflow_run_id' => $continuedRun->id,
                'update_name' => $update->update_name,
                'arguments' => $update->arguments,
            ], null, $command);
        }
    }

    private function completeRun(WorkflowRun $run, WorkflowTask $task, mixed $result): void
    {
        $outputCodec = is_string($run->payload_codec) && $run->payload_codec !== ''
            ? $run->payload_codec
            : CodecRegistry::defaultCodec();
        $serializedOutput = Serializer::serializeWithCodec($outputCodec, $result);
        $this->logApproachingLimit(
            StructuralLimits::warnApproachingPayloadSize($serializedOutput),
            $run,
            [
                'payload_site' => 'workflow_output',
            ],
        );
        $storedOutput = ExternalPayloads::externalizeForNamespace(
            $serializedOutput,
            $outputCodec,
            is_string($run->namespace) ? $run->namespace : null,
        );
        StructuralLimits::guardPayloadSize($storedOutput);

        $run->forceFill([
            'status' => RunStatus::Completed,
            'closed_reason' => 'completed',
            'output' => $storedOutput,
            'output_payload_codec' => $outputCodec,
            'closed_at' => now(),
            'last_progress_at' => now(),
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted, [
            'output' => ExternalPayloads::historyValue(
                $run->output,
                $outputCodec,
                is_string($run->namespace) ? $run->namespace : null,
            ),
            'payload_codec' => $outputCodec,
        ], $task);

        $task->forceFill([
            'status' => TaskStatus::Completed,
            'lease_expires_at' => null,
        ])->save();

        LifecycleEventDispatcher::workflowCompleted($run);

        ParentClosePolicyEnforcer::enforce($run);

        $this->dispatchParentResumeTasks($run);

        $this->projectRun(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function recordWorkflowStartCommand(
        WorkflowRun $sourceRun,
        int $sourceSequence,
        WorkflowInstance $targetInstance,
        WorkflowRun $targetRun,
        array $arguments,
        mixed $recordedAt,
        ?string $childCallId = null,
    ): WorkflowCommand {
        $startCommandCodec = Serializer::chooseCodecForData(
            $sourceRun->payload_codec ?? CodecRegistry::defaultCodec(),
            $arguments,
        );
        $startCommandPayload = Serializer::serializeWithCodec($startCommandCodec, $arguments);
        $startCommandPayload = ExternalPayloads::externalizeForNamespace(
            $startCommandPayload,
            $startCommandCodec,
            is_string($targetRun->namespace) ? $targetRun->namespace : null,
        );

        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::record(
            $targetInstance,
            $targetRun,
            array_merge(
                CommandContext::workflow(
                    $sourceRun->workflow_instance_id,
                    $sourceRun->id,
                    $sourceSequence,
                    $childCallId,
                )->attributes(),
                [
                    'command_type' => CommandType::Start->value,
                    'target_scope' => 'instance',
                    'status' => CommandStatus::Accepted->value,
                    'outcome' => CommandOutcome::StartedNew->value,
                    'payload_codec' => $startCommandCodec,
                    'payload' => $startCommandPayload,
                    'accepted_at' => $recordedAt,
                    'applied_at' => $recordedAt,
                    'created_at' => $recordedAt,
                    'updated_at' => $recordedAt,
                ],
            ),
        );

        return $command;
    }

    /**
     * Close a run whose server-enforced execution or run deadline elapsed.
     *
     * External worker bridges call this while holding the task and run locks,
     * immediately before accepting commands. This makes the deadline the
     * authority at the workflow-task commit boundary, even when the watchdog
     * cannot repair a run because its task remains leased.
     */
    public function timeoutIfDeadlineExpired(WorkflowRun $run, WorkflowTask $task): bool
    {
        $run->load([
            'instance',
            'activityExecutions',
            'timers',
            'failures',
            'tasks',
            'commands',
            'updates',
            'historyEvents',
            'childLinks.childRun.instance.currentRun',
            'childLinks.childRun.failures',
            'childLinks.childRun.historyEvents',
        ]);

        if (! $this->deadlineExpired($run)) {
            return false;
        }

        $this->timeoutRun($run, $task);

        return true;
    }

    private function deadlineExpired(WorkflowRun $run): bool
    {
        $now = now();

        if ($run->execution_deadline_at !== null && $now->gte($run->execution_deadline_at)) {
            return true;
        }

        if ($run->run_deadline_at !== null && $now->gte($run->run_deadline_at)) {
            return true;
        }

        return false;
    }

    private function timeoutRun(WorkflowRun $run, WorkflowTask $task): void
    {
        $now = now();

        $exception = ($run->execution_deadline_at !== null && $now->gte($run->execution_deadline_at))
            ? WorkflowTimeoutException::executionTimeout($run->execution_deadline_at->toIso8601String())
            : WorkflowTimeoutException::runTimeout($run->run_deadline_at->toIso8601String());

        $timeoutKind = $exception->timeoutKind;
        $message = $exception->getMessage();
        $exceptionClass = WorkflowTimeoutException::class;

        // Cancel all open tasks except the current one.
        $openTasks = $run->tasks
            ->filter(
                static fn (WorkflowTask $t): bool => in_array($t->status, [TaskStatus::Ready, TaskStatus::Leased], true)
                && $t->id !== $task->id
            );

        foreach ($openTasks as $openTask) {
            $openTask->forceFill([
                'status' => TaskStatus::Cancelled,
                'lease_expires_at' => null,
                'last_error' => null,
            ])->save();
        }

        // Cancel open activity executions with history events.
        $tasksByActivityExecutionId = $openTasks
            ->filter(static fn (WorkflowTask $t): bool => is_string($t->payload['activity_execution_id'] ?? null))
            ->keyBy(static fn (WorkflowTask $t): string => $t->payload['activity_execution_id']);

        $openActivityExecutions = $run->activityExecutions
            ->filter(
                static fn (ActivityExecution $e): bool => in_array($e->status, [
                    ActivityStatus::Pending,
                    ActivityStatus::Running,
                ], true)
            );

        foreach ($openActivityExecutions as $execution) {
            $execution->forceFill([
                'status' => ActivityStatus::Cancelled,
                'closed_at' => $execution->closed_at ?? $now,
            ])->save();

            /** @var WorkflowTask|null $activityTask */
            $activityTask = $tasksByActivityExecutionId->get($execution->id);

            ActivityCancellation::record($run, $execution, $activityTask);
        }

        // Cancel open timers with history events.
        $openTimers = $run->timers
            ->filter(static fn (WorkflowTimer $t): bool => $t->status === TimerStatus::Pending);

        foreach ($openTimers as $timer) {
            $timer->forceFill([
                'status' => TimerStatus::Cancelled,
            ])->save();

            TimerCancellation::record($run, $timer);
        }

        // Record failure row.
        $failureCategory = FailureCategory::Timeout;

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()->create([
            'workflow_run_id' => $run->id,
            'source_kind' => 'workflow_run',
            'source_id' => $run->id,
            'propagation_kind' => 'timeout',
            'failure_category' => $failureCategory->value,
            'handled' => false,
            'exception_class' => $exceptionClass,
            'message' => $message,
            'file' => '',
            'line' => 0,
            'trace_preview' => '',
        ]);

        // Close the run.
        $run->forceFill([
            'status' => RunStatus::Failed,
            'closed_reason' => 'timed_out',
            'closed_at' => $now,
            'last_progress_at' => $now,
        ])->save();

        // Record terminal history event.
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowTimedOut, [
            'failure_id' => $failure->id,
            'timeout_kind' => $timeoutKind,
            'failure_category' => $failureCategory->value,
            'message' => $message,
            'exception_class' => $exceptionClass,
            'execution_deadline_at' => $run->execution_deadline_at?->toIso8601String(),
            'run_deadline_at' => $run->run_deadline_at?->toIso8601String(),
        ], $task);

        // Mark current task completed.
        $task->forceFill([
            'status' => TaskStatus::Completed,
            'lease_expires_at' => null,
        ])->save();

        // Dispatch lifecycle events.
        LifecycleEventDispatcher::workflowFailed($run, $exceptionClass, $message);
        LifecycleEventDispatcher::failureRecorded(
            $run,
            (string) $failure->id,
            'workflow_run',
            $run->id,
            $exceptionClass,
            $message,
        );

        // Apply parent-close policy to open children.
        ParentClosePolicyEnforcer::enforce($run);

        // Notify parent workflows and project summary.
        $this->dispatchParentResumeTasks($run);

        $this->projectRun(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
    }

    private function failRun(
        WorkflowRun $run,
        WorkflowTask $task,
        Throwable $throwable,
        string $sourceKind,
        string $sourceId,
    ): void {
        if ($throwable instanceof UnresolvedWorkflowFailureException) {
            $this->blockReplayUntilFailureCanBeRestored($run, $task, $throwable);

            return;
        }

        if ($throwable instanceof ConditionWaitDefinitionMismatchException) {
            $this->blockReplayUntilCompatibleConditionWaitDefinition($run, $task, $throwable);

            return;
        }

        if ($throwable instanceof HistoryEventShapeMismatchException) {
            $this->blockReplayUntilCompatibleHistoryShape($run, $task, $throwable);

            return;
        }

        $failureCategory = FailureFactory::classify('terminal', $sourceKind, $throwable);
        $nonRetryable = FailureFactory::isNonRetryable($throwable);

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()->create(array_merge(
            FailureFactory::make($throwable),
            [
                'workflow_run_id' => $run->id,
                'source_kind' => $sourceKind,
                'source_id' => $sourceId,
                'propagation_kind' => 'terminal',
                'failure_category' => $failureCategory->value,
                'non_retryable' => $nonRetryable,
                'handled' => false,
            ],
        ));

        $run->forceFill([
            'status' => RunStatus::Failed,
            'closed_reason' => 'failed',
            'closed_at' => now(),
            'last_progress_at' => now(),
        ])->save();

        $exceptionPayload = FailureFactory::payload($throwable);

        $failedEventPayload = [
            'failure_id' => $failure->id,
            'source_kind' => $sourceKind,
            'source_id' => $sourceId,
            'failure_category' => $failureCategory->value,
            'non_retryable' => $nonRetryable,
            'exception_type' => $exceptionPayload['type'] ?? null,
            'exception_class' => $failure->exception_class,
            'message' => $failure->message,
            'exception' => $exceptionPayload,
        ];

        if ($throwable instanceof StructuralLimitExceededException) {
            try {
                $failedEventPayload['structural_limit_kind'] = $throwable->limitKind->value;
                $failedEventPayload['structural_limit_value'] = $throwable->currentValue;
                $failedEventPayload['structural_limit_configured'] = $throwable->configuredLimit;
            } catch (\Error) {
                // Restored structural-limit exceptions from older history can
                // be catchable without their readonly metadata initialized.
            }
        }

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowFailed, $failedEventPayload, $task);

        $task->forceFill([
            'status' => TaskStatus::Failed,
            'lease_expires_at' => null,
        ])->save();

        LifecycleEventDispatcher::workflowFailed($run, $failure->exception_class, $failure->message);
        LifecycleEventDispatcher::failureRecorded(
            $run,
            (string) $failure->id,
            $sourceKind,
            $sourceId,
            $failure->exception_class,
            $failure->message,
        );

        ParentClosePolicyEnforcer::enforce($run);

        $this->dispatchParentResumeTasks($run);

        $this->projectRun(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
    }

    private function ensureStepHistoryCompatible(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        string $expectedShape,
        array $expectedDetails = [],
    ): bool {
        try {
            WorkflowStepHistory::assertCompatible($run, $sequence, $expectedShape, $expectedDetails);

            return true;
        } catch (Throwable $throwable) {
            $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

            return false;
        }
    }

    /**
     * @param list<array<string, mixed>> $leafDescriptors
     */
    private function ensureParallelGroupHistoryCompatible(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        array $leafDescriptors,
    ): bool {
        try {
            WorkflowStepHistory::assertParallelGroupCompatible($run, $sequence, $leafDescriptors);

            return true;
        } catch (Throwable $throwable) {
            $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

            return false;
        }
    }

    private function ensureTypedStepHistoryRecorded(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        string $expectedShape,
    ): bool {
        try {
            WorkflowStepHistory::assertTypedHistoryRecorded($run, $sequence, $expectedShape);

            return true;
        } catch (Throwable $throwable) {
            $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

            return false;
        }
    }

    private function blockReplayUntilFailureCanBeRestored(
        WorkflowRun $run,
        WorkflowTask $task,
        UnresolvedWorkflowFailureException $throwable,
    ): void {
        $payload = is_array($task->payload) ? $task->payload : [];
        $payload['replay_blocked'] = true;
        $payload['replay_blocked_reason'] = 'failure_resolution';
        $payload['replay_blocked_exception_class'] = $throwable->originalExceptionClass();
        $payload['replay_blocked_exception_type'] = $throwable->exceptionType();
        $payload['replay_blocked_resolution_source'] = $throwable->resolutionSource();
        $payload['replay_blocked_resolution_error'] = $throwable->resolutionError();

        $task->forceFill([
            'status' => TaskStatus::Failed,
            'payload' => $payload,
            'last_error' => $throwable->getMessage(),
            'lease_expires_at' => null,
        ])->save();

        $this->projectRun(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
    }

    private function blockReplayUntilCompatibleConditionWaitDefinition(
        WorkflowRun $run,
        WorkflowTask $task,
        ConditionWaitDefinitionMismatchException $throwable,
    ): void {
        $payload = is_array($task->payload) ? $task->payload : [];
        $payload['replay_blocked'] = true;
        $payload['replay_blocked_reason'] = 'condition_wait_definition_mismatch';
        $payload['replay_blocked_workflow_sequence'] = $throwable->workflowSequence;
        $payload['replay_blocked_condition_wait_id'] = ConditionWaits::waitIdForSequence(
            $run,
            $throwable->workflowSequence,
        );
        $payload['replay_blocked_recorded_condition_key'] = $throwable->recordedConditionKey;
        $payload['replay_blocked_current_condition_key'] = $throwable->currentConditionKey;
        $payload['replay_blocked_recorded_condition_definition_fingerprint'] = $throwable->recordedConditionDefinitionFingerprint;
        $payload['replay_blocked_current_condition_definition_fingerprint'] = $throwable->currentConditionDefinitionFingerprint;

        $task->forceFill([
            'status' => TaskStatus::Failed,
            'payload' => $payload,
            'last_error' => $throwable->getMessage(),
            'lease_expires_at' => null,
        ])->save();

        $this->projectRun(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
    }

    private function blockReplayUntilCompatibleHistoryShape(
        WorkflowRun $run,
        WorkflowTask $task,
        HistoryEventShapeMismatchException $throwable,
    ): void {
        $payload = is_array($task->payload) ? $task->payload : [];
        $payload['replay_blocked'] = true;
        $payload['replay_blocked_reason'] = 'history_shape_mismatch';
        $payload['replay_blocked_workflow_sequence'] = $throwable->workflowSequence;
        $payload['replay_blocked_expected_history_shape'] = $throwable->expectedHistoryShape;
        $payload['replay_blocked_recorded_event_types'] = $throwable->recordedEventTypes;

        $task->forceFill([
            'status' => TaskStatus::Failed,
            'payload' => $payload,
            'last_error' => $throwable->getMessage(),
            'lease_expires_at' => null,
        ])->save();

        $this->projectRun(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function childFailureHandledPayload(WorkflowHistoryEvent $event): array
    {
        $payload = is_array($event->payload)
            ? $event->payload
            : [];

        $payload['source_kind'] = 'child_workflow_run';
        $payload['source_id'] = is_string($payload['child_workflow_run_id'] ?? null)
            ? $payload['child_workflow_run_id']
            : null;
        $payload['propagation_kind'] = 'child';

        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $failurePayload
     */
    private function recordFailureHandled(
        WorkflowRun $run,
        WorkflowTask $task,
        ?string $failureId,
        int $workflowSequence,
        array $failurePayload = [],
    ): void {
        if ($failureId === null || $failureId === '') {
            return;
        }

        /** @var WorkflowFailure|null $failure */
        $failure = WorkflowFailure::query()
            ->whereKey($failureId)
            ->where('workflow_run_id', $run->id)
            ->first();

        if ($failure !== null && ! $failure->handled) {
            $failure->forceFill([
                'handled' => true,
            ])->save();
        }

        $alreadyRecorded = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::FailureHandled->value)
            ->get(['payload'])
            ->contains(static function (WorkflowHistoryEvent $event) use ($failureId): bool {
                return is_array($event->payload)
                    && ($event->payload['failure_id'] ?? null) === $failureId;
            });

        if ($alreadyRecorded) {
            return;
        }

        $exceptionPayload = is_array($failurePayload['exception'] ?? null)
            ? $failurePayload['exception']
            : [];
        $exceptionType = is_string($failurePayload['exception_type'] ?? null)
            ? $failurePayload['exception_type']
            : (is_string($exceptionPayload['type'] ?? null) ? $exceptionPayload['type'] : null);
        $failureCategory = self::resolveFailureHandledCategory($failure, $failurePayload);

        WorkflowHistoryEvent::record($run, HistoryEventType::FailureHandled, array_filter([
            'failure_id' => $failureId,
            'sequence' => $workflowSequence,
            'failure_category' => $failureCategory,
            'source_kind' => $failure?->source_kind
                ?? (is_string($failurePayload['source_kind'] ?? null) ? $failurePayload['source_kind'] : null),
            'source_id' => $failure?->source_id
                ?? (is_string($failurePayload['source_id'] ?? null) ? $failurePayload['source_id'] : null),
            'propagation_kind' => $failure?->propagation_kind
                ?? (is_string(
                    $failurePayload['propagation_kind'] ?? null
                ) ? $failurePayload['propagation_kind'] : null),
            'exception_class' => $failure?->exception_class
                ?? (is_string($failurePayload['exception_class'] ?? null) ? $failurePayload['exception_class'] : null),
            'exception_type' => $exceptionType,
            'message' => $failure?->message
                ?? (is_string($failurePayload['message'] ?? null) ? $failurePayload['message'] : null),
            'handled' => true,
        ], static fn (mixed $value): bool => $value !== null), $task);
    }

    /**
     * Resolve a canonical FailureCategory value for a FailureHandled event.
     *
     * The DB row is authoritative when available (it carries an enum-cast
     * value); fall back to the failurePayload (which carries the same string
     * for child propagation), then to the catch-all Application category so
     * downstream consumers can rely on the field being present and a member
     * of the FailureCategory enum.
     *
     * @param array<string, mixed> $failurePayload
     */
    private static function resolveFailureHandledCategory(
        ?WorkflowFailure $failure,
        array $failurePayload,
    ): string {
        $modelCategory = $failure?->failure_category;
        if ($modelCategory instanceof FailureCategory) {
            return $modelCategory->value;
        }

        $payloadCategory = $failurePayload['failure_category'] ?? null;
        if (is_string($payloadCategory) && FailureCategory::tryFrom($payloadCategory) !== null) {
            return $payloadCategory;
        }

        return FailureCategory::Application->value;
    }

    private function startChildRetryIfAvailable(
        WorkflowRun $parentRun,
        int $sequence,
        WorkflowRun $failedChildRun,
    ): ?WorkflowTask {
        if (ChildRunHistory::resolvedStatus(null, $failedChildRun) !== RunStatus::Failed) {
            return null;
        }

        $parentLink = ChildRunHistory::latestLinkForSequence($parentRun, $sequence);

        if ($parentLink?->child_workflow_run_id !== $failedChildRun->id) {
            return null;
        }

        $scheduledEvent = ChildRunHistory::scheduledEventForSequence($parentRun, $sequence);
        $retryPolicy = is_array($scheduledEvent?->payload['retry_policy'] ?? null)
            ? $scheduledEvent->payload['retry_policy']
            : null;

        if ($retryPolicy === null) {
            /** @var WorkflowChildCall|null $childCall */
            $childCall = WorkflowChildCall::query()
                ->where('parent_workflow_run_id', $parentRun->id)
                ->where('sequence', $sequence)
                ->first();

            $retryPolicy = is_array($childCall?->retry_policy) ? $childCall->retry_policy : null;
        }

        if ($retryPolicy === null) {
            return null;
        }

        $attemptCount = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRun->id)
            ->where('sequence', $sequence)
            ->where('link_type', 'child_workflow')
            ->count();

        if ($attemptCount >= ChildWorkflowRetryPolicy::maxAttempts($retryPolicy)) {
            return null;
        }

        if (ChildWorkflowRetryPolicy::isNonRetryableFailure($retryPolicy, $failedChildRun)) {
            return null;
        }

        $timeoutPolicy = is_array($scheduledEvent?->payload['timeout_policy'] ?? null)
            ? $scheduledEvent->payload['timeout_policy']
            : null;
        $executionTimeoutSeconds = is_int($timeoutPolicy['execution_timeout_seconds'] ?? null)
            ? (int) $timeoutPolicy['execution_timeout_seconds']
            : null;
        $runTimeoutSeconds = is_int($timeoutPolicy['run_timeout_seconds'] ?? null)
            ? (int) $timeoutPolicy['run_timeout_seconds']
            : null;

        $now = now();
        $backoffSeconds = ChildWorkflowRetryPolicy::backoffSeconds($retryPolicy, $attemptCount);
        $availableAt = $now->copy()
            ->addSeconds($backoffSeconds);

        /** @var WorkflowInstance|null $childInstance */
        $childInstance = WorkflowInstance::query()
            ->lockForUpdate()
            ->find($failedChildRun->workflow_instance_id);

        if (! $childInstance instanceof WorkflowInstance) {
            return null;
        }

        $nextRunNumber = ((int) WorkflowRun::query()
            ->where('workflow_instance_id', $failedChildRun->workflow_instance_id)
            ->max('run_number')) + 1;

        $executionDeadlineAt = $failedChildRun->execution_deadline_at;
        if ($executionDeadlineAt === null && $executionTimeoutSeconds !== null) {
            $executionDeadlineAt = $now->copy()
                ->addSeconds($executionTimeoutSeconds);
        }

        $runDeadlineAt = $runTimeoutSeconds !== null
            ? $now->copy()
                ->addSeconds($runTimeoutSeconds)
            : null;

        /** @var WorkflowRun $retryRun */
        $retryRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $failedChildRun->workflow_instance_id,
            'run_number' => $nextRunNumber,
            'workflow_class' => $failedChildRun->workflow_class,
            'workflow_type' => $failedChildRun->workflow_type,
            'namespace' => $failedChildRun->namespace,
            'business_key' => $failedChildRun->business_key,
            'visibility_labels' => $failedChildRun->visibility_labels,
            'status' => RunStatus::Pending->value,
            'compatibility' => $failedChildRun->compatibility ?? WorkerCompatibility::current(),
            'payload_codec' => $failedChildRun->payload_codec ?? CodecRegistry::defaultCodec(),
            'arguments' => $failedChildRun->arguments,
            'run_timeout_seconds' => $runTimeoutSeconds,
            'execution_deadline_at' => $executionDeadlineAt,
            'run_deadline_at' => $runDeadlineAt,
            'connection' => $failedChildRun->connection,
            'queue' => $failedChildRun->queue,
            'started_at' => $now,
            'last_progress_at' => $now,
            'last_history_sequence' => 0,
        ]);

        $this->inheritTypedVisibilityMetadata($failedChildRun, $retryRun);

        $childInstance->forceFill([
            'current_run_id' => $retryRun->id,
            'run_count' => max((int) $childInstance->run_count, $nextRunNumber),
        ])->save();

        $childCallId = ChildRunHistory::childCallIdForSequence($parentRun, $sequence) ?? (string) Str::ulid();
        $parallelMetadataPath = ChildRunHistory::parallelGroupPathForSequence($parentRun, $sequence);
        $parallelMetadata = ParallelChildGroup::payloadForPath($parallelMetadataPath);
        $parentClosePolicy = $parentLink?->parent_close_policy ?? ParentClosePolicy::Abandon->value;

        /** @var WorkflowLink $retryLink */
        $retryLink = WorkflowLink::query()->create([
            'link_type' => 'child_workflow',
            'sequence' => $sequence,
            'parent_workflow_instance_id' => $parentRun->workflow_instance_id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $retryRun->workflow_instance_id,
            'child_workflow_run_id' => $retryRun->id,
            'is_primary_parent' => true,
            'parallel_group_path' => self::parallelGroupPath($parallelMetadata),
            'parent_close_policy' => $parentClosePolicy,
        ]);

        WorkflowHistoryEvent::record($parentRun, HistoryEventType::ChildRunStarted, array_filter(array_merge([
            'sequence' => $sequence,
            'workflow_link_id' => $retryLink->id,
            'child_call_id' => $childCallId,
            'child_workflow_instance_id' => $retryRun->workflow_instance_id,
            'child_workflow_run_id' => $retryRun->id,
            'child_workflow_class' => $retryRun->workflow_class,
            'child_workflow_type' => $retryRun->workflow_type,
            'child_run_number' => $retryRun->run_number,
            'parent_close_policy' => $parentClosePolicy,
            'retry_attempt' => $attemptCount + 1,
            'retry_of_child_workflow_run_id' => $failedChildRun->id,
            'retry_backoff_seconds' => $backoffSeconds,
            'retry_policy' => $retryPolicy,
            'timeout_policy' => $timeoutPolicy,
            'execution_timeout_seconds' => $executionTimeoutSeconds,
            'run_timeout_seconds' => $runTimeoutSeconds,
            'execution_deadline_at' => $executionDeadlineAt?->toIso8601String(),
            'run_deadline_at' => $runDeadlineAt?->toIso8601String(),
        ], $parallelMetadata), static fn (mixed $value): bool => $value !== null));

        WorkflowHistoryEvent::record($retryRun, HistoryEventType::WorkflowStarted, [
            'workflow_class' => $retryRun->workflow_class,
            'workflow_type' => $retryRun->workflow_type,
            'workflow_instance_id' => $retryRun->workflow_instance_id,
            'workflow_run_id' => $retryRun->id,
            'parent_workflow_instance_id' => $parentRun->workflow_instance_id,
            'parent_workflow_run_id' => $parentRun->id,
            'parent_sequence' => $sequence,
            'workflow_link_id' => $retryLink->id,
            'child_call_id' => $childCallId,
            'retry_attempt' => $attemptCount + 1,
            'retry_of_child_workflow_run_id' => $failedChildRun->id,
            'retry_policy' => $retryPolicy,
            'timeout_policy' => $timeoutPolicy,
            'execution_timeout_seconds' => $executionTimeoutSeconds,
            'run_timeout_seconds' => $runTimeoutSeconds,
            'execution_deadline_at' => $executionDeadlineAt?->toIso8601String(),
            'run_deadline_at' => $runDeadlineAt?->toIso8601String(),
        ]);

        /** @var WorkflowTask $retryTask */
        $retryTask = WorkflowTask::query()->create([
            'workflow_run_id' => $retryRun->id,
            'namespace' => $retryRun->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $availableAt,
            'payload' => [],
            'connection' => $retryRun->connection,
            'queue' => $retryRun->queue,
            'compatibility' => $retryRun->compatibility,
        ]);

        ChildRunHistory::markChildCallRetryStarted(
            $parentRun,
            $sequence,
            $retryRun,
            $childCallId,
            $attemptCount,
            $backoffSeconds,
            $failedChildRun,
        );

        TaskDispatcher::dispatch($retryTask);

        $this->projectRun(
            $retryRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        return $retryTask;
    }

    private function dispatchParentResumeTasks(WorkflowRun $childRun): void
    {
        $childRun->unsetRelation('historyEvents');
        $childRun->unsetRelation('failures');
        $childRun->load(['historyEvents', 'failures']);

        $parentLinks = WorkflowLink::query()
            ->where('child_workflow_run_id', $childRun->id)
            ->where('link_type', 'child_workflow')
            ->lockForUpdate()
            ->get();

        /** @var array<string, array{parent_workflow_run_id: string, parent_sequence: int|null}> $parentReferences */
        $parentReferences = [];

        foreach ($parentLinks as $parentLink) {
            if (! is_string($parentLink->parent_workflow_run_id) || $parentLink->parent_workflow_run_id === '') {
                continue;
            }

            $parentSequence = self::intValue($parentLink->sequence);

            $parentReferences[$parentLink->parent_workflow_run_id] = [
                'parent_workflow_run_id' => $parentLink->parent_workflow_run_id,
                'parent_sequence' => $parentSequence
                    ?? ($parentReferences[$parentLink->parent_workflow_run_id]['parent_sequence'] ?? null),
            ];
        }

        if ($parentReferences === []) {
            $parentReference = ChildRunHistory::parentReferenceForRun($childRun);

            if ($parentReference !== null) {
                $parentReferences[$parentReference['parent_workflow_run_id']] = $parentReference;
            }
        }

        foreach ($parentReferences as $parentReference) {
            /** @var WorkflowRun|null $parentRun */
            $parentRun = WorkflowRun::query()
                ->lockForUpdate()
                ->find($parentReference['parent_workflow_run_id']);

            if ($parentRun === null || in_array($parentRun->status, [
                RunStatus::Completed,
                RunStatus::Failed,
                RunStatus::Cancelled,
                RunStatus::Terminated,
            ], true)) {
                continue;
            }

            $parentTaskPayload = [];

            if (is_int($parentReference['parent_sequence'])) {
                $parentRun->loadMissing([
                    'historyEvents',
                    'childLinks.childRun.instance.currentRun',
                    'childLinks.childRun.failures',
                    'childLinks.childRun.historyEvents',
                ]);

                if ($this->startChildRetryIfAvailable(
                    $parentRun,
                    $parentReference['parent_sequence'],
                    $childRun,
                ) !== null) {
                    $this->projectRun(
                        $parentRun->fresh([
                            'instance',
                            'tasks',
                            'activityExecutions',
                            'timers',
                            'failures',
                            'historyEvents',
                            'childLinks.childRun.instance.currentRun',
                            'childLinks.childRun.failures',
                            'childLinks.childRun.historyEvents',
                        ])
                    );

                    continue;
                }

                $parallelMetadataPath = ChildRunHistory::parallelGroupPathForSequence(
                    $parentRun,
                    $parentReference['parent_sequence'],
                );
                $childStatus = ChildRunHistory::resolvedStatus(null, $childRun);

                if (
                    $parallelMetadataPath !== []
                    && $childStatus instanceof RunStatus
                    && ! ParallelChildGroup::shouldWakeParentOnChildClosure(
                        $parentRun,
                        $parallelMetadataPath,
                        $childStatus
                    )
                ) {
                    $this->projectRun(
                        $parentRun->fresh([
                            'instance',
                            'tasks',
                            'activityExecutions',
                            'timers',
                            'failures',
                            'historyEvents',
                            'childLinks.childRun.instance.currentRun',
                            'childLinks.childRun.failures',
                            'childLinks.childRun.historyEvents',
                        ])
                    );

                    continue;
                }

                if (
                    $parallelMetadataPath !== []
                    && ! $this->recordClosedParallelChildResolutions(
                        $parentRun,
                        $parallelMetadataPath,
                        $parentReference['parent_sequence'],
                    )
                ) {
                    $this->projectRun(
                        $parentRun->fresh([
                            'instance',
                            'tasks',
                            'activityExecutions',
                            'timers',
                            'failures',
                            'historyEvents',
                            'childLinks.childRun.instance.currentRun',
                            'childLinks.childRun.failures',
                            'childLinks.childRun.historyEvents',
                        ])
                    );

                    continue;
                }

                try {
                    WorkflowStepHistory::assertCompatible(
                        $parentRun,
                        $parentReference['parent_sequence'],
                        WorkflowStepHistory::CHILD_WORKFLOW,
                    );
                    WorkflowStepHistory::assertTypedHistoryRecorded(
                        $parentRun,
                        $parentReference['parent_sequence'],
                        WorkflowStepHistory::CHILD_WORKFLOW,
                    );
                } catch (HistoryEventShapeMismatchException) {
                    $this->projectRun(
                        $parentRun->fresh([
                            'instance',
                            'tasks',
                            'activityExecutions',
                            'timers',
                            'failures',
                            'historyEvents',
                            'childLinks.childRun.instance.currentRun',
                            'childLinks.childRun.failures',
                            'childLinks.childRun.historyEvents',
                        ])
                    );

                    continue;
                }

                $resolutionEvent = $this->recordChildResolution(
                    $parentRun,
                    null,
                    $parentReference['parent_sequence'],
                    $childRun,
                );
                $parentTaskPayload = WorkflowTaskPayload::forChildResolution($resolutionEvent);
            }

            $hasOpenWorkflowTask = WorkflowTask::query()
                ->where('workflow_run_id', $parentRun->id)
                ->where('task_type', TaskType::Workflow->value)
                ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
                ->exists();

            if ($hasOpenWorkflowTask) {
                continue;
            }

            /** @var WorkflowTask $parentTask */
            $parentTask = WorkflowTask::query()->create([
                'workflow_run_id' => $parentRun->id,
                'namespace' => $parentRun->namespace,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now(),
                'payload' => $parentTaskPayload,
                'connection' => $parentRun->connection,
                'queue' => $parentRun->queue,
                'compatibility' => $parentRun->compatibility,
            ]);

            TaskDispatcher::dispatch($parentTask);

            $this->projectRun(
                $parentRun->fresh([
                    'instance',
                    'tasks',
                    'activityExecutions',
                    'timers',
                    'failures',
                    'historyEvents',
                    'childLinks.childRun.instance.currentRun',
                    'childLinks.childRun.failures',
                ])
            );
        }
    }

    /**
     * @param list<array{
     *     parallel_group_id: string,
     *     parallel_group_kind: string,
     *     parallel_group_base_sequence: int,
     *     parallel_group_size: int,
     *     parallel_group_index: int
     * }> $parallelMetadataPath
     */
    private function recordClosedParallelChildResolutions(
        WorkflowRun $parentRun,
        array $parallelMetadataPath,
        int $closingSequence,
    ): bool {
        foreach ($parallelMetadataPath as $metadata) {
            foreach (ParallelChildGroup::sequences($metadata) as $sequence) {
                if ($sequence === $closingSequence) {
                    continue;
                }

                if (ChildRunHistory::resolutionEventForSequence($parentRun, $sequence) !== null) {
                    continue;
                }

                $childRun = ChildRunHistory::childRunForSequence($parentRun, $sequence);

                if (! $childRun instanceof WorkflowRun) {
                    continue;
                }

                $childStatus = ChildRunHistory::resolvedStatus(null, $childRun);

                if (! $childStatus instanceof RunStatus || in_array($childStatus, [
                    RunStatus::Pending,
                    RunStatus::Running,
                    RunStatus::Waiting,
                ], true)) {
                    continue;
                }

                try {
                    WorkflowStepHistory::assertCompatible(
                        $parentRun,
                        $sequence,
                        WorkflowStepHistory::CHILD_WORKFLOW,
                    );
                    WorkflowStepHistory::assertTypedHistoryRecorded(
                        $parentRun,
                        $sequence,
                        WorkflowStepHistory::CHILD_WORKFLOW,
                    );
                } catch (HistoryEventShapeMismatchException) {
                    return false;
                }

                $this->recordChildResolution($parentRun, null, $sequence, $childRun);
                $parentRun->unsetRelation('historyEvents');
                $parentRun->load('historyEvents');
            }
        }

        return true;
    }

    private function activityResult(WorkflowHistoryEvent $event, ?WorkflowRun $run = null): mixed
    {
        $serialized = ExternalPayloads::payloadBlob(
            $event->payload['result'] ?? null,
            $this->stringValue($event->payload['payload_codec'] ?? null) ?? $this->stringValue($run?->payload_codec ?? null),
            is_string($run?->namespace) ? $run->namespace : null,
        );

        if ($serialized === null) {
            return null;
        }

        $codec = $this->stringValue($event->payload['payload_codec'] ?? null);

        if ($codec !== null) {
            return Serializer::unserializeWithCodec($codec, $serialized);
        }

        return $this->unserializePayloadWithRun($serialized, $run);
    }

    private function sideEffectResult(WorkflowHistoryEvent $event, ?WorkflowRun $run = null): mixed
    {
        $serialized = ExternalPayloads::payloadBlob(
            $event->payload['result'] ?? null,
            $this->stringValue($event->payload['payload_codec'] ?? null) ?? $this->stringValue($run?->payload_codec ?? null),
            is_string($run?->namespace) ? $run->namespace : null,
        );

        if ($serialized === null) {
            return null;
        }

        return $this->unserializePayloadWithRun($serialized, $run);
    }

    /**
     * Decode a payload bytes string using the run's pinned codec, falling
     * back to the legacy codec-blind sniffer when no run codec is available
     * (history events written before payload_codec was populated).
     */
    private function unserializePayloadWithRun(string $serialized, ?WorkflowRun $run): mixed
    {
        $serialized = ExternalPayloads::resolveStoredPayload(
            $serialized,
            is_string($run?->payload_codec) ? $run->payload_codec : null,
            is_string($run?->namespace) ? $run->namespace : null,
        );

        if ($run !== null && is_string($run->payload_codec) && $run->payload_codec !== '') {
            return Serializer::unserializeWithCodec($run->payload_codec, $serialized);
        }

        return Serializer::unserialize($serialized);
    }

    private function activityCompletionEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => in_array(
                $event->event_type,
                [
                    HistoryEventType::ActivityCompleted,
                    HistoryEventType::ActivityFailed,
                    HistoryEventType::ActivityCancelled,
                    HistoryEventType::ActivityTimedOut,
                ],
                true,
            ) && ($event->payload['sequence'] ?? null) === $sequence
        );

        return $event;
    }

    private function activityOpenEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => in_array(
                $event->event_type,
                [
                    HistoryEventType::ActivityScheduled,
                    HistoryEventType::ActivityStarted,
                    HistoryEventType::ActivityHeartbeatRecorded,
                    HistoryEventType::ActivityRetryScheduled,
                ],
                true,
            ) && ($event->payload['sequence'] ?? null) === $sequence
        );

        return $event;
    }

    private function activityHistoryEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        return $this->activityCompletionEvent($run, $sequence)
            ?? $this->activityOpenEvent($run, $sequence);
    }

    private function sideEffectEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::SideEffectRecorded
                && ($event->payload['sequence'] ?? null) === $sequence
        );

        return $event;
    }

    private function versionMarkerEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::VersionMarkerRecorded
                && ($event->payload['sequence'] ?? null) === $sequence
        );

        return $event;
    }

    private function recordSideEffect(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        SideEffectCall $sideEffectCall,
    ): WorkflowHistoryEvent {
        $result = ($sideEffectCall->callback)();
        $payloadCodec = is_string($run->payload_codec) && $run->payload_codec !== ''
            ? $run->payload_codec
            : CodecRegistry::defaultCodec();
        $namespace = is_string($run->namespace) ? $run->namespace : null;
        $storedResult = ExternalPayloads::externalizeForNamespace(
            Serializer::serializeWithCodec($payloadCodec, $result),
            $payloadCodec,
            $namespace,
        );

        $event = WorkflowHistoryEvent::record(
            $run,
            HistoryEventType::SideEffectRecorded,
            [
                'sequence' => $sequence,
                'result' => ExternalPayloads::historyValue($storedResult, $payloadCodec, $namespace),
            ],
            $task,
        );

        $run->historyEvents->push($event);

        return $event;
    }

    private static function payloadCodecForUpdate(WorkflowUpdate $update, WorkflowRun $run): string
    {
        if (is_string($update->payload_codec) && $update->payload_codec !== '') {
            return $update->payload_codec;
        }

        if (is_string($run->payload_codec) && $run->payload_codec !== '') {
            return $run->payload_codec;
        }

        return CodecRegistry::defaultCodec();
    }

    private function recordVersionMarker(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        VersionCall $versionCall,
        int $version,
    ): WorkflowHistoryEvent {
        $event = WorkflowHistoryEvent::record(
            $run,
            HistoryEventType::VersionMarkerRecorded,
            [
                'sequence' => $sequence,
                'change_id' => $versionCall->changeId,
                'version' => $version,
                'min_supported' => $versionCall->minSupported,
                'max_supported' => $versionCall->maxSupported,
            ],
            $task,
        );

        $run->historyEvents->push($event);

        return $event;
    }

    private function searchAttributesUpsertedEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::SearchAttributesUpserted
                && ($event->payload['sequence'] ?? null) === $sequence
        );

        return $event;
    }

    private function recordSearchAttributesUpserted(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        UpsertSearchAttributesCall $call,
    ): WorkflowHistoryEvent {
        $existing = $run->typedSearchAttributes();
        $merged = $existing;

        foreach ($call->attributes as $key => $value) {
            if ($value === null) {
                unset($merged[$key]);
            } else {
                $merged[$key] = $value;
            }
        }

        ksort($merged);
        $serializedSearchAttributes = json_encode($merged, JSON_THROW_ON_ERROR);
        $this->logApproachingLimit(
            StructuralLimits::warnApproachingSearchAttributeSize($serializedSearchAttributes),
            $run,
            [
                'payload_site' => 'search_attributes',
            ],
        );
        StructuralLimits::guardSearchAttributeSize($serializedSearchAttributes);

        $searchAttributeService = app(SearchAttributeUpsertService::class);
        $searchAttributeService->upsert($run, $call, $sequence);
        $run->unsetRelation('searchAttributes');

        $event = WorkflowHistoryEvent::record(
            $run,
            HistoryEventType::SearchAttributesUpserted,
            [
                'sequence' => $sequence,
                'attributes' => $call->attributes,
                'merged' => $merged,
            ],
            $task,
        );

        $run->historyEvents->push($event);

        return $event;
    }

    private function memoUpsertedEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::MemoUpserted
                && ($event->payload['sequence'] ?? null) === $sequence
        );

        return $event;
    }

    private function recordMemoUpserted(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        UpsertMemoCall $call,
    ): WorkflowHistoryEvent {
        $existing = $run->typedMemos();

        $merged = $existing;

        foreach ($call->entries as $key => $value) {
            if ($value === null) {
                unset($merged[$key]);
            } else {
                $merged[$key] = $value;
            }
        }

        ksort($merged);

        $serializedMemo = json_encode($merged, JSON_THROW_ON_ERROR);
        $this->logApproachingLimit(
            StructuralLimits::warnApproachingMemoSize($serializedMemo),
            $run,
            [
                'payload_site' => 'memo',
            ],
        );
        StructuralLimits::guardMemoSize($serializedMemo);

        $memoService = app(MemoUpsertService::class);
        $memoService->upsert($run, new UpsertMemosCall($call->entries), $sequence);
        $run->unsetRelation('memos');

        $event = WorkflowHistoryEvent::record(
            $run,
            HistoryEventType::MemoUpserted,
            [
                'sequence' => $sequence,
                'entries' => $call->entries,
                'merged' => $merged,
            ],
            $task,
        );

        $run->historyEvents->push($event);

        return $event;
    }

    private function timerFiredEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::TimerFired
                && ! self::isInternalTimeoutTimerKind($event->payload['timer_kind'] ?? null)
                && ($event->payload['sequence'] ?? null) === $sequence
        );

        return $event;
    }

    private function timerScheduledEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::TimerScheduled
                && ! self::isInternalTimeoutTimerKind($event->payload['timer_kind'] ?? null)
                && ($event->payload['sequence'] ?? null) === $sequence
        );

        return $event;
    }

    private function conditionTimeoutFiredEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::TimerFired
                && ($event->payload['timer_kind'] ?? null) === 'condition_timeout'
                && ($event->payload['sequence'] ?? null) === $sequence
        );

        return $event;
    }

    private function conditionTimeoutScheduledEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::TimerScheduled
                && ($event->payload['timer_kind'] ?? null) === 'condition_timeout'
                && ($event->payload['sequence'] ?? null) === $sequence
        );

        return $event;
    }

    private function signalTimeoutFiredEvent(WorkflowRun $run, int $sequence, string $signalName): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::TimerFired
                && ($event->payload['timer_kind'] ?? null) === 'signal_timeout'
                && ($event->payload['sequence'] ?? null) === $sequence
                && ($event->payload['signal_name'] ?? null) === $signalName
        );

        return $event;
    }

    private function signalTimeoutScheduledEvent(
        WorkflowRun $run,
        int $sequence,
        string $signalName
    ): ?WorkflowHistoryEvent {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::TimerScheduled
                && ($event->payload['timer_kind'] ?? null) === 'signal_timeout'
                && ($event->payload['sequence'] ?? null) === $sequence
                && ($event->payload['signal_name'] ?? null) === $signalName
        );

        return $event;
    }

    private function serviceOperationEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        $events = $run->historyEvents->filter(
            static fn (WorkflowHistoryEvent $event): bool => in_array(
                $event->event_type,
                [
                    HistoryEventType::ServiceCallStarted,
                    HistoryEventType::ServiceCallCompleted,
                    HistoryEventType::ServiceCallFailed,
                    HistoryEventType::ServiceCallCancelled,
                ],
                true,
            ) && ($event->payload['sequence'] ?? null) === $sequence
        );

        /** @var WorkflowHistoryEvent|null $terminal */
        $terminal = $events->first(static fn (WorkflowHistoryEvent $event): bool => in_array(
            $event->event_type,
            [
                HistoryEventType::ServiceCallCompleted,
                HistoryEventType::ServiceCallFailed,
                HistoryEventType::ServiceCallCancelled,
            ],
            true,
        ));

        /** @var WorkflowHistoryEvent|null $started */
        $started = $events->first();

        return $terminal ?? $started;
    }

    private function recordServiceOperationEvent(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        ServiceOperationCall $call,
    ): WorkflowHistoryEvent {
        $payloadCodec = $call->options?->payloadCodec
            ?? (is_string($run->payload_codec) && $run->payload_codec !== ''
                ? $run->payload_codec
                : CodecRegistry::defaultCodec());
        $namespace = is_string($run->namespace) ? $run->namespace : null;
        $serializedRequest = Serializer::serializeWithCodec($payloadCodec, $call->requestPayload);
        $storedRequest = ExternalPayloads::externalizeForNamespace($serializedRequest, $payloadCodec, $namespace);
        $surface = $this->serviceControlPlane()->execute(
            $call->endpointName,
            $call->serviceName,
            $call->operationName,
            $this->serviceOperationControlPlaneOptions($run, $sequence, $call, $payloadCodec, $serializedRequest),
        );
        $eventType = self::serviceOperationEventTypeForSurface($surface);

        $event = WorkflowHistoryEvent::record(
            $run,
            $eventType,
            $this->serviceOperationEventPayload(
                $run,
                $sequence,
                $call,
                $surface,
                $storedRequest,
                $payloadCodec,
            ),
            $task,
        );
        $run->historyEvents->push($event);

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceOperationControlPlaneOptions(
        WorkflowRun $run,
        int $sequence,
        ServiceOperationCall $call,
        string $payloadCodec,
        string $serializedRequest,
    ): array {
        $options = $call->options?->toCommandOptions() ?? [];
        $metadata = is_array($options['metadata'] ?? null) ? $options['metadata'] : [];
        $metadata = [
            'caller_sdk_language' => 'workflow-php',
            'caller_workflow_instance_id' => $run->workflow_instance_id,
            'caller_workflow_run_id' => $run->id,
            'workflow_sequence' => $sequence,
        ] + $metadata;

        return array_filter([
            'namespace' => self::nonEmptyString($options['namespace'] ?? null) ?? $run->namespace,
            'arguments' => $call->requestPayload,
            'payload_blob' => $serializedRequest,
            'payload_codec' => $payloadCodec,
            'service_call_id' => self::nonEmptyString($options['service_call_id'] ?? null),
            'idempotency_key' => self::nonEmptyString($options['idempotency_key'] ?? null)
                ?? $this->defaultServiceOperationIdempotencyKey($run, $sequence),
            'mode_override' => self::nonEmptyString($options['mode_override'] ?? null),
            'wait_for' => self::nonEmptyString($options['wait_for'] ?? null),
            'wait_timeout_seconds' => is_int($options['wait_timeout_seconds'] ?? null)
                ? $options['wait_timeout_seconds']
                : null,
            'caller_namespace' => self::nonEmptyString($options['caller_namespace'] ?? null) ?? $run->namespace,
            'caller_workflow_instance_id' => $run->workflow_instance_id,
            'caller_workflow_run_id' => $run->id,
            'target_workflow_instance_id' => self::nonEmptyString($options['target_workflow_instance_id'] ?? null),
            'target_workflow_run_id' => self::nonEmptyString($options['target_workflow_run_id'] ?? null),
            'connection' => self::nonEmptyString($options['connection'] ?? null),
            'queue' => self::nonEmptyString($options['queue'] ?? null),
            'business_key' => self::nonEmptyString($options['business_key'] ?? null),
            'labels' => is_array($options['labels'] ?? null) ? $options['labels'] : null,
            'memo' => is_array($options['memo'] ?? null) ? $options['memo'] : null,
            'search_attributes' => is_array($options['search_attributes'] ?? null)
                ? $options['search_attributes']
                : null,
            'duplicate_start_policy' => self::nonEmptyString($options['duplicate_start_policy'] ?? null),
            'metadata' => $metadata,
            'request_payload_reference' => self::nonEmptyString($options['request_payload_reference'] ?? null),
            'principal_subject' => self::nonEmptyString($options['principal_subject'] ?? null),
            'principal_method' => self::nonEmptyString($options['principal_method'] ?? null),
            'principal_roles' => is_array($options['principal_roles'] ?? null) ? $options['principal_roles'] : null,
            'principal_tenant' => self::nonEmptyString($options['principal_tenant'] ?? null),
            'principal_claims' => is_array($options['principal_claims'] ?? null) ? $options['principal_claims'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function defaultServiceOperationIdempotencyKey(WorkflowRun $run, int $sequence): string
    {
        return implode(':', [
            'workflow-service-operation',
            $run->workflow_instance_id,
            $run->id,
            (string) $sequence,
        ]);
    }

    /**
     * @param array<string, mixed> $surface
     */
    private static function serviceOperationEventTypeForSurface(array $surface): HistoryEventType
    {
        $status = self::nonEmptyString($surface['status'] ?? null);

        return match ($status) {
            'completed' => HistoryEventType::ServiceCallCompleted,
            'failed' => HistoryEventType::ServiceCallFailed,
            'cancelled' => HistoryEventType::ServiceCallCancelled,
            default => ($surface['accepted'] ?? null) === false
                ? HistoryEventType::ServiceCallFailed
                : HistoryEventType::ServiceCallStarted,
        };
    }

    private static function serviceOperationStartedEventIsVisible(
        WorkflowHistoryEvent $event,
        ServiceOperationCall $call,
    ): bool
    {
        if ($event->event_type !== HistoryEventType::ServiceCallStarted) {
            return true;
        }

        $surface = is_array($event->payload['service_call'] ?? null)
            ? $event->payload['service_call']
            : [];

        return self::serviceOperationResumesOnAdmission($call)
            || self::nonEmptyString($event->payload['wait_for'] ?? null) === 'accepted'
            || self::nonEmptyString($event->payload['operation_mode'] ?? null) === 'async'
            || self::nonEmptyString($surface['wait_for'] ?? null) === 'accepted'
            || self::nonEmptyString($surface['operation_mode'] ?? null) === 'async';
    }

    private static function serviceOperationResumesOnAdmission(ServiceOperationCall $call): bool
    {
        return $call->options?->shouldResumeOnAdmission() ?? false;
    }

    /**
     * @param array<string, mixed> $surface
     * @return array<string, mixed>
     */
    private function serviceOperationEventPayload(
        WorkflowRun $run,
        int $sequence,
        ServiceOperationCall $call,
        array $surface,
        ?string $storedRequest,
        string $payloadCodec,
    ): array {
        $metadata = $call->options?->metadata ?? [];
        $responsePayload = $surface['response_payload'] ?? $surface['result'] ?? null;
        $payload = [
            'sequence' => $sequence,
            'service_call_id' => self::nonEmptyString($surface['service_call_id'] ?? null)
                ?? self::nonEmptyString($surface['id'] ?? null),
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'caller_workflow_instance_id' => $run->workflow_instance_id,
            'caller_workflow_run_id' => $run->id,
            'caller_sdk_language' => 'workflow-php',
            'endpoint_name' => $call->endpointName,
            'service_name' => $call->serviceName,
            'operation_name' => $call->operationName,
            'service_sdk_language' => self::metadataString($surface, 'service_sdk_language')
                ?? self::metadataString($metadata, 'service_sdk_language'),
            'request_payload' => ExternalPayloads::historyValue(
                $storedRequest,
                $payloadCodec,
                is_string($run->namespace) ? $run->namespace : null,
            ),
            'response_payload' => $responsePayload,
            'payload_codec' => $payloadCodec,
            'operation_mode' => self::nonEmptyString($surface['operation_mode'] ?? null)
                ?? $call->options?->modeOverride,
            'wait_for' => self::nonEmptyString($surface['wait_for'] ?? null)
                ?? $call->options?->waitFor,
            'status' => self::nonEmptyString($surface['status'] ?? null),
            'outcome' => self::nonEmptyString($surface['outcome'] ?? null),
            'resolved_binding_kind' => self::nonEmptyString($surface['resolved_binding_kind'] ?? null),
            'resolved_target_reference' => self::nonEmptyString($surface['resolved_target_reference'] ?? null),
            'linked_workflow_instance_id' => self::nonEmptyString($surface['linked_workflow_instance_id'] ?? null),
            'linked_workflow_run_id' => self::nonEmptyString($surface['linked_workflow_run_id'] ?? null),
            'linked_workflow_update_id' => self::nonEmptyString($surface['linked_workflow_update_id'] ?? null),
            'service_call' => $surface,
            'response_or_failure_surface' => $surface,
        ];

        if (($surface['accepted'] ?? null) === false || ($payload['status'] ?? null) === 'failed') {
            $failure = self::serviceOperationFailurePayload($surface);
            $payload += [
                'exception_type' => $failure['type'] ?? null,
                'exception_class' => $failure['class'] ?? RuntimeException::class,
                'message' => $failure['message'] ?? 'Service operation failed.',
                'code' => $failure['code'] ?? 0,
                'exception' => $failure,
            ];
        }

        if (($payload['status'] ?? null) === 'cancelled') {
            $message = self::nonEmptyString($surface['failure_message'] ?? null) ?? 'Service operation cancelled.';
            $payload += [
                'exception_type' => 'service_call_cancelled',
                'exception_class' => RuntimeException::class,
                'message' => $message,
                'code' => 0,
                'exception' => [
                    'class' => RuntimeException::class,
                    'type' => 'service_call_cancelled',
                    'message' => $message,
                    'code' => 0,
                ],
            ];
        }

        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $surface
     * @return array<string, mixed>
     */
    private static function serviceOperationFailurePayload(array $surface): array
    {
        $type = self::metadataString($surface, 'caller_observed_error_type')
            ?? self::metadataString($surface, 'service_error_type')
            ?? self::metadataString($surface['outcome_metadata'] ?? null, 'caller_observed_error_type')
            ?? self::metadataString($surface['outcome_metadata'] ?? null, 'service_error_type')
            ?? self::nonEmptyString($surface['failure_type'] ?? null)
            ?? self::nonEmptyString($surface['error_type'] ?? null)
            ?? self::nonEmptyString($surface['outcome_reason'] ?? null)
            ?? self::nonEmptyString($surface['reason'] ?? null)
            ?? 'service_operation_failed';
        $message = self::metadataString($surface, 'typed_error_message')
            ?? self::metadataString($surface['outcome_metadata'] ?? null, 'typed_error_message')
            ?? self::nonEmptyString($surface['outcome_message'] ?? null)
            ?? self::nonEmptyString($surface['failure_message'] ?? null)
            ?? self::nonEmptyString($surface['message'] ?? null)
            ?? self::nonEmptyString($surface['reason'] ?? null)
            ?? 'Service operation failed.';

        return [
            'class' => RuntimeException::class,
            'type' => $type,
            'message' => $message,
            'code' => 0,
        ];
    }

    private function serviceOperationResult(WorkflowHistoryEvent $event, WorkflowRun $run): ServiceOperationResult
    {
        $surface = is_array($event->payload['service_call'] ?? null)
            ? $event->payload['service_call']
            : [];
        $surface += array_filter([
            'service_call_id' => self::nonEmptyString($event->payload['service_call_id'] ?? null),
            'status' => self::nonEmptyString($event->payload['status'] ?? null),
            'outcome' => self::nonEmptyString($event->payload['outcome'] ?? null),
            'endpoint_name' => self::nonEmptyString($event->payload['endpoint_name'] ?? null),
            'service_name' => self::nonEmptyString($event->payload['service_name'] ?? null),
            'operation_name' => self::nonEmptyString($event->payload['operation_name'] ?? null),
            'operation_mode' => self::nonEmptyString($event->payload['operation_mode'] ?? null),
            'wait_for' => self::nonEmptyString($event->payload['wait_for'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);

        return ServiceOperationResult::fromSurface(
            $surface,
            $this->serviceOperationResponsePayload($event, $run),
        );
    }

    private function serviceOperationResponsePayload(WorkflowHistoryEvent $event, WorkflowRun $run): mixed
    {
        if (! array_key_exists('response_payload', $event->payload)) {
            return null;
        }

        $payload = $event->payload['response_payload'];

        if (! is_string($payload) && ! self::isPayloadEnvelope($payload)) {
            return $payload;
        }

        $codec = self::nonEmptyString($event->payload['payload_codec'] ?? null)
            ?? (is_string($run->payload_codec) && $run->payload_codec !== ''
                ? $run->payload_codec
                : CodecRegistry::defaultCodec());
        $serialized = ExternalPayloads::payloadBlob(
            $payload,
            $codec,
            is_string($run->namespace) ? $run->namespace : null,
        );

        if ($serialized === null) {
            return null;
        }

        try {
            return Serializer::unserializeWithCodec($codec, $serialized);
        } catch (Throwable) {
            return $payload;
        }
    }

    private static function isPayloadEnvelope(mixed $payload): bool
    {
        return is_array($payload)
            && (
                (isset($payload['blob']) && is_string($payload['blob']))
                || (isset($payload['external_storage']) && is_array($payload['external_storage']))
            );
    }

    private function serviceOperationException(WorkflowHistoryEvent $event): Throwable
    {
        $payload = is_array($event->payload['exception'] ?? null) ? $event->payload['exception'] : [];
        $exceptionClass = self::nonEmptyString($event->payload['exception_class'] ?? null) ?? RuntimeException::class;
        $message = self::nonEmptyString($event->payload['message'] ?? null) ?? 'Service operation failed.';
        $code = self::intValue($event->payload['code'] ?? null) ?? 0;

        if (! is_string($payload['type'] ?? null) && is_string($event->payload['exception_type'] ?? null)) {
            $payload['type'] = $event->payload['exception_type'];
        }

        if (! is_string($payload['class'] ?? null)) {
            $payload['class'] = $exceptionClass;
        }

        if (! is_string($payload['message'] ?? null)) {
            $payload['message'] = $message;
        }

        if (! is_int($payload['code'] ?? null)) {
            $payload['code'] = $code;
        }

        try {
            return FailureFactory::restoreForReplay($payload, $exceptionClass, $message, $code);
        } catch (UnresolvedWorkflowFailureException) {
            return FailureFactory::restoreExternalWorkerFailure($payload, $exceptionClass, $message, $code);
        }
    }

    private function serviceControlPlane(): ServiceControlPlane
    {
        /** @var ServiceControlPlane $controlPlane */
        $controlPlane = app(ServiceControlPlane::class);

        return $controlPlane;
    }

    private static function metadataString(mixed $container, string $key): ?string
    {
        if (! is_array($container)) {
            return null;
        }

        $value = $container[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function activityException(
        ?WorkflowHistoryEvent $event = null,
        ?ActivityExecution $execution = null,
        ?WorkflowRun $run = null,
    ): Throwable {
        $payload = is_array($event?->payload['exception'] ?? null)
            ? $event->payload['exception']
            : (is_string($execution?->exception)
                ? $this->unserializePayloadWithRun($execution->exception, $run)
                : []);

        if (! is_array($payload) && $event !== null && $run !== null) {
            /** @var WorkflowFailure|null $failure */
            $failure = ($event->payload['failure_id'] ?? null) === null
                ? null
                : $run->failures->firstWhere('id', $event->payload['failure_id']);

            $payload = $failure === null
                ? []
                : [
                    'class' => $failure->exception_class,
                    'message' => $failure->message,
                ];
        }

        if (is_array($payload) && ! is_string($payload['type'] ?? null) && is_string(
            $event?->payload['exception_type'] ?? null
        )) {
            $payload['type'] = $event->payload['exception_type'];
        }

        $fallbackClass = is_string($event?->payload['exception_class'] ?? null)
            ? $event->payload['exception_class']
            : RuntimeException::class;
        $fallbackMessage = is_string($event?->payload['message'] ?? null)
            ? $event->payload['message']
            : match ($event?->event_type) {
                HistoryEventType::ActivityCancelled => 'Activity cancelled',
                HistoryEventType::ActivityTimedOut => 'Activity timed out',
                default => 'Activity failed',
            };
        $fallbackCode = is_int($event?->payload['code'] ?? null)
            ? $event->payload['code']
            : 0;

        return FailureFactory::restoreForReplay($payload, $fallbackClass, $fallbackMessage, $fallbackCode);
    }

    private function applyRecordedUpdates(
        WorkflowRun $run,
        \Workflow\V2\Workflow $workflow,
        int $sequence,
        ?WorkflowTask $task = null,
    ): bool {
        $events = $run->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::UpdateApplied
                    && ($event->payload['sequence'] ?? null) === $sequence
            )
            ->sortBy('sequence');

        $workflow->setCommandDispatchEnabled(false);

        try {
            foreach ($events as $event) {
                if (! $event instanceof WorkflowHistoryEvent) {
                    continue;
                }

                /** @var WorkflowCommand|null $command */
                $command = $event->workflow_command_id === null
                    ? null
                    : $run->commands->firstWhere('id', $event->workflow_command_id);

                $target = $event->payload['update_name'] ?? ($command === null
                    ? null
                    : WorkflowPayloadDecoder::commandTargetName($command, [
                        'workflow_id' => $run->workflow_instance_id,
                        'run_id' => $run->id,
                        'event_id' => $event->id,
                    ]));

                if (! is_string($target) || $target === '') {
                    throw new LogicException(sprintf(
                        'Workflow update event [%s] is missing an update method name.',
                        $event->id,
                    ));
                }

                $method = WorkflowDefinition::resolveUpdateTarget($workflow::class, $target)['method'] ?? $target;

                $serializedArguments = $event->payload['arguments'] ?? null;
                $arguments = is_string($serializedArguments)
                    ? WorkflowPayloadDecoder::unserializeWithRun($serializedArguments, $run, [
                        'workflow_id' => $run->workflow_instance_id,
                        'run_id' => $run->id,
                        'event_id' => $event->id,
                        'update_name' => $target,
                        'workflow_command_id' => $command?->id,
                    ])
                    : ($command === null
                        ? null
                        : WorkflowPayloadDecoder::commandArguments($command, [
                            'workflow_id' => $run->workflow_instance_id,
                            'run_id' => $run->id,
                            'event_id' => $event->id,
                            'update_name' => $target,
                        ]));

                $parameters = $workflow->resolveMethodDependencies(
                    is_array($arguments) ? array_values($arguments) : [],
                    new ReflectionMethod($workflow, $method),
                );

                $workflow->{$method}(...$parameters);
            }
        } finally {
            $workflow->setCommandDispatchEnabled(true);
        }

        if ($task instanceof WorkflowTask && ! $this->applyPendingUpdates($run, $task, $workflow, $sequence)) {
            return false;
        }

        return true;
    }

    private function applyPendingUpdates(
        WorkflowRun $run,
        WorkflowTask $task,
        \Workflow\V2\Workflow $workflow,
        int $sequence,
    ): bool {
        $run->loadMissing(['updates', 'commands']);

        $updates = $run->updates
            ->filter(
                static fn (WorkflowUpdate $update): bool => $update->status === UpdateStatus::Accepted
                    && ($update->workflow_sequence === null || (int) $update->workflow_sequence === $sequence)
            )
            ->sort(static function (WorkflowUpdate $left, WorkflowUpdate $right): int {
                $leftSequence = $left->command_sequence ?? PHP_INT_MAX;
                $rightSequence = $right->command_sequence ?? PHP_INT_MAX;

                if ($leftSequence !== $rightSequence) {
                    return $leftSequence <=> $rightSequence;
                }

                $leftAcceptedAt = $left->accepted_at?->getTimestampMs() ?? PHP_INT_MAX;
                $rightAcceptedAt = $right->accepted_at?->getTimestampMs() ?? PHP_INT_MAX;

                if ($leftAcceptedAt !== $rightAcceptedAt) {
                    return $leftAcceptedAt <=> $rightAcceptedAt;
                }

                return $left->id <=> $right->id;
            });

        foreach ($updates as $update) {
            if (! $update instanceof WorkflowUpdate) {
                continue;
            }

            /** @var WorkflowCommand|null $command */
            $command = $update->workflow_command_id === null
                ? null
                : $run->commands->firstWhere('id', $update->workflow_command_id);

            try {
                $target = $update->update_name;
                $resolvedTarget = WorkflowDefinition::resolveUpdateTarget($workflow::class, $target);

                if ($resolvedTarget === null) {
                    throw new LogicException(sprintf(
                        'Workflow update [%s] is not declared on workflow [%s].',
                        $target,
                        $workflow::class,
                    ));
                }

                $parameters = $workflow->resolveMethodDependencies(
                    WorkflowPayloadDecoder::updateArguments($update, [
                        'workflow_id' => $run->workflow_instance_id,
                        'run_id' => $run->id,
                        'update_name' => $target,
                    ]),
                    new ReflectionMethod($workflow, $resolvedTarget['method']),
                );
                $result = $workflow->{$resolvedTarget['method']}(...$parameters);

                $appliedEvent = WorkflowHistoryEvent::record($run, HistoryEventType::UpdateApplied, [
                    'workflow_command_id' => $command?->id,
                    'update_id' => $update->id,
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'workflow_run_id' => $run->id,
                    'update_name' => $target,
                    'arguments' => $update->arguments,
                    'sequence' => $sequence,
                ], $task, $command);
                $run->historyEvents->push($appliedEvent);

                $updateCodec = self::payloadCodecForUpdate($update, $run);
                $namespace = is_string($run->namespace) ? $run->namespace : null;
                $serializedResult = Serializer::serializeWithCodec($updateCodec, $result);
                $storedResult = ExternalPayloads::externalizeForNamespace(
                    $serializedResult,
                    $updateCodec,
                    $namespace,
                );
                $completedEvent = WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted, [
                    'workflow_command_id' => $command?->id,
                    'update_id' => $update->id,
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'workflow_run_id' => $run->id,
                    'update_name' => $target,
                    'sequence' => $sequence,
                    'result' => ExternalPayloads::historyValue($storedResult, $updateCodec, $namespace),
                ], $task, $command);
                $run->historyEvents->push($completedEvent);

                $update->forceFill([
                    'workflow_sequence' => $sequence,
                    'status' => UpdateStatus::Completed->value,
                    'outcome' => CommandOutcome::UpdateCompleted->value,
                    'result' => $storedResult,
                    'applied_at' => now(),
                    'closed_at' => now(),
                ])->save();

                if ($command instanceof WorkflowCommand) {
                    $command->forceFill([
                        'outcome' => CommandOutcome::UpdateCompleted->value,
                        'applied_at' => $update->applied_at,
                    ])->save();

                    if ($command->message_sequence !== null) {
                        MessageStreamCursor::advanceCursor($run, (int) $command->message_sequence, $task);
                    }
                }
            } catch (Throwable $throwable) {
                $exceptionPayload = FailureFactory::payload($throwable);
                $updateFailureCategory = FailureFactory::classify('update', 'workflow_command', $throwable);
                $updateNonRetryable = FailureFactory::isNonRetryable($throwable);

                /** @var WorkflowFailure $failure */
                $failure = WorkflowFailure::query()->create(array_merge(
                    FailureFactory::make($throwable),
                    [
                        'workflow_run_id' => $run->id,
                        'source_kind' => 'workflow_command',
                        'source_id' => $command?->id ?? $update->id,
                        'propagation_kind' => 'update',
                        'failure_category' => $updateFailureCategory->value,
                        'non_retryable' => $updateNonRetryable,
                        'handled' => false,
                    ],
                ));

                $completedEvent = WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted, [
                    'workflow_command_id' => $command?->id,
                    'update_id' => $update->id,
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'workflow_run_id' => $run->id,
                    'update_name' => $update->update_name,
                    'sequence' => $sequence,
                    'failure_id' => $failure->id,
                    'failure_category' => $updateFailureCategory->value,
                    'non_retryable' => $updateNonRetryable,
                    'exception_type' => $exceptionPayload['type'] ?? null,
                    'exception_class' => $failure->exception_class,
                    'message' => $failure->message,
                    'code' => $throwable->getCode(),
                    'exception' => $exceptionPayload,
                ], $task, $command);
                $run->historyEvents->push($completedEvent);

                $update->forceFill([
                    'workflow_sequence' => $sequence,
                    'status' => UpdateStatus::Failed->value,
                    'outcome' => CommandOutcome::UpdateFailed->value,
                    'failure_id' => $failure->id,
                    'failure_message' => $failure->message,
                    'applied_at' => now(),
                    'closed_at' => now(),
                ])->save();

                if ($command instanceof WorkflowCommand) {
                    $command->forceFill([
                        'outcome' => CommandOutcome::UpdateFailed->value,
                        'applied_at' => $update->applied_at,
                    ])->save();

                    if ($command->message_sequence !== null) {
                        MessageStreamCursor::advanceCursor($run, (int) $command->message_sequence, $task);
                    }
                }

                return false;
            }
        }

        return true;
    }

    private function restartAfterPendingUpdateFailure(WorkflowRun $run, WorkflowTask $task): WorkflowTask
    {
        $run->forceFill([
            'status' => RunStatus::Waiting,
            'last_progress_at' => now(),
        ])->save();

        $task->forceFill([
            'status' => TaskStatus::Completed,
            'lease_expires_at' => null,
        ])->save();

        /** @var WorkflowTask $nextTask */
        $nextTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => [
                'resume_reason' => 'pending_update_failed',
            ],
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
        ]);

        $this->projectRun(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        return $nextTask;
    }

    private function conditionWaitResolutionEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => in_array(
                $event->event_type,
                [HistoryEventType::ConditionWaitSatisfied, HistoryEventType::ConditionWaitTimedOut],
                true,
            ) && ($event->payload['sequence'] ?? null) === $sequence
        );

        return $event;
    }

    private function conditionWaitOpenedEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::ConditionWaitOpened
                && ($event->payload['sequence'] ?? null) === $sequence
        );

        return $event;
    }

    private function conditionWaitId(WorkflowRun $run, int $sequence): ?string
    {
        $openedEvent = $this->conditionWaitOpenedEvent($run, $sequence);
        $resolutionEvent = $this->conditionWaitResolutionEvent($run, $sequence);

        return $this->stringValue($openedEvent?->payload['condition_wait_id'] ?? null)
            ?? $this->stringValue($resolutionEvent?->payload['condition_wait_id'] ?? null)
            ?? ConditionWaits::waitIdForSequence($run, $sequence);
    }

    private function recordConditionWaitOpened(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        string $waitId,
        ?int $timeoutSeconds,
        ?string $conditionKey,
        ?string $conditionDefinitionFingerprint,
    ): WorkflowHistoryEvent {
        $existingEvent = $this->conditionWaitOpenedEvent($run, $sequence);

        if ($existingEvent !== null) {
            return $existingEvent;
        }

        return WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitOpened, array_filter([
            'condition_wait_id' => $waitId,
            'condition_key' => $conditionKey,
            'condition_definition_fingerprint' => $conditionDefinitionFingerprint,
            'sequence' => $sequence,
            'timeout_seconds' => $timeoutSeconds,
        ], static fn (mixed $value): bool => $value !== null), $task);
    }

    private function recordConditionWaitSatisfied(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        string $waitId,
        ?WorkflowTimer $timer,
        AwaitCall|AwaitWithTimeoutCall $current,
    ): WorkflowHistoryEvent {
        $existingEvent = $this->conditionWaitResolutionEvent($run, $sequence);

        if ($existingEvent !== null) {
            return $existingEvent;
        }

        return WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitSatisfied, array_filter([
            'condition_wait_id' => $waitId,
            'condition_key' => $current->conditionKey,
            'condition_definition_fingerprint' => $current->conditionDefinitionFingerprint,
            'sequence' => $sequence,
            'timer_id' => $timer?->id,
            'timeout_seconds' => $current instanceof AwaitWithTimeoutCall ? $current->seconds : null,
        ], static fn (mixed $value): bool => $value !== null), $task);
    }

    private function recordConditionWaitTimedOut(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        string $waitId,
        ?WorkflowTimer $timer,
        ?int $timeoutSeconds,
        ?string $conditionKey,
        ?string $conditionDefinitionFingerprint,
        ?string $timerId = null,
    ): WorkflowHistoryEvent {
        $existingEvent = $this->conditionWaitResolutionEvent($run, $sequence);

        if ($existingEvent !== null) {
            return $existingEvent;
        }

        return WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitTimedOut, array_filter([
            'condition_wait_id' => $waitId,
            'condition_key' => $conditionKey,
            'condition_definition_fingerprint' => $conditionDefinitionFingerprint,
            'sequence' => $sequence,
            'timer_id' => $timer?->id ?? $timerId,
            'timeout_seconds' => $timeoutSeconds,
        ], static fn (mixed $value): bool => $value !== null), $task);
    }

    private function cancelConditionTimeout(WorkflowRun $run, WorkflowTask $task, WorkflowTimer $timer): void
    {
        if ($timer->status !== TimerStatus::Pending) {
            return;
        }

        $timer->forceFill([
            'status' => TimerStatus::Cancelled,
        ])->save();

        TimerCancellation::record($run, $timer, $task);

        foreach ($run->tasks as $runTask) {
            if (
                ($runTask->payload['timer_id'] ?? null) !== $timer->id
                || ! in_array($runTask->status, [TaskStatus::Ready, TaskStatus::Leased], true)
            ) {
                continue;
            }

            $runTask->forceFill([
                'status' => TaskStatus::Cancelled,
                'lease_expires_at' => null,
                'last_error' => null,
            ])->save();
        }
    }

    private function cancelSignalTimeout(WorkflowRun $run, WorkflowTask $task, WorkflowTimer $timer): void
    {
        $this->cancelConditionTimeout($run, $task, $timer);
    }

    private function latestReplayTime(?CarbonInterface $current, mixed $candidate): ?CarbonInterface
    {
        if (! $candidate instanceof CarbonInterface) {
            return $current;
        }

        if ($current === null || $candidate->getTimestampMs() > $current->getTimestampMs()) {
            return $candidate;
        }

        return $current;
    }

    private function syncWorkflowCursor(Workflow $workflow, int $visibleSequence): void
    {
        $workflow->syncExecutionCursor($visibleSequence);
        $workflow->setCommandDispatchEnabled(true);
    }

    private function syncWorkflowCursorForCurrent(
        Workflow $workflow,
        WorkflowRun $run,
        mixed $current,
        int $sequence,
    ): void {
        $this->syncWorkflowCursor($workflow, $this->visibleSequenceForCurrent($run, $current, $sequence));
    }

    private function visibleSequenceForCurrent(WorkflowRun $run, mixed $current, int $sequence): int
    {
        return match (true) {
            $current instanceof LocalActivityCall => (
                $this->activityHistoryEvent($run, $sequence) !== null
            ) ? $sequence + 1 : $sequence,
            $current instanceof ActivityCall => (
                $this->activityHistoryEvent($run, $sequence) !== null
            ) ? $sequence + 1 : $sequence,
            $current instanceof AwaitCall => (
                $this->conditionWaitOpenedEvent($run, $sequence) !== null
                || $this->conditionWaitResolutionEvent($run, $sequence) !== null
            ) ? $sequence + 1 : $sequence,
            $current instanceof AwaitWithTimeoutCall => (
                $this->conditionWaitOpenedEvent($run, $sequence) !== null
                || $this->conditionWaitResolutionEvent($run, $sequence) !== null
                || $run->timers->firstWhere('sequence', $sequence) instanceof WorkflowTimer
            ) ? $sequence + 1 : $sequence,
            $current instanceof TimerCall => (
                $this->timerScheduledEvent($run, $sequence) !== null
                || $this->timerFiredEvent($run, $sequence) !== null
            ) ? $sequence + 1 : $sequence,
            $current instanceof SignalCall => (
                $this->appliedSignalEvent($run, $sequence, $current) !== null
                || $this->pendingSignalCommand($run, $current) !== null
                || SignalWaits::openWaitIdForName($run, $current->name) !== null
            ) ? $sequence + 1 : $sequence,
            $current instanceof ChildWorkflowCall => (
                ChildRunHistory::scheduledEventForSequence($run, $sequence) !== null
                || ChildRunHistory::startedEventForSequence($run, $sequence) !== null
                || ChildRunHistory::resolutionEventForSequence($run, $sequence) !== null
            ) ? $sequence + 1 : $sequence,
            $current instanceof AllCall => $this->visibleSequenceForAllCall($run, $current, $sequence),
            default => $sequence,
        };
    }

    private function visibleSequenceForAllCall(WorkflowRun $run, AllCall $current, int $sequence): int
    {
        $groupSize = $current->leafCount();

        if ($groupSize === 0) {
            return $sequence;
        }

        foreach ($current->leafDescriptors($sequence) as $descriptor) {
            $call = $descriptor['call'];
            $itemSequence = $sequence + $descriptor['offset'];

            if ($call instanceof ActivityCall) {
                if (
                    $this->activityHistoryEvent($run, $itemSequence) !== null
                ) {
                    return $sequence + $groupSize;
                }

                continue;
            }

            if (
                ChildRunHistory::scheduledEventForSequence($run, $itemSequence) !== null
                || ChildRunHistory::startedEventForSequence($run, $itemSequence) !== null
                || ChildRunHistory::resolutionEventForSequence($run, $itemSequence) !== null
            ) {
                return $sequence + $groupSize;
            }
        }

        return $sequence;
    }

    private function projectRun(WorkflowRun $run): void
    {
        $this->historyProjectionRole()
            ->projectRun($run);
    }

    private function historyProjectionRole(): HistoryProjectionRole
    {
        /** @var HistoryProjectionRole $role */
        $role = app(HistoryProjectionRole::class);

        return $role;
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $parallelMetadata
     * @return list<array<string, mixed>>|null
     */
    private static function parallelGroupPath(?array $parallelMetadata): ?array
    {
        if ($parallelMetadata === null) {
            return null;
        }

        $path = ParallelChildGroup::metadataPathFromPayload($parallelMetadata);

        return $path === [] ? null : $path;
    }

    /**
     * Log a structured warning when a count- or size-based resource is
     * approaching its hard structural limit. Callers pass the result of
     * one of the `StructuralLimits::warnApproaching*()` helpers — null
     * means "safe, no warning needed."
     *
     * @param array{limit_kind: string, current: int, limit: int, threshold_percent: int, utilization_percent: int}|null $warning
     * @param array<string, mixed> $extraContext
     */
    private function logApproachingLimit(?array $warning, WorkflowRun $run, array $extraContext = []): void
    {
        StructuralLimits::logWarning($warning, array_merge([
            'workflow_run_id' => $run->id,
            'workflow_type' => $run->workflow_type,
        ], $extraContext));
    }

    private function inheritTypedVisibilityMetadata(WorkflowRun $sourceRun, WorkflowRun $targetRun): void
    {
        app(MemoUpsertService::class)->inheritFromParent($sourceRun, $targetRun, 1);
        app(SearchAttributeUpsertService::class)->inheritFromParent($sourceRun, $targetRun, 1);

        $targetRun->unsetRelation('memos');
        $targetRun->unsetRelation('searchAttributes');
    }
}
