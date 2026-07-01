<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Exceptions\UnsupportedWorkflowYieldException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Workflow;

final class QueryStateReplayer
{
    public function query(WorkflowRun $run, string $method, array $arguments = []): mixed
    {
        $workflow = $this->replay($run);
        $workflow->setCommandDispatchEnabled(false);
        $parameters = $workflow->resolveMethodDependencies($arguments, new ReflectionMethod($workflow, $method));

        return $workflow->{$method}(...$parameters);
    }

    public function replay(WorkflowRun $run): \Workflow\V2\Workflow
    {
        return $this->replayState($run)
->workflow;
    }

    public function replayState(WorkflowRun $run): ReplayState
    {
        $this->loadReplayRelations($run);

        $workflowClass = WorkflowDefinitionFingerprint::resolveClassForRun($run);
        $workflow = new $workflowClass($run);
        $this->syncWorkflowCursor($workflow, 1);
        $entryMethod = EntryMethod::forWorkflow($workflow);
        $arguments = $workflow->resolveMethodDependencies($run->workflowArguments(), $entryMethod);
        $workflowExecution = WorkflowExecution::start($workflow, $arguments, $run->started_at);
        $historySequencesByPosition = $this->historySequencesByReplayPosition($run);

        if (! $workflowExecution->valid()) {
            $this->syncWorkflowCursor($workflow, 0);
            return new ReplayState($workflow, 0, null);
        }

        $current = $workflowExecution->current();
        $sequence = 1;

        while (true) {
            if (! $workflowExecution->valid()) {
                $this->syncWorkflowCursor($workflow, $sequence);
                return new ReplayState($workflow, $sequence, null);
            }

            if ($current instanceof LocalActivityCall) {
                $historySequence = $this->historySequenceForReplayPosition($historySequencesByPosition, $sequence);

                WorkflowStepHistory::assertCompatible($run, $historySequence, WorkflowStepHistory::LOCAL_ACTIVITY, [
                    'activity_type' => $current->activity,
                ]);

                $activityCompletion = $this->activityCompletionEvent($run, $historySequence);

                if ($activityCompletion !== null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    if ($activityCompletion->event_type === HistoryEventType::ActivityCompleted) {
                        $current = $workflowExecution->send(
                            $this->activityResult($activityCompletion, $run),
                            $activityCompletion->recorded_at,
                        );
                    } else {
                        $current = $workflowExecution->throw(
                            $this->activityException($activityCompletion, null, $run),
                            $activityCompletion->recorded_at,
                        );
                    }

                    ++$sequence;

                    continue;
                }

                if ($this->activityOpenEvent($run, $historySequence) !== null) {
                    $this->applyRecordedUpdates($run, $workflow, $historySequence);
                    $this->syncWorkflowCursor($workflow, $sequence + 1);

                    return new ReplayState($workflow, $sequence, $current);
                }

                $this->applyRecordedUpdates($run, $workflow, $historySequence);
                $this->syncWorkflowCursor($workflow, $sequence + 1);

                return new ReplayState($workflow, $sequence, $current);
            }

            if ($current instanceof ActivityCall) {
                $historySequence = $this->historySequenceForReplayPosition($historySequencesByPosition, $sequence);

                WorkflowStepHistory::assertCompatible($run, $historySequence, WorkflowStepHistory::ACTIVITY, [
                    'activity_type' => $current->activity,
                ]);

                $activityCompletion = $this->activityCompletionEvent($run, $historySequence);

                if ($activityCompletion !== null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    if ($activityCompletion->event_type === HistoryEventType::ActivityCompleted) {
                        $current = $workflowExecution->send(
                            $this->activityResult($activityCompletion, $run),
                            $activityCompletion->recorded_at,
                        );
                    } else {
                        $current = $workflowExecution->throw(
                            $this->activityException($activityCompletion, null, $run),
                            $activityCompletion->recorded_at,
                        );
                    }

                    ++$sequence;

                    continue;
                }

                if ($this->activityOpenEvent($run, $historySequence) !== null) {
                    $this->applyRecordedUpdates($run, $workflow, $historySequence);
                    $this->syncWorkflowCursor($workflow, $sequence + 1);

                    return new ReplayState($workflow, $sequence, $current);
                }

                /** @var ActivityExecution|null $execution */
                $execution = $run->activityExecutions->firstWhere('sequence', $historySequence);

                if ($execution === null || in_array(
                    $execution->status,
                    [ActivityStatus::Pending, ActivityStatus::Running],
                    true
                )) {
                    if ($execution !== null) {
                        WorkflowStepHistory::assertTypedHistoryRecorded($run, $historySequence, WorkflowStepHistory::ACTIVITY);
                    }

                    $this->applyRecordedUpdates($run, $workflow, $historySequence);
                    $this->syncWorkflowCursor($workflow, $sequence + 1);

                    return new ReplayState($workflow, $sequence, $current);
                }

                WorkflowStepHistory::assertTypedHistoryRecorded($run, $historySequence, WorkflowStepHistory::ACTIVITY);

                $this->syncWorkflowCursor($workflow, $sequence + 1);
                if ($execution->status === ActivityStatus::Completed) {
                    $current = $workflowExecution->send($execution->activityResult(), $execution->closed_at);
                } else {
                    $current = $workflowExecution->throw(
                        $this->activityException(null, $execution, $run),
                        $execution->closed_at,
                    );
                }

                ++$sequence;

                continue;
            }

            if ($current instanceof AwaitCall || $current instanceof AwaitWithTimeoutCall) {
                $historySequence = $this->historySequenceForReplayPosition($historySequencesByPosition, $sequence);

                $this->applyRecordedUpdates($run, $workflow, $historySequence);
                ConditionWaits::assertReplayCompatible($run, $historySequence, $current);

                $resolutionEvent = $this->conditionWaitResolutionEvent($run, $historySequence);

                if ($resolutionEvent === null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return new ReplayState($workflow, $sequence, $current);
                }

                $this->syncWorkflowCursor($workflow, $sequence + 1);
                $current = $workflowExecution->send(
                    $resolutionEvent->event_type === HistoryEventType::ConditionWaitSatisfied,
                    $resolutionEvent->recorded_at,
                );

                ++$sequence;

                continue;
            }

            if ($current instanceof TimerCall) {
                $historySequence = $this->historySequenceForReplayPosition($historySequencesByPosition, $sequence);

                WorkflowStepHistory::assertCompatible($run, $historySequence, WorkflowStepHistory::TIMER);

                $timerFired = $this->timerFiredEvent($run, $historySequence);

                if ($timerFired !== null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    $current = $workflowExecution->send(true, $timerFired->recorded_at);

                    ++$sequence;

                    continue;
                }

                if ($this->timerScheduledEvent($run, $historySequence) !== null) {
                    $this->applyRecordedUpdates($run, $workflow, $historySequence);
                    $this->syncWorkflowCursor($workflow, $sequence + 1);

                    return new ReplayState($workflow, $sequence, $current);
                }

                $timer = $run->timers->firstWhere('sequence', $historySequence);

                if ($timer === null || $timer->status === TimerStatus::Pending) {
                    if ($timer !== null) {
                        WorkflowStepHistory::assertTypedHistoryRecorded($run, $historySequence, WorkflowStepHistory::TIMER);
                    }

                    $this->applyRecordedUpdates($run, $workflow, $historySequence);
                    $this->syncWorkflowCursor($workflow, $sequence + 1);

                    return new ReplayState($workflow, $sequence, $current);
                }

                WorkflowStepHistory::assertTypedHistoryRecorded($run, $historySequence, WorkflowStepHistory::TIMER);

                $this->syncWorkflowCursor($workflow, $sequence + 1);
                $current = $workflowExecution->send(true, $timer->fired_at);

                ++$sequence;

                continue;
            }

            if ($current instanceof SideEffectCall) {
                $historySequence = $this->historySequenceForReplayPosition($historySequencesByPosition, $sequence);

                $this->applyRecordedUpdates($run, $workflow, $historySequence);
                WorkflowStepHistory::assertCompatible($run, $historySequence, WorkflowStepHistory::SIDE_EFFECT);

                $sideEffectEvent = $this->sideEffectEvent($run, $historySequence);

                if ($sideEffectEvent === null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return new ReplayState($workflow, $sequence, $current);
                }

                $this->syncWorkflowCursor($workflow, $sequence + 1);
                $current = $workflowExecution->send(
                    $this->sideEffectResult($sideEffectEvent, $run),
                    $sideEffectEvent->recorded_at,
                );

                ++$sequence;

                continue;
            }

            if ($current instanceof VersionCall) {
                $historySequence = $this->historySequenceForReplayPosition($historySequencesByPosition, $sequence);

                $this->applyRecordedUpdates($run, $workflow, $historySequence);
                WorkflowStepHistory::assertCompatible($run, $historySequence, WorkflowStepHistory::VERSION_MARKER, [
                    'change_id' => $current->changeId,
                ]);

                $versionEvent = $this->versionMarkerEvent($run, $historySequence);
                $resolution = VersionResolver::resolve($run, $versionEvent, $current, $historySequence);

                $this->syncWorkflowCursor($workflow, $sequence + ($resolution->advancesSequence ? 1 : 0));
                $current = $workflowExecution->send(
                    $current->resolveValue($resolution->version),
                    $versionEvent?->recorded_at
                );

                if ($resolution->advancesSequence) {
                    ++$sequence;
                }

                continue;
            }

            if ($current instanceof UpsertSearchAttributesCall) {
                $historySequence = $this->historySequenceForReplayPosition($historySequencesByPosition, $sequence);

                $this->applyRecordedUpdates($run, $workflow, $historySequence);
                WorkflowStepHistory::assertCompatible($run, $historySequence, WorkflowStepHistory::SEARCH_ATTRIBUTES_UPSERT);

                $upsertEvent = $this->searchAttributesUpsertedEvent($run, $historySequence);

                if ($upsertEvent === null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return new ReplayState($workflow, $sequence, $current);
                }

                $this->syncWorkflowCursor($workflow, $sequence + 1);
                $current = $workflowExecution->send(null, $upsertEvent->recorded_at);

                ++$sequence;

                continue;
            }

            if ($current instanceof SignalCall) {
                $historySequence = $this->historySequenceForReplayPosition($historySequencesByPosition, $sequence);

                $this->applyRecordedUpdates($run, $workflow, $historySequence);
                WorkflowStepHistory::assertCompatible($run, $historySequence, WorkflowStepHistory::SIGNAL_WAIT, [
                    'signal_name' => $current->name,
                ]);

                $signalEvent = $this->signalResolutionEvent($run, $historySequence, $current);

                if ($signalEvent !== null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    $current = $workflowExecution->send(
                        $this->signalValue($signalEvent, $run),
                        $signalEvent->recorded_at,
                    );

                    ++$sequence;

                    continue;
                }

                $signalTimeoutFired = $current->timeoutSeconds !== null
                    ? $this->signalTimeoutFiredEvent($run, $historySequence, $current->name)
                    : null;

                if ($signalTimeoutFired !== null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    $current = $workflowExecution->send(null, $signalTimeoutFired->recorded_at);

                    ++$sequence;

                    continue;
                }

                $this->syncWorkflowCursor($workflow, $sequence + 1);
                return new ReplayState($workflow, $sequence, $current);
            }

            if ($current instanceof ChildWorkflowCall) {
                $historySequence = $this->historySequenceForReplayPosition($historySequencesByPosition, $sequence);

                $this->applyRecordedUpdates($run, $workflow, $historySequence);
                WorkflowStepHistory::assertCompatible($run, $historySequence, WorkflowStepHistory::CHILD_WORKFLOW, [
                    'child_workflow_type' => $current->workflow,
                ]);

                $resolutionEvent = ChildRunHistory::resolutionEventForSequence($run, $historySequence);
                $childRun = ChildRunHistory::childRunForSequence($run, $historySequence);

                if ($resolutionEvent !== null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    if ($resolutionEvent->event_type === HistoryEventType::ChildRunCompleted) {
                        $current = $workflowExecution->send(
                            ChildRunHistory::outputForResolution($resolutionEvent, $childRun),
                            $resolutionEvent->recorded_at,
                        );
                    } else {
                        $current = $workflowExecution->throw(
                            ChildRunHistory::exceptionForResolution($resolutionEvent, $childRun),
                            $resolutionEvent->recorded_at,
                        );
                    }

                    ++$sequence;

                    continue;
                }

                // History is authoritative: if the parent run has already
                // committed a ChildWorkflowScheduled/ChildRunStarted event for
                // this sequence but no resolution event yet, the workflow
                // must stay suspended at the child call even if the child's
                // DB row was updated to a terminal status (by a racing child
                // worker, by a cross-run repair, or by a test that manipulates
                // the child directly). Falling back to child DB state here
                // would leak non-history state into query replay.
                if (ChildRunHistory::parentHistoryBlocksResolutionWithoutEvent($run, $historySequence)) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return new ReplayState($workflow, $sequence, $current);
                }

                $childStatus = $childRun instanceof WorkflowRun
                    ? ChildRunHistory::resolvedStatus(null, $childRun)
                    : null;

                if ($childRun instanceof WorkflowRun && $childStatus instanceof RunStatus && ! in_array($childStatus, [
                    RunStatus::Pending,
                    RunStatus::Running,
                    RunStatus::Waiting,
                ], true)) {
                    WorkflowStepHistory::assertTypedHistoryRecorded(
                        $run,
                        $historySequence,
                        WorkflowStepHistory::CHILD_WORKFLOW
                    );

                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    if ($childStatus === RunStatus::Completed) {
                        $current = $workflowExecution->send(
                            ChildRunHistory::outputForChildRun($childRun),
                            $childRun->closed_at,
                        );
                    } else {
                        $current = $workflowExecution->throw(
                            ChildRunHistory::exceptionForChildRun($childRun),
                            $childRun->closed_at,
                        );
                    }

                    ++$sequence;

                    continue;
                }

                if ($childRun instanceof WorkflowRun) {
                    WorkflowStepHistory::assertTypedHistoryRecorded(
                        $run,
                        $historySequence,
                        WorkflowStepHistory::CHILD_WORKFLOW
                    );
                }

                $this->syncWorkflowCursor($workflow, $sequence + 1);
                return new ReplayState($workflow, $sequence, $current);
            }

            if ($current instanceof AllCall) {
                $historySequence = $this->historySequenceForReplayPosition($historySequencesByPosition, $sequence);

                $this->applyRecordedUpdates($run, $workflow, $historySequence);

                $leafDescriptors = $current->leafDescriptors($historySequence);
                $groupSize = count($leafDescriptors);

                if ($groupSize === 0) {
                    $this->syncWorkflowCursor($workflow, $sequence);
                    $current = $workflowExecution->send($current->nestedResults([]));

                    continue;
                }

                $pending = false;
                $results = [];
                $failure = null;

                WorkflowStepHistory::assertParallelGroupCompatible($run, $historySequence, $leafDescriptors);

                foreach ($leafDescriptors as $descriptor) {
                    $call = $descriptor['call'];
                    $offset = $descriptor['offset'];
                    $itemPosition = $sequence + $offset;
                    $itemSequence = $this->historySequenceForReplayPosition(
                        $historySequencesByPosition,
                        $itemPosition,
                    );
                    WorkflowStepHistory::assertCompatible(
                        $run,
                        $itemSequence,
                        $call instanceof ActivityCall
                            ? WorkflowStepHistory::ACTIVITY
                            : WorkflowStepHistory::CHILD_WORKFLOW,
                        $call instanceof ActivityCall
                            ? ['activity_type' => $call->activity]
                            : ['child_workflow_type' => $call->workflow],
                    );

                    if ($call instanceof ActivityCall) {
                        $activityCompletion = $this->activityCompletionEvent($run, $itemSequence);

                        if ($activityCompletion !== null) {
                            if ($activityCompletion->event_type === HistoryEventType::ActivityCompleted) {
                                $results[$offset] = $this->activityResult($activityCompletion, $run);

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

                        if ($this->activityOpenEvent($run, $itemSequence) !== null) {
                            $pending = true;

                            continue;
                        }

                        /** @var ActivityExecution|null $execution */
                        $execution = $run->activityExecutions->firstWhere('sequence', $itemSequence);

                        if (! $execution instanceof ActivityExecution) {
                            $pending = true;

                            continue;
                        }

                        if (in_array($execution->status, [
                            ActivityStatus::Pending,
                            ActivityStatus::Running,
                        ], true)) {
                            WorkflowStepHistory::assertTypedHistoryRecorded(
                                $run,
                                $itemSequence,
                                WorkflowStepHistory::ACTIVITY,
                            );

                            $pending = true;

                            continue;
                        }

                        WorkflowStepHistory::assertTypedHistoryRecorded(
                            $run,
                            $itemSequence,
                            WorkflowStepHistory::ACTIVITY,
                        );

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

                    $childStatus = $childRun instanceof WorkflowRun
                        ? ChildRunHistory::resolvedStatus(null, $childRun)
                        : null;

                    if ($childRun instanceof WorkflowRun && $childStatus instanceof RunStatus && ! in_array(
                        $childStatus,
                        [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting],
                        true
                    )) {
                        WorkflowStepHistory::assertTypedHistoryRecorded(
                            $run,
                            $itemSequence,
                            WorkflowStepHistory::CHILD_WORKFLOW,
                        );

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

                        continue;
                    }

                    if (ChildRunHistory::parentHistoryBlocksResolutionWithoutEvent($run, $itemSequence)) {
                        $pending = true;

                        continue;
                    }

                    if ($childRun instanceof WorkflowRun) {
                        WorkflowStepHistory::assertTypedHistoryRecorded(
                            $run,
                            $itemSequence,
                            WorkflowStepHistory::CHILD_WORKFLOW,
                        );
                    }

                    $pending = true;
                }

                if ($failure !== null) {
                    $this->syncWorkflowCursor($workflow, $sequence + $groupSize);
                    $failureTime = isset($failure['recorded_at']) && is_int(
                        $failure['recorded_at']
                    ) && $failure['recorded_at'] !== PHP_INT_MAX
                        ? \Carbon\Carbon::createFromTimestampMs($failure['recorded_at'])
                        : null;
                    $current = $workflowExecution->throw($failure['exception'], $failureTime);
                    $sequence += $groupSize;

                    continue;
                }

                if ($pending) {
                    $this->syncWorkflowCursor($workflow, $sequence + $groupSize);
                    return new ReplayState($workflow, $sequence, $current);
                }

                ksort($results);
                $this->syncWorkflowCursor($workflow, $sequence + $groupSize);
                $current = $workflowExecution->send($current->nestedResults(array_values($results)));
                $sequence += $groupSize;

                continue;
            }

            if ($current instanceof ContinueAsNewCall) {
                WorkflowStepHistory::assertCompatible($run, $sequence, WorkflowStepHistory::CONTINUE_AS_NEW);

                $this->syncWorkflowCursor($workflow, $sequence);
                return new ReplayState($workflow, $sequence, $current);
            }

            $this->syncWorkflowCursor($workflow, $sequence);
            throw new UnsupportedWorkflowYieldException(sprintf(
                'Workflow %s yielded %s. v2 currently supports activity(), child(), async(), all(), parallel(), await(), signal(), timer(), sideEffect(), continueAsNew(), getVersion(), patched(), deprecatePatch(), upsertMemo(), and upsertSearchAttributes() only.',
                $run->workflow_class,
                get_debug_type($current),
            ));
        }
    }

    private function loadReplayRelations(WorkflowRun $run): void
    {
        if ($this->hasReplayRelationsLoaded($run)) {
            return;
        }

        $run->loadMissing([
            'instance',
            'activityExecutions',
            'timers',
            'failures',
            'commands',
            'signals',
            'historyEvents',
            'childLinks.childRun.instance.currentRun',
            'childLinks.childRun.failures',
            'childLinks.childRun.historyEvents',
        ]);
    }

    private function hasReplayRelationsLoaded(WorkflowRun $run): bool
    {
        foreach ([
            'instance',
            'activityExecutions',
            'timers',
            'failures',
            'commands',
            'signals',
            'historyEvents',
        ] as $relation) {
            if (! $run->relationLoaded($relation)) {
                return false;
            }
        }

        if (! $run->relationLoaded('childLinks')) {
            return false;
        }

        $childLinks = $run->getRelation('childLinks');

        if (! is_iterable($childLinks)) {
            return false;
        }

        foreach ($childLinks as $childLink) {
            if (! $childLink instanceof Model || ! $childLink->relationLoaded('childRun')) {
                return false;
            }

            $childRun = $childLink->getRelation('childRun');

            if (! $childRun instanceof WorkflowRun) {
                return false;
            }

            foreach (['instance', 'failures', 'historyEvents'] as $relation) {
                if (! $childRun->relationLoaded($relation)) {
                    return false;
                }
            }

            $instance = $childRun->getRelation('instance');

            if (! $instance instanceof Model || ! $instance->relationLoaded('currentRun')) {
                return false;
            }
        }

        return true;
    }

    private function signalResolutionEvent(
        WorkflowRun $run,
        int $sequence,
        SignalCall $signalCall,
    ): ?WorkflowHistoryEvent {
        $applied = $this->appliedSignalEvent($run, $sequence, $signalCall);
        $received = $this->receivedSignalEvent($run, $sequence, $signalCall);

        if ($applied !== null && $this->signalEventCarriesPayload($applied, $run)) {
            return $applied;
        }

        return $received ?? $applied;
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

    private function receivedSignalEvent(
        WorkflowRun $run,
        int $sequence,
        SignalCall $signalCall,
    ): ?WorkflowHistoryEvent {
        $waitIds = $this->signalWaitIdsForSequence($run, $sequence, $signalCall->name);

        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static function (WorkflowHistoryEvent $event) use ($sequence, $signalCall, $waitIds): bool {
                if (
                    $event->event_type !== HistoryEventType::SignalReceived
                    || ($event->payload['signal_name'] ?? null) !== $signalCall->name
                ) {
                    return false;
                }

                if (($event->payload['workflow_sequence'] ?? null) === $sequence) {
                    return true;
                }

                $signalWaitId = $event->payload['signal_wait_id'] ?? null;

                return is_string($signalWaitId) && isset($waitIds[$signalWaitId]);
            }
        );

        return $event;
    }

    private function signalEventCarriesPayload(WorkflowHistoryEvent $event, ?WorkflowRun $run): bool
    {
        $payload = is_array($event->payload) ? $event->payload : [];

        return array_key_exists('value', $payload)
            || array_key_exists('arguments', $payload)
            || $this->signalRecordForEvent($event, $run) instanceof WorkflowSignal;
    }

    /**
     * @return array<string, true>
     */
    private function signalWaitIdsForSequence(WorkflowRun $run, int $sequence, string $signalName): array
    {
        $waitIds = [];

        foreach ($run->historyEvents as $event) {
            if (
                $event->event_type !== HistoryEventType::SignalWaitOpened
                || ($event->payload['sequence'] ?? null) !== $sequence
                || ($event->payload['signal_name'] ?? null) !== $signalName
            ) {
                continue;
            }

            $signalWaitId = $this->stringValue($event->payload['signal_wait_id'] ?? null);

            if ($signalWaitId !== null) {
                $waitIds[$signalWaitId] = true;
            }
        }

        return $waitIds;
    }

    private function signalValue(WorkflowHistoryEvent $event, ?WorkflowRun $run): mixed
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $serialized = $payload['value'] ?? null;

        if (is_string($serialized)) {
            return WorkflowPayloadDecoder::unserializeWithRun($serialized, $run, [
                'workflow_id' => $run?->workflow_instance_id,
                'run_id' => $run?->id,
                'event_id' => $event->id,
                'signal_name' => $this->stringValue($payload['signal_name'] ?? null),
            ]);
        }

        if (array_key_exists('value', $payload)) {
            $decoded = $this->payloadValue(
                $payload['value'],
                $this->stringValue($payload['payload_codec'] ?? null),
                $run,
            );

            if ($decoded['available']) {
                return $decoded['value'];
            }
        }

        $arguments = $payload['arguments'] ?? null;

        if ($arguments !== null) {
            return $this->signalValueFromArguments(
                $arguments,
                $this->stringValue($payload['payload_codec'] ?? null),
                $run,
            );
        }

        $signal = $this->signalRecordForEvent($event, $run);

        if (! $signal instanceof WorkflowSignal) {
            return null;
        }

        return $this->signalValueFromArguments(
            $signal->arguments,
            $this->stringValue($signal->payload_codec ?? null),
            $run,
        );
    }

    /**
     * @return array{available: bool, value: mixed}
     */
    private function payloadValue(mixed $payload, ?string $payloadCodec, ?WorkflowRun $run): array
    {
        $codec = $payloadCodec
            ?? $this->stringValue(is_array($payload) ? ($payload['codec'] ?? null) : null)
            ?? $this->stringValue($run?->payload_codec ?? null);
        $serialized = ExternalPayloads::payloadBlob(
            $payload,
            $codec,
            is_string($run?->namespace) ? $run->namespace : null,
        );

        if ($serialized === null) {
            return [
                'available' => false,
                'value' => null,
            ];
        }

        return [
            'available' => true,
            'value' => $codec !== null
                ? Serializer::unserializeWithCodec($codec, $serialized)
                : Serializer::unserialize($serialized),
        ];
    }

    private function signalRecordForEvent(WorkflowHistoryEvent $event, ?WorkflowRun $run): ?WorkflowSignal
    {
        $signals = $run?->signals ?? collect();
        $signalId = $this->stringValue($event->payload['signal_id'] ?? null);

        if ($signalId !== null) {
            /** @var WorkflowSignal|null $signal */
            $signal = $signals->firstWhere('id', $signalId);

            if ($signal instanceof WorkflowSignal) {
                return $signal;
            }
        }

        $commandId = $this->stringValue($event->payload['workflow_command_id'] ?? null)
            ?? $this->stringValue($event->workflow_command_id ?? null);

        if ($commandId !== null) {
            /** @var WorkflowSignal|null $signal */
            $signal = $signals->firstWhere('workflow_command_id', $commandId);

            if ($signal instanceof WorkflowSignal) {
                return $signal;
            }
        }

        $signalName = $this->stringValue($event->payload['signal_name'] ?? null);
        $signalWaitId = $this->stringValue($event->payload['signal_wait_id'] ?? null);

        if ($signalName === null || $signalWaitId === null) {
            return null;
        }

        /** @var WorkflowSignal|null $signal */
        $signal = $signals->first(static fn (mixed $candidate): bool => $candidate instanceof WorkflowSignal
            && $candidate->signal_name === $signalName
            && $candidate->signal_wait_id === $signalWaitId);

        return $signal instanceof WorkflowSignal ? $signal : null;
    }

    private function signalValueFromArguments(mixed $payload, ?string $payloadCodec, ?WorkflowRun $run): mixed
    {
        $codec = $payloadCodec ?? $this->stringValue($run?->payload_codec ?? null);
        $serialized = ExternalPayloads::payloadBlob(
            $payload,
            $codec,
            is_string($run?->namespace) ? $run->namespace : null,
        );

        if ($serialized === null) {
            return true;
        }

        $arguments = $codec !== null
            ? Serializer::unserializeWithCodec($codec, $serialized)
            : $this->unserializeWithRun($serialized, $run);

        if (! is_array($arguments)) {
            return $arguments;
        }

        $arguments = array_values($arguments);

        if ($arguments === []) {
            return true;
        }

        return count($arguments) === 1 ? $arguments[0] : $arguments;
    }

    private function activityResult(WorkflowHistoryEvent $event, ?WorkflowRun $run): mixed
    {
        $codec = $this->stringValue($event->payload['payload_codec'] ?? null);
        $serialized = ExternalPayloads::payloadBlob(
            $event->payload['result'] ?? null,
            $codec ?? $this->stringValue($run?->payload_codec ?? null),
            is_string($run?->namespace) ? $run->namespace : null,
        );

        if ($serialized === null) {
            return null;
        }

        if ($codec !== null) {
            return Serializer::unserializeWithCodec($codec, $serialized);
        }

        return $this->unserializeWithRun($serialized, $run);
    }

    private function sideEffectResult(WorkflowHistoryEvent $event, ?WorkflowRun $run): mixed
    {
        $serialized = ExternalPayloads::payloadBlob(
            $event->payload['result'] ?? null,
            $this->stringValue($event->payload['payload_codec'] ?? null) ?? $this->stringValue($run?->payload_codec ?? null),
            is_string($run?->namespace) ? $run->namespace : null,
        );

        if ($serialized === null) {
            return null;
        }

        return $this->unserializeWithRun($serialized, $run);
    }

    private function unserializeWithRun(string $serialized, ?WorkflowRun $run): mixed
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

    private function searchAttributesUpsertedEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::SearchAttributesUpserted
                && ($event->payload['sequence'] ?? null) === $sequence
        );

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

    /**
     * @param array<int, int> $historySequencesByPosition
     */
    private function historySequenceForReplayPosition(array $historySequencesByPosition, int $sequence): int
    {
        return $historySequencesByPosition[$sequence] ?? $sequence;
    }

    /**
     * Worker-protocol history can contain control-plane events before workflow
     * commands, so replay position 1 may be persisted as a later durable
     * sequence. Query replay needs the persisted command sequence when reading
     * command outcomes from history.
     *
     * @return array<int, int>
     */
    private function historySequencesByReplayPosition(WorkflowRun $run): array
    {
        $positions = [];
        $seen = [];

        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            $eventType = $event->event_type instanceof HistoryEventType
                ? $event->event_type->value
                : $this->stringValue($event->event_type);

            if (! $this->hasWorkflowCommandSequence($eventType)) {
                continue;
            }

            $sequence = $this->eventSequence($event);

            if ($sequence === null || isset($seen[$sequence])) {
                continue;
            }

            $seen[$sequence] = true;
            $positions[count($positions) + 1] = $sequence;
        }

        return $positions;
    }

    private function hasWorkflowCommandSequence(?string $type): bool
    {
        return in_array($type, [
            HistoryEventType::ActivityScheduled->value,
            HistoryEventType::ActivityStarted->value,
            HistoryEventType::ActivityHeartbeatRecorded->value,
            HistoryEventType::ActivityRetryScheduled->value,
            HistoryEventType::ActivityCompleted->value,
            HistoryEventType::ActivityFailed->value,
            HistoryEventType::ActivityCancelled->value,
            HistoryEventType::ActivityTimedOut->value,
            HistoryEventType::TimerScheduled->value,
            HistoryEventType::TimerCancelled->value,
            HistoryEventType::TimerFired->value,
            HistoryEventType::ConditionWaitOpened->value,
            HistoryEventType::ConditionWaitSatisfied->value,
            HistoryEventType::ConditionWaitTimedOut->value,
            HistoryEventType::SignalWaitOpened->value,
            HistoryEventType::SignalApplied->value,
            HistoryEventType::ChildWorkflowScheduled->value,
            HistoryEventType::ChildRunStarted->value,
            HistoryEventType::ChildRunCompleted->value,
            HistoryEventType::ChildRunFailed->value,
            HistoryEventType::ChildRunCancelled->value,
            HistoryEventType::ChildRunTerminated->value,
            HistoryEventType::SideEffectRecorded->value,
            HistoryEventType::VersionMarkerRecorded->value,
            HistoryEventType::SearchAttributesUpserted->value,
        ], true);
    }

    private function eventSequence(WorkflowHistoryEvent $event): ?int
    {
        return $this->intValue($event->payload['sequence'] ?? null)
            ?? $this->intValue($event->payload['workflow_sequence'] ?? null)
            ?? $this->intValue($event->sequence);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value)
            ? (int) $value
            : null;
    }

    private static function isInternalTimeoutTimerKind(mixed $value): bool
    {
        return in_array($value, ['condition_timeout', 'signal_timeout'], true);
    }

    private function activityException(
        ?WorkflowHistoryEvent $event = null,
        ?ActivityExecution $execution = null,
        ?WorkflowRun $run = null,
    ): Throwable {
        $payload = is_array($event?->payload['exception'] ?? null)
            ? $event->payload['exception']
            : (is_string($execution?->exception)
                ? $this->unserializeExceptionWithRun($execution->exception, $run)
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
                default => 'Activity failed',
            };
        $fallbackCode = is_int($event?->payload['code'] ?? null)
            ? $event->payload['code']
            : 0;

        return FailureFactory::restoreForReplay($payload, $fallbackClass, $fallbackMessage, $fallbackCode);
    }

    private function unserializeExceptionWithRun(string $serialized, ?WorkflowRun $run): mixed
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

    private function applyRecordedUpdates(
        WorkflowRun $run,
        \Workflow\V2\Workflow $workflow,
        int $sequence,
    ): void {
        $events = $run->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::UpdateApplied
                    && ($event->payload['sequence'] ?? null) === $sequence
            )
            ->sortBy('sequence');

        foreach ($events as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            /** @var WorkflowCommand|null $command */
            $command = $event->workflow_command_id === null
                ? null
                : $run->commands->firstWhere('id', $event->workflow_command_id);

            $method = $this->updateMethodName($workflow, $event, $command);

            if ($method === null) {
                throw new LogicException(sprintf(
                    'Workflow update event [%s] is missing an update method name.',
                    $event->id,
                ));
            }

            $arguments = $this->updateArguments($event, $command, $run);
            $parameters = $workflow->resolveMethodDependencies(
                $arguments,
                new ReflectionMethod($workflow, $method),
            );

            $workflow->{$method}(...$parameters);
        }
    }

    private function updateMethodName(
        \Workflow\V2\Workflow $workflow,
        WorkflowHistoryEvent $event,
        ?WorkflowCommand $command,
    ): ?string {
        $target = $event->payload['update_name'] ?? ($command === null
            ? null
            : WorkflowPayloadDecoder::commandTargetName($command, [
                'event_id' => $event->id,
                'update_name' => $this->stringValue($event->payload['update_name'] ?? null),
            ]));

        if (! is_string($target) || $target === '') {
            return null;
        }

        return WorkflowDefinition::resolveUpdateTarget($workflow::class, $target)['method'] ?? $target;
    }

    /**
     * @return array<int, mixed>
     */
    private function updateArguments(
        WorkflowHistoryEvent $event,
        ?WorkflowCommand $command,
        ?WorkflowRun $run,
    ): array {
        $serialized = $event->payload['arguments'] ?? null;

        if (is_string($serialized)) {
            $arguments = WorkflowPayloadDecoder::unserializeWithRun($serialized, $run, [
                'workflow_id' => $run?->workflow_instance_id,
                'run_id' => $run?->id,
                'event_id' => $event->id,
                'update_name' => $this->stringValue($event->payload['update_name'] ?? null),
                'workflow_command_id' => $command?->id,
            ]);

            return is_array($arguments)
                ? array_values($arguments)
                : [];
        }

        return $command === null
            ? []
            : WorkflowPayloadDecoder::commandArguments($command, [
                'workflow_id' => $run?->workflow_instance_id,
                'run_id' => $run?->id,
                'event_id' => $event->id,
                'update_name' => $this->stringValue($event->payload['update_name'] ?? null),
            ]);
    }

    private function syncWorkflowCursor(Workflow $workflow, int $visibleSequence): void
    {
        $workflow->syncExecutionCursor($visibleSequence);
        $workflow->setCommandDispatchEnabled(false);
    }
}
