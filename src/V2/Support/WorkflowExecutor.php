<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Str;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Exceptions\ConditionWaitDefinitionMismatchException;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Exceptions\UnresolvedWorkflowFailureException;
use Workflow\V2\Exceptions\UnsupportedWorkflowYieldException;
use Workflow\V2\Models\ActivityExecution;
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
use Workflow\V2\Support\LifecycleEventDispatcher;
use Workflow\WorkflowMetadata;

final class WorkflowExecutor
{
    public function run(WorkflowRun $run, WorkflowTask $task): ?WorkflowTask
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

        if ($this->deadlineExpired($run)) {
            $this->timeoutRun($run, $task);

            return null;
        }

        $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        $workflow = new $workflowClass($run);
        $entryMethod = EntryMethod::forWorkflow($workflow);
        $arguments = $workflow->resolveMethodDependencies($run->workflowArguments(), $entryMethod);

        try {
            $workflowExecution = WorkflowExecution::start($workflow, $arguments);
        } catch (Throwable $throwable) {
            $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

            return null;
        }

        if (! $workflowExecution->valid()) {
            $this->completeRun($run, $task, $workflowExecution->getReturn());

            return null;
        }

        $current = $workflowExecution->current();

        $sequence = 1;
        $this->syncWorkflowCursor($workflow, $sequence);

        while (true) {
            if (! $workflowExecution->valid()) {
                try {
                    $this->syncWorkflowCursor($workflow, $sequence);
                    $this->completeRun($run, $task, $workflowExecution->getReturn());
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);
                }

                return null;
            }

            if ($current instanceof ActivityCall) {
                $this->syncWorkflowCursorForCurrent($workflow, $run, $current, $sequence);

                if (! $this->applyRecordedUpdates($run, $workflow, $sequence, $task)) {
                    return $this->restartAfterPendingUpdateFailure($run, $task);
                }

                if (! $this->ensureStepHistoryCompatible($run, $task, $sequence, WorkflowStepHistory::ACTIVITY)) {
                    return null;
                }

                $activityCompletion = $this->activityCompletionEvent($run, $sequence);

                if ($activityCompletion !== null) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        if ($activityCompletion->event_type === HistoryEventType::ActivityCompleted) {
                            $current = $workflowExecution->send($this->activityResult($activityCompletion));
                        } else {
                            $failureId = $activityCompletion->payload['failure_id'] ?? null;

                            $current = $workflowExecution->throw(
                                $this->activityException($activityCompletion, null, $run)
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
                        $current = $workflowExecution->send($execution->activityResult());
                    } else {
                        $failure = $run->failures
                            ->firstWhere('source_id', $execution->id);

                        $current = $workflowExecution->throw($this->activityException(null, $execution, $run));

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
                            $resolutionEvent->event_type === HistoryEventType::ConditionWaitSatisfied
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
                        $current = $workflowExecution->send(false);
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

                    $this->recordConditionWaitSatisfied($run, $task, $sequence, $waitId, $timeoutTimer, $current);

                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        $current = $workflowExecution->send(true);
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

                        $this->recordConditionWaitTimedOut(
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
                            $current = $workflowExecution->send(false);
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
                return $this->waitForNextResumeSource($run, $task);
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
                    $current = $workflowExecution->send($this->sideEffectResult($sideEffectEvent));
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

                if (! $this->ensureStepHistoryCompatible($run, $task, $sequence, WorkflowStepHistory::VERSION_MARKER)) {
                    return null;
                }

                $versionMarkerEvent = $this->versionMarkerEvent($run, $sequence);

                try {
                    $resolution = VersionResolver::resolve($run, $versionMarkerEvent, $current, $sequence);
                    $version = $resolution->version;

                    if ($resolution->shouldRecordMarker) {
                        $versionMarkerEvent = $this->recordVersionMarker($run, $task, $sequence, $current, $version);
                    }

                    $this->syncWorkflowCursor($workflow, $sequence + ($resolution->advancesSequence ? 1 : 0));
                    $current = $workflowExecution->send($version);
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
                    $current = $workflowExecution->send(null);
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
                    $current = $workflowExecution->send(null);
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

                if ($this->timerFiredEvent($run, $sequence) !== null) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        $current = $workflowExecution->send(true);
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
                        $this->fireImmediateTimer($run, $task, $sequence, $current);

                        try {
                            $this->syncWorkflowCursor($workflow, $sequence + 1);
                            $current = $workflowExecution->send(true);
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
                    $current = $workflowExecution->send(true);
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

                if (! $this->ensureStepHistoryCompatible($run, $task, $sequence, WorkflowStepHistory::SIGNAL_WAIT)) {
                    return null;
                }

                $signalEvent = $this->appliedSignalEvent($run, $sequence, $current);

                if ($signalEvent !== null) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        $current = $workflowExecution->send($this->signalValue($signalEvent));
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    ++$sequence;
                    continue;
                }

                $signalCommand = $this->pendingSignalCommand($run, $current);

                if ($signalCommand !== null) {
                    $signalWaitId = $this->signalWaitIdForCommand($run, $signalCommand, $current->name);

                    $this->recordSignalWait($run, $task, $sequence, $current, $signalWaitId);

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
                        $current = $workflowExecution->send($this->signalValue($signalEvent));
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    ++$sequence;
                    continue;
                }

                $this->recordSignalWait($run, $task, $sequence, $current);

                $this->syncWorkflowCursor($workflow, $sequence + 1);
                return $this->waitForNextResumeSource($run, $task);
            }

            if ($current instanceof ChildWorkflowCall) {
                $this->syncWorkflowCursorForCurrent($workflow, $run, $current, $sequence);

                if (! $this->applyRecordedUpdates($run, $workflow, $sequence, $task)) {
                    return $this->restartAfterPendingUpdateFailure($run, $task);
                }

                if (! $this->ensureStepHistoryCompatible($run, $task, $sequence, WorkflowStepHistory::CHILD_WORKFLOW)) {
                    return null;
                }

                $resolutionEvent = ChildRunHistory::resolutionEventForSequence($run, $sequence);
                $childRun = ChildRunHistory::childRunForSequence($run, $sequence);

                if ($resolutionEvent !== null) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        if ($resolutionEvent->event_type === HistoryEventType::ChildRunCompleted) {
                            $current = $workflowExecution->send(
                                ChildRunHistory::outputForResolution($resolutionEvent, $childRun)
                            );
                        } else {
                            $failureId = $resolutionEvent->payload['failure_id'] ?? null;
                            $current = $workflowExecution->throw(
                                ChildRunHistory::exceptionForResolution($resolutionEvent, $childRun)
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
                            ChildRunHistory::outputForResolution($resolutionEvent, $childRun)
                        );
                    } else {
                        $failureId = $resolutionEvent->payload['failure_id'] ?? null;
                        $current = $workflowExecution->throw(
                            ChildRunHistory::exceptionForResolution($resolutionEvent, $childRun)
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

                foreach ($leafDescriptors as $descriptor) {
                    $call = $descriptor['call'];
                    $offset = $descriptor['offset'];
                    $itemSequence = $sequence + $offset;
                    $parallelMetadata = ParallelChildGroup::payloadForPath($descriptor['group_path']);

                    if ($call instanceof ActivityCall) {
                        $activityCompletion = $this->activityCompletionEvent($run, $itemSequence);

                        if ($activityCompletion !== null) {
                            if ($activityCompletion->event_type === HistoryEventType::ActivityCompleted) {
                                $results[$offset] = $this->activityResult($activityCompletion);

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

                        continue;
                    }

                    $failure = ParallelFailureSelector::select(
                        $failure,
                        $offset,
                        ChildRunHistory::exceptionForChildRun($childRun),
                        $childRun->closed_at?->getTimestampMs() ?? PHP_INT_MAX,
                    );
                }

                if ($failure !== null) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + $groupSize);
                        $current = $workflowExecution->throw($failure['exception']);

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
                        $current = $workflowExecution->send($current->nestedResults(array_values($results)));
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

                return $this->continueAsNew($run, $task, $sequence, $current, $workflowClass);
            }

            $this->failRun(
                $run,
                $task,
                new UnsupportedWorkflowYieldException(sprintf(
                    'Workflow %s yielded %s. v2 currently supports activity(), await(), awaitWithTimeout(), child(), all(), sideEffect(), getVersion(), timer(), awaitSignal(), and continueAsNew() only.',
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
        EntryMethod::describeActivity($activityCall->activity);

        $options = $activityCall->options;

        $scheduleDeadlineAt = $options?->scheduleToStartTimeout !== null
            ? now()->addSeconds($options->scheduleToStartTimeout)
            : null;

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'activity_class' => $activityCall->activity,
            'activity_type' => TypeRegistry::for($activityCall->activity),
            'status' => ActivityStatus::Pending->value,
            'attempt_count' => 0,
            'arguments' => Serializer::serialize($activityCall->arguments),
            'connection' => RoutingResolver::activityConnection($activityCall->activity, $run, $options),
            'queue' => RoutingResolver::activityQueue($activityCall->activity, $run, $options),
            'parallel_group_path' => self::parallelGroupPath($parallelMetadata),
            'activity_options' => $options?->toSnapshot(),
            'schedule_deadline_at' => $scheduleDeadlineAt,
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

        $this->markRunWaiting($run, $task);

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
        $metadata = WorkflowMetadata::fromStartArguments($childWorkflowCall->arguments);
        $workflowType = TypeRegistry::for($childWorkflowCall->workflow);
        $commandContract = RunCommandContract::snapshot($childWorkflowCall->workflow);
        $now = now();

        /** @var WorkflowInstance $childInstance */
        $childInstance = WorkflowInstance::query()->create([
            'workflow_class' => $childWorkflowCall->workflow,
            'workflow_type' => $workflowType,
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
            'business_key' => null,
            'visibility_labels' => null,
            'status' => RunStatus::Pending->value,
            'compatibility' => $run->compatibility ?? WorkerCompatibility::current(),
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize($metadata->arguments),
            'connection' => RoutingResolver::workflowConnection($childWorkflowCall->workflow, $metadata),
            'queue' => RoutingResolver::workflowQueue($childWorkflowCall->workflow, $metadata),
            'started_at' => $now,
            'last_progress_at' => $now,
            'last_history_sequence' => 0,
        ]);

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

        RunSummaryProjector::project(
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
    ): void {
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

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerFired, [
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
                    && $command->targetName() === $signalCall->name
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

    private function applySignal(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        SignalCall $signalCall,
        WorkflowCommand $command,
        string $signalWaitId,
    ): WorkflowHistoryEvent {
        $value = $this->signalPayloadValue($command);
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
            'value' => Serializer::serialize($value),
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

        WorkflowHistoryEvent::record($run, HistoryEventType::SignalWaitOpened, [
            'signal_name' => $signalCall->name,
            'signal_wait_id' => $signalWaitId ?? (string) Str::ulid(),
            'sequence' => $sequence,
        ], $task);
    }

    private function signalWaitIdForCommand(WorkflowRun $run, WorkflowCommand $command, string $signalName): string
    {
        /** @var WorkflowHistoryEvent|null $receivedEvent */
        $receivedEvent = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::SignalReceived
                && $event->workflow_command_id === $command->id
        );

        $signalWaitId = $receivedEvent === null
            ? null
            : $this->stringValue($receivedEvent->payload['signal_wait_id'] ?? null);

        return $signalWaitId
            ?? SignalWaits::openWaitIdForName($run, $signalName)
            ?? SignalWaits::bufferedWaitIdForCommandId($command->id);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private function signalValue(WorkflowHistoryEvent $event): mixed
    {
        $serialized = $event->payload['value'] ?? null;

        if (! is_string($serialized)) {
            return null;
        }

        return Serializer::unserialize($serialized);
    }

    private function signalPayloadValue(WorkflowCommand $command): mixed
    {
        $arguments = $command->payloadArguments();

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
            'output' => $childTerminalEvent?->event_type === HistoryEventType::WorkflowCompleted
                ? $childTerminalEvent->payload['output'] ?? $childRun->output
                : null,
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

    private function waitForNextResumeSource(WorkflowRun $run, WorkflowTask $task): ?WorkflowTask
    {
        $this->markRunWaiting($run, $task);

        return null;
    }

    private function markRunWaiting(WorkflowRun $run, WorkflowTask $task): void
    {
        $run->forceFill([
            'status' => RunStatus::Waiting,
        ])->save();

        $task->forceFill([
            'status' => TaskStatus::Completed,
            'lease_expires_at' => null,
        ])->save();

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
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

        /** @var WorkflowRun $continuedRun */
        $continuedRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $run->workflow_instance_id,
            'run_number' => $run->run_number + 1,
            'workflow_class' => $workflowClass,
            'workflow_type' => $run->workflow_type,
            'business_key' => $run->business_key,
            'visibility_labels' => $run->visibility_labels,
            'memo' => $run->memo,
            'search_attributes' => $run->search_attributes,
            'run_timeout_seconds' => $runTimeoutSeconds,
            'execution_deadline_at' => $executionDeadlineAt,
            'run_deadline_at' => $runDeadlineAt,
            'status' => RunStatus::Pending->value,
            'compatibility' => $run->compatibility,
            'payload_codec' => $run->payload_codec,
            'arguments' => Serializer::serialize($continueAsNew->arguments),
            'connection' => $run->connection,
            'queue' => $run->queue,
            'started_at' => $now,
            'last_progress_at' => $now,
            'last_history_sequence' => 0,
        ]);

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

        WorkflowHistoryEvent::record($continuedRun, HistoryEventType::StartAccepted, [
            'workflow_command_id' => $startCommand->id,
            'workflow_instance_id' => $continuedRun->workflow_instance_id,
            'workflow_run_id' => $continuedRun->id,
            'workflow_class' => $continuedRun->workflow_class,
            'workflow_type' => $continuedRun->workflow_type,
            'business_key' => $continuedRun->business_key,
            'visibility_labels' => $continuedRun->visibility_labels,
            'memo' => $continuedRun->memo,
            'search_attributes' => $continuedRun->search_attributes,
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
            'memo' => $continuedRun->memo,
            'search_attributes' => $continuedRun->search_attributes,
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

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
        foreach (array_keys($parentRunsToProject) as $parentRunId) {
            /** @var WorkflowRun|null $parentRun */
            $parentRun = WorkflowRun::query()->find($parentRunId);

            if (! $parentRun instanceof WorkflowRun) {
                continue;
            }

            RunSummaryProjector::project(
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
        RunSummaryProjector::project(
            $continuedRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        return $continuedTask;
    }

    private function completeRun(WorkflowRun $run, WorkflowTask $task, mixed $result): void
    {
        $run->forceFill([
            'status' => RunStatus::Completed,
            'closed_reason' => 'completed',
            'output' => Serializer::serialize($result),
            'closed_at' => now(),
            'last_progress_at' => now(),
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted, [
            'output' => $run->output,
        ], $task);

        $task->forceFill([
            'status' => TaskStatus::Completed,
            'lease_expires_at' => null,
        ])->save();

        LifecycleEventDispatcher::workflowCompleted($run);

        $this->dispatchParentResumeTasks($run);

        RunSummaryProjector::project(
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
                    'payload_codec' => config('workflows.serializer'),
                    'payload' => Serializer::serialize($arguments),
                    'accepted_at' => $recordedAt,
                    'applied_at' => $recordedAt,
                    'created_at' => $recordedAt,
                    'updated_at' => $recordedAt,
                ],
            ),
        );

        return $command;
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

        $timeoutKind = 'run_timeout';

        if ($run->execution_deadline_at !== null && $now->gte($run->execution_deadline_at)) {
            $timeoutKind = 'execution_timeout';
        }

        $message = $timeoutKind === 'execution_timeout'
            ? sprintf('Workflow execution deadline expired at %s.', $run->execution_deadline_at->toIso8601String())
            : sprintf('Workflow run deadline expired at %s.', $run->run_deadline_at->toIso8601String());

        $exceptionClass = 'Workflow\\V2\\Exceptions\\WorkflowTimeoutException';

        // Cancel all open tasks except the current one.
        $openTasks = $run->tasks
            ->filter(static fn (WorkflowTask $t): bool => in_array($t->status, [TaskStatus::Ready, TaskStatus::Leased], true)
                && $t->id !== $task->id);

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
            ->filter(static fn (ActivityExecution $e): bool => in_array($e->status, [ActivityStatus::Pending, ActivityStatus::Running], true));

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

        // Notify parent workflows and project summary.
        $this->dispatchParentResumeTasks($run);

        RunSummaryProjector::project(
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

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()->create(array_merge(
            FailureFactory::make($throwable),
            [
                'workflow_run_id' => $run->id,
                'source_kind' => $sourceKind,
                'source_id' => $sourceId,
                'propagation_kind' => 'terminal',
                'failure_category' => $failureCategory->value,
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

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowFailed, [
            'failure_id' => $failure->id,
            'source_kind' => $sourceKind,
            'source_id' => $sourceId,
            'failure_category' => $failureCategory->value,
            'exception_type' => $exceptionPayload['type'] ?? null,
            'exception_class' => $failure->exception_class,
            'message' => $failure->message,
            'exception' => $exceptionPayload,
        ], $task);

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

        $this->dispatchParentResumeTasks($run);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
    }

    private function ensureStepHistoryCompatible(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        string $expectedShape,
    ): bool {
        try {
            WorkflowStepHistory::assertCompatible($run, $sequence, $expectedShape);

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

        RunSummaryProjector::project(
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

        RunSummaryProjector::project(
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

        RunSummaryProjector::project(
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

        WorkflowHistoryEvent::record($run, HistoryEventType::FailureHandled, array_filter([
            'failure_id' => $failureId,
            'sequence' => $workflowSequence,
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
                    RunSummaryProjector::project(
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
                    RunSummaryProjector::project(
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
                    RunSummaryProjector::project(
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

            RunSummaryProjector::project(
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

    private function activityResult(WorkflowHistoryEvent $event): mixed
    {
        $serialized = $event->payload['result'] ?? null;

        if (! is_string($serialized)) {
            return null;
        }

        return Serializer::unserialize($serialized);
    }

    private function sideEffectResult(WorkflowHistoryEvent $event): mixed
    {
        $serialized = $event->payload['result'] ?? null;

        if (! is_string($serialized)) {
            return null;
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

        $event = WorkflowHistoryEvent::record(
            $run,
            HistoryEventType::SideEffectRecorded,
            [
                'sequence' => $sequence,
                'result' => Serializer::serialize($result),
            ],
            $task,
        );

        $run->historyEvents->push($event);

        return $event;
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
        $existing = is_array($run->search_attributes) ? $run->search_attributes : [];

        $merged = $existing;

        foreach ($call->attributes as $key => $value) {
            if ($value === null) {
                unset($merged[$key]);
            } else {
                $merged[$key] = $value;
            }
        }

        ksort($merged);

        $run->search_attributes = $merged;
        $run->save();

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
        $existing = is_array($run->memo) ? $run->memo : [];

        $merged = $existing;

        foreach ($call->entries as $key => $value) {
            if ($value === null) {
                unset($merged[$key]);
            } else {
                $merged[$key] = $value;
            }
        }

        ksort($merged);

        $run->memo = $merged;
        $run->save();

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
                && ($event->payload['timer_kind'] ?? null) !== 'condition_timeout'
                && ($event->payload['sequence'] ?? null) === $sequence
        );

        return $event;
    }

    private function timerScheduledEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::TimerScheduled
                && ($event->payload['timer_kind'] ?? null) !== 'condition_timeout'
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

    private function activityException(
        ?WorkflowHistoryEvent $event = null,
        ?ActivityExecution $execution = null,
        ?WorkflowRun $run = null,
    ): Throwable {
        $payload = is_array($event?->payload['exception'] ?? null)
            ? $event->payload['exception']
            : (is_string($execution?->exception)
                ? Serializer::unserialize($execution->exception)
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

                $target = $event->payload['update_name'] ?? $command?->targetName();

                if (! is_string($target) || $target === '') {
                    throw new LogicException(sprintf(
                        'Workflow update event [%s] is missing an update method name.',
                        $event->id,
                    ));
                }

                $method = WorkflowDefinition::resolveUpdateTarget($workflow::class, $target)['method'] ?? $target;

                $serializedArguments = $event->payload['arguments'] ?? null;
                $arguments = is_string($serializedArguments)
                    ? Serializer::unserialize($serializedArguments)
                    : $command?->payloadArguments();

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
                    $update->updateArguments(),
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

                $completedEvent = WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted, [
                    'workflow_command_id' => $command?->id,
                    'update_id' => $update->id,
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'workflow_run_id' => $run->id,
                    'update_name' => $target,
                    'sequence' => $sequence,
                    'result' => Serializer::serialize($result),
                ], $task, $command);
                $run->historyEvents->push($completedEvent);

                $update->forceFill([
                    'workflow_sequence' => $sequence,
                    'status' => UpdateStatus::Completed->value,
                    'outcome' => CommandOutcome::UpdateCompleted->value,
                    'result' => Serializer::serialize($result),
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

                /** @var WorkflowFailure $failure */
                $failure = WorkflowFailure::query()->create(array_merge(
                    FailureFactory::make($throwable),
                    [
                        'workflow_run_id' => $run->id,
                        'source_kind' => 'workflow_command',
                        'source_id' => $command?->id ?? $update->id,
                        'propagation_kind' => 'update',
                        'failure_category' => $updateFailureCategory->value,
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

        RunSummaryProjector::project(
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
}
