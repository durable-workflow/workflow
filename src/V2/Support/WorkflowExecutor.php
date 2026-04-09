<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Generator;
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
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Enums\UpdateStatus;
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

        $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        $workflow = new $workflowClass($run);
        $arguments = $workflow->resolveMethodDependencies(
            $run->workflowArguments(),
            new ReflectionMethod($workflow, 'execute'),
        );

        try {
            $result = $workflow->execute(...$arguments);
        } catch (Throwable $throwable) {
            $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

            return null;
        }

        if (! $result instanceof Generator) {
            $this->completeRun($run, $task, $result);

            return null;
        }

        try {
            $current = $result->current();
        } catch (Throwable $throwable) {
            $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

            return null;
        }

        $sequence = 1;
        $this->syncWorkflowCursor($workflow, $sequence);

        while (true) {
            if (! $result->valid()) {
                try {
                    $this->syncWorkflowCursor($workflow, $sequence);
                    $this->completeRun($run, $task, $result->getReturn());
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

                $activityCompletion = $this->activityCompletionEvent($run, $sequence);

                if ($activityCompletion !== null) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        if ($activityCompletion->event_type === HistoryEventType::ActivityCompleted) {
                            $current = $result->send($this->activityResult($activityCompletion));
                        } else {
                            $failureId = $activityCompletion->payload['failure_id'] ?? null;

                            if (is_string($failureId)) {
                                /** @var WorkflowFailure|null $failure */
                                $failure = $run->failures->firstWhere('id', $failureId);

                                if ($failure !== null) {
                                    $failure->forceFill([
                                        'handled' => true,
                                    ])->save();
                                }
                            }

                            $current = $result->throw($this->activityException($activityCompletion, null, $run));
                        }
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    ++$sequence;
                    continue;
                }

                /** @var ActivityExecution|null $execution */
                $execution = $run->activityExecutions->firstWhere('sequence', $sequence);

                if ($execution === null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return $this->scheduleActivity($run, $task, $sequence, $current);
                }

                if (in_array($execution->status, [ActivityStatus::Pending, ActivityStatus::Running], true)) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return $this->waitForNextResumeSource($run, $task);
                }

                try {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    if ($execution->status === ActivityStatus::Completed) {
                        $current = $result->send($execution->activityResult());
                    } else {
                        $failure = $run->failures
                            ->firstWhere('source_id', $execution->id);

                        if ($failure !== null) {
                            $failure->forceFill([
                                'handled' => true,
                            ])->save();
                        }

                        $current = $result->throw($this->activityException(null, $execution, $run));
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

                $resolutionEvent = $this->conditionWaitResolutionEvent($run, $sequence);

                if ($resolutionEvent !== null) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        $current = $result->send(
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
                );

                /** @var WorkflowTimer|null $timeoutTimer */
                $timeoutTimer = $current instanceof AwaitWithTimeoutCall
                    ? $run->timers->firstWhere('sequence', $sequence)
                    : null;

                if (
                    $current instanceof AwaitWithTimeoutCall
                    && (
                        $this->timerFiredEvent($run, $sequence) !== null
                        || $timeoutTimer?->status === TimerStatus::Fired
                    )
                ) {
                    $this->recordConditionWaitTimedOut(
                        $run,
                        $task,
                        $sequence,
                        $waitId,
                        $timeoutTimer,
                        $current->seconds
                    );

                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        $current = $result->send(false);
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
                        $this->cancelConditionTimeout($run, $timeoutTimer);
                    }

                    $this->recordConditionWaitSatisfied($run, $task, $sequence, $waitId, $timeoutTimer, $current);

                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        $current = $result->send(true);
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
                        );

                        try {
                            $this->syncWorkflowCursor($workflow, $sequence + 1);
                            $current = $result->send(false);
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

                $sideEffectEvent = $this->sideEffectEvent($run, $sequence);

                try {
                    if ($sideEffectEvent === null) {
                        $sideEffectEvent = $this->recordSideEffect($run, $task, $sequence, $current);
                    }

                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    $current = $result->send($this->sideEffectResult($sideEffectEvent));
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
                    $version = $resolution->version;

                    if ($resolution->shouldRecordMarker) {
                        $versionMarkerEvent = $this->recordVersionMarker($run, $task, $sequence, $current, $version);
                    }

                    $this->syncWorkflowCursor($workflow, $sequence + ($resolution->advancesSequence ? 1 : 0));
                    $current = $result->send($version);
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                if ($resolution->advancesSequence) {
                    ++$sequence;
                }

                continue;
            }

            if ($current instanceof TimerCall) {
                $this->syncWorkflowCursorForCurrent($workflow, $run, $current, $sequence);

                if (! $this->applyRecordedUpdates($run, $workflow, $sequence, $task)) {
                    return $this->restartAfterPendingUpdateFailure($run, $task);
                }

                if ($this->timerFiredEvent($run, $sequence) !== null) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        $current = $result->send(true);
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    ++$sequence;
                    continue;
                }

                /** @var WorkflowTimer|null $timer */
                $timer = $run->timers->firstWhere('sequence', $sequence);

                if ($timer === null) {
                    if ($current->seconds === 0) {
                        $this->fireImmediateTimer($run, $task, $sequence, $current);

                        try {
                            $this->syncWorkflowCursor($workflow, $sequence + 1);
                            $current = $result->send(true);
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
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return $this->waitForNextResumeSource($run, $task);
                }

                try {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    $current = $result->send(true);
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

                $signalEvent = $this->appliedSignalEvent($run, $sequence, $current);

                if ($signalEvent !== null) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        $current = $result->send($this->signalValue($signalEvent));
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
                        $current = $result->send($this->signalValue($signalEvent));
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

                $resolutionEvent = ChildRunHistory::resolutionEventForSequence($run, $sequence);
                $childRun = ChildRunHistory::childRunForSequence($run, $sequence);

                if ($resolutionEvent !== null) {
                    try {
                        $this->syncWorkflowCursor($workflow, $sequence + 1);
                        if ($resolutionEvent->event_type === HistoryEventType::ChildRunCompleted) {
                            $current = $result->send(
                                ChildRunHistory::outputForResolution($resolutionEvent, $childRun)
                            );
                        } else {
                            $current = $result->throw(
                                ChildRunHistory::exceptionForResolution($resolutionEvent, $childRun)
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
                    return $this->scheduleChildWorkflow($run, $task, $sequence, $current);
                }

                $childStatus = ChildRunHistory::resolvedStatus(null, $childRun);

                if (in_array($childStatus, [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting], true)) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return $this->waitForNextResumeSource($run, $task);
                }

                $resolutionEvent = $this->recordChildResolution($run, $task, $sequence, $childRun);

                try {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    if ($resolutionEvent->event_type === HistoryEventType::ChildRunCompleted) {
                        $current = $result->send(ChildRunHistory::outputForResolution($resolutionEvent, $childRun));
                    } else {
                        $current = $result->throw(ChildRunHistory::exceptionForResolution($resolutionEvent, $childRun));
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
                        $current = $result->send($current->nestedResults([]));
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    continue;
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
                            );

                            continue;
                        }

                        /** @var ActivityExecution|null $execution */
                        $execution = $run->activityExecutions->firstWhere('sequence', $itemSequence);

                        if (! $execution instanceof ActivityExecution) {
                            $scheduledTasks[] = $this->scheduleActivity(
                                $run,
                                $task,
                                $itemSequence,
                                $call,
                                $parallelMetadata,
                                false,
                            );
                            $pending = true;

                            continue;
                        }

                        if (in_array($execution->status, [
                            ActivityStatus::Pending,
                            ActivityStatus::Running,
                        ], true)) {
                            $pending = true;

                            continue;
                        }

                        if ($execution->status === ActivityStatus::Completed) {
                            $results[$offset] = $execution->activityResult();

                            continue;
                        }

                        $failure = ParallelFailureSelector::select(
                            $failure,
                            $offset,
                            $this->activityException(null, $execution, $run),
                            $execution->closed_at?->getTimestampMs() ?? PHP_INT_MAX,
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

                        $scheduledTasks[] = $this->scheduleChildWorkflow(
                            $run,
                            $task,
                            $itemSequence,
                            $call,
                            $parallelMetadata,
                            false,
                        );
                        $pending = true;

                        continue;
                    }

                    $childStatus = ChildRunHistory::resolvedStatus(null, $childRun);

                    if (! $childStatus instanceof RunStatus || in_array($childStatus, [
                        RunStatus::Pending,
                        RunStatus::Running,
                        RunStatus::Waiting,
                    ], true)) {
                        $pending = true;

                        continue;
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
                        $current = $result->throw($failure['exception']);
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
                        $current = $result->send($current->nestedResults(array_values($results)));
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
        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'activity_class' => $activityCall->activity,
            'activity_type' => TypeRegistry::for($activityCall->activity),
            'status' => ActivityStatus::Pending->value,
            'attempt_count' => 0,
            'arguments' => Serializer::serialize($activityCall->arguments),
            'connection' => RoutingResolver::activityConnection($activityCall->activity, $run),
            'queue' => RoutingResolver::activityQueue($activityCall->activity, $run),
        ]);
        $activityClass = TypeRegistry::resolveActivityClass($execution->activity_class, $execution->activity_type);
        $activity = new $activityClass($execution, $run, $task->id);

        $execution->forceFill([
            'retry_policy' => ActivityRetryPolicy::snapshot($activity),
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
        ], $task);

        /** @var WorkflowTask $timerTask */
        $timerTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Timer->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $fireAt,
            'payload' => [
                'timer_id' => $timer->id,
                'condition_wait_id' => $waitId,
            ],
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
        ], null, $startCommand);

        /** @var WorkflowTask $childTask */
        $childTask = WorkflowTask::query()->create([
            'workflow_run_id' => $childRun->id,
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
        ], $task);

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerFired, [
            'timer_id' => $timer->id,
            'sequence' => $sequence,
            'delay_seconds' => $timer->delay_seconds,
            'fired_at' => $timer->fired_at?->toJSON(),
            'timer_kind' => 'condition_timeout',
            'condition_wait_id' => $waitId,
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
        WorkflowTask $task,
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
        $parallelMetadataPath = ParallelChildGroup::metadataPathForSequence($run, $sequence);
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
            'exception' => $childTerminalEvent?->event_type === HistoryEventType::WorkflowFailed
                ? $childTerminalEvent->payload['exception'] ?? null
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

        /** @var WorkflowRun $continuedRun */
        $continuedRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $run->workflow_instance_id,
            'run_number' => $run->run_number + 1,
            'workflow_class' => $workflowClass,
            'workflow_type' => $run->workflow_type,
            'business_key' => $run->business_key,
            'visibility_labels' => $run->visibility_labels,
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
                    ParallelChildGroup::metadataPathForSequence($parentRun, $parentChildLink->sequence)
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
            'parent_workflow_instance_id' => $parentReference['parent_workflow_instance_id'] ?? null,
            'parent_workflow_run_id' => $parentReference['parent_workflow_run_id'] ?? null,
            'parent_sequence' => $parentReference['parent_sequence'] ?? null,
        ], null, $startCommand);

        /** @var WorkflowTask $continuedTask */
        $continuedTask = WorkflowTask::query()->create([
            'workflow_run_id' => $continuedRun->id,
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

    private function failRun(
        WorkflowRun $run,
        WorkflowTask $task,
        Throwable $throwable,
        string $sourceKind,
        string $sourceId,
    ): void {
        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()->create(array_merge(
            FailureFactory::make($throwable),
            [
                'workflow_run_id' => $run->id,
                'source_kind' => $sourceKind,
                'source_id' => $sourceId,
                'propagation_kind' => 'terminal',
                'handled' => false,
            ],
        ));

        $run->forceFill([
            'status' => RunStatus::Failed,
            'closed_reason' => 'failed',
            'closed_at' => now(),
            'last_progress_at' => now(),
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowFailed, [
            'failure_id' => $failure->id,
            'source_kind' => $sourceKind,
            'source_id' => $sourceId,
            'exception_class' => $failure->exception_class,
            'message' => $failure->message,
            'exception' => FailureFactory::payload($throwable),
        ], $task);

        $task->forceFill([
            'status' => TaskStatus::Failed,
            'lease_expires_at' => null,
        ])->save();

        $this->dispatchParentResumeTasks($run);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
    }

    private function dispatchParentResumeTasks(WorkflowRun $childRun): void
    {
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

            $parentReferences[$parentLink->parent_workflow_run_id] = [
                'parent_workflow_run_id' => $parentLink->parent_workflow_run_id,
                'parent_sequence' => is_int($parentLink->sequence)
                    ? $parentLink->sequence
                    : ($parentReferences[$parentLink->parent_workflow_run_id]['parent_sequence'] ?? null),
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

            if (is_int($parentReference['parent_sequence'])) {
                $parentRun->loadMissing([
                    'historyEvents',
                    'childLinks.childRun.instance.currentRun',
                    'childLinks.childRun.failures',
                    'childLinks.childRun.historyEvents',
                ]);

                $parallelMetadataPath = ParallelChildGroup::metadataPathForSequence(
                    $parentRun,
                    $parentReference['parent_sequence']
                );
                $childStatus = ChildRunHistory::resolvedStatus(
                    ChildRunHistory::resolutionEventForSequence($parentRun, $parentReference['parent_sequence']),
                    $childRun,
                );

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
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now(),
                'payload' => [],
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
                [HistoryEventType::ActivityCompleted, HistoryEventType::ActivityFailed],
                true,
            ) && ($event->payload['sequence'] ?? null) === $sequence
        );

        return $event;
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

    private function timerFiredEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::TimerFired
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

        $fallbackClass = is_string($event?->payload['exception_class'] ?? null)
            ? $event->payload['exception_class']
            : RuntimeException::class;
        $fallbackMessage = is_string($event?->payload['message'] ?? null)
            ? $event->payload['message']
            : 'Activity failed';
        $fallbackCode = is_int($event?->payload['code'] ?? null)
            ? $event->payload['code']
            : 0;

        return FailureFactory::restore($payload, $fallbackClass, $fallbackMessage, $fallbackCode);
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
                }
            } catch (Throwable $throwable) {
                /** @var WorkflowFailure $failure */
                $failure = WorkflowFailure::query()->create(array_merge(
                    FailureFactory::make($throwable),
                    [
                        'workflow_run_id' => $run->id,
                        'source_kind' => 'workflow_command',
                        'source_id' => $command?->id ?? $update->id,
                        'propagation_kind' => 'update',
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
                    'exception_class' => $failure->exception_class,
                    'message' => $failure->message,
                    'code' => $throwable->getCode(),
                    'exception' => FailureFactory::payload($throwable),
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
    ): WorkflowHistoryEvent {
        $existingEvent = $this->conditionWaitOpenedEvent($run, $sequence);

        if ($existingEvent !== null) {
            return $existingEvent;
        }

        return WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitOpened, array_filter([
            'condition_wait_id' => $waitId,
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
    ): WorkflowHistoryEvent {
        $existingEvent = $this->conditionWaitResolutionEvent($run, $sequence);

        if ($existingEvent !== null) {
            return $existingEvent;
        }

        return WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitTimedOut, array_filter([
            'condition_wait_id' => $waitId,
            'sequence' => $sequence,
            'timer_id' => $timer?->id,
            'timeout_seconds' => $timeoutSeconds,
        ], static fn (mixed $value): bool => $value !== null), $task);
    }

    private function cancelConditionTimeout(WorkflowRun $run, WorkflowTimer $timer): void
    {
        if ($timer->status !== TimerStatus::Pending) {
            return;
        }

        $timer->forceFill([
            'status' => TimerStatus::Cancelled,
        ])->save();

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
        $this->syncWorkflowCursor(
            $workflow,
            $this->visibleSequenceForCurrent($run, $current, $sequence),
        );
    }

    private function visibleSequenceForCurrent(
        WorkflowRun $run,
        mixed $current,
        int $sequence,
    ): int {
        return match (true) {
            $current instanceof ActivityCall => (
                $this->activityCompletionEvent($run, $sequence) !== null
                || $run->activityExecutions->firstWhere('sequence', $sequence) instanceof ActivityExecution
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
                $this->timerFiredEvent($run, $sequence) !== null
                || $run->timers->firstWhere('sequence', $sequence) instanceof WorkflowTimer
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
                || ChildRunHistory::childRunForSequence($run, $sequence) instanceof WorkflowRun
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
                    $this->activityCompletionEvent($run, $itemSequence) !== null
                    || $run->activityExecutions->firstWhere('sequence', $itemSequence) instanceof ActivityExecution
                ) {
                    return $sequence + $groupSize;
                }

                continue;
            }

            if (
                ChildRunHistory::scheduledEventForSequence($run, $itemSequence) !== null
                || ChildRunHistory::startedEventForSequence($run, $itemSequence) !== null
                || ChildRunHistory::resolutionEventForSequence($run, $itemSequence) !== null
                || ChildRunHistory::childRunForSequence($run, $itemSequence) instanceof WorkflowRun
            ) {
                return $sequence + $groupSize;
            }
        }

        return $sequence;
    }
}
