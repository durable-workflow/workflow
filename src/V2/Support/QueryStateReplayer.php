<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Generator;
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
        $run->loadMissing([
            'instance',
            'activityExecutions',
            'timers',
            'failures',
            'commands',
            'historyEvents',
            'childLinks.childRun.instance.currentRun',
            'childLinks.childRun.failures',
            'childLinks.childRun.historyEvents',
        ]);

        $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        $workflow = new $workflowClass($run);
        $this->syncWorkflowCursor($workflow, 1);
        $arguments = $workflow->resolveMethodDependencies(
            $run->workflowArguments(),
            new ReflectionMethod($workflow, 'execute'),
        );
        $result = $workflow->execute(...$arguments);

        if (! $result instanceof Generator) {
            $this->syncWorkflowCursor($workflow, 0);
            return new ReplayState($workflow, 0, null);
        }

        $current = $result->current();
        $sequence = 1;

        while (true) {
            if (! $result->valid()) {
                $this->syncWorkflowCursor($workflow, $sequence);
                return new ReplayState($workflow, $sequence, null);
            }

            if ($current instanceof ActivityCall) {
                WorkflowStepHistory::assertCompatible($run, $sequence, WorkflowStepHistory::ACTIVITY);

                $activityCompletion = $this->activityCompletionEvent($run, $sequence);

                if ($activityCompletion !== null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    if ($activityCompletion->event_type === HistoryEventType::ActivityCompleted) {
                        $current = $result->send($this->activityResult($activityCompletion));
                    } else {
                        $current = $result->throw($this->activityException($activityCompletion, null, $run));
                    }

                    ++$sequence;

                    continue;
                }

                if ($this->activityOpenEvent($run, $sequence) !== null) {
                    $this->applyRecordedUpdates($run, $workflow, $sequence);
                    $this->syncWorkflowCursor($workflow, $sequence + 1);

                    return new ReplayState($workflow, $sequence, $current);
                }

                /** @var ActivityExecution|null $execution */
                $execution = $run->activityExecutions->firstWhere('sequence', $sequence);

                if ($execution === null || in_array(
                    $execution->status,
                    [ActivityStatus::Pending, ActivityStatus::Running],
                    true
                )) {
                    $this->applyRecordedUpdates($run, $workflow, $sequence);
                    $this->syncWorkflowCursor($workflow, $sequence + 1);

                    return new ReplayState($workflow, $sequence, $current);
                }

                $this->syncWorkflowCursor($workflow, $sequence + 1);
                if ($execution->status === ActivityStatus::Completed) {
                    $current = $result->send($execution->activityResult());
                } else {
                    $current = $result->throw($this->activityException(null, $execution, $run));
                }

                ++$sequence;

                continue;
            }

            if ($current instanceof AwaitCall || $current instanceof AwaitWithTimeoutCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);
                ConditionWaits::assertReplayCompatible($run, $sequence, $current);

                $resolutionEvent = $this->conditionWaitResolutionEvent($run, $sequence);

                if ($resolutionEvent === null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return new ReplayState($workflow, $sequence, $current);
                }

                $this->syncWorkflowCursor($workflow, $sequence + 1);
                $current = $result->send(
                    $resolutionEvent->event_type === HistoryEventType::ConditionWaitSatisfied
                );

                ++$sequence;

                continue;
            }

            if ($current instanceof TimerCall) {
                WorkflowStepHistory::assertCompatible($run, $sequence, WorkflowStepHistory::TIMER);

                if ($this->timerFiredEvent($run, $sequence) !== null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    $current = $result->send(true);

                    ++$sequence;

                    continue;
                }

                if ($this->timerScheduledEvent($run, $sequence) !== null) {
                    $this->applyRecordedUpdates($run, $workflow, $sequence);
                    $this->syncWorkflowCursor($workflow, $sequence + 1);

                    return new ReplayState($workflow, $sequence, $current);
                }

                $timer = $run->timers->firstWhere('sequence', $sequence);

                if ($timer === null || $timer->status === TimerStatus::Pending) {
                    $this->applyRecordedUpdates($run, $workflow, $sequence);
                    $this->syncWorkflowCursor($workflow, $sequence + 1);

                    return new ReplayState($workflow, $sequence, $current);
                }

                $this->syncWorkflowCursor($workflow, $sequence + 1);
                $current = $result->send(true);

                ++$sequence;

                continue;
            }

            if ($current instanceof SideEffectCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);
                WorkflowStepHistory::assertCompatible($run, $sequence, WorkflowStepHistory::SIDE_EFFECT);

                $sideEffectEvent = $this->sideEffectEvent($run, $sequence);

                if ($sideEffectEvent === null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return new ReplayState($workflow, $sequence, $current);
                }

                $this->syncWorkflowCursor($workflow, $sequence + 1);
                $current = $result->send($this->sideEffectResult($sideEffectEvent));

                ++$sequence;

                continue;
            }

            if ($current instanceof VersionCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);
                WorkflowStepHistory::assertCompatible($run, $sequence, WorkflowStepHistory::VERSION_MARKER);

                $resolution = VersionResolver::resolve(
                    $run,
                    $this->versionMarkerEvent($run, $sequence),
                    $current,
                    $sequence,
                );

                $this->syncWorkflowCursor($workflow, $sequence + ($resolution->advancesSequence ? 1 : 0));
                $current = $result->send($resolution->version);

                if ($resolution->advancesSequence) {
                    ++$sequence;
                }

                continue;
            }

            if ($current instanceof SignalCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);
                WorkflowStepHistory::assertCompatible($run, $sequence, WorkflowStepHistory::SIGNAL_WAIT);

                $signalEvent = $this->appliedSignalEvent($run, $sequence, $current);

                if ($signalEvent === null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return new ReplayState($workflow, $sequence, $current);
                }

                $this->syncWorkflowCursor($workflow, $sequence + 1);
                $current = $result->send($this->signalValue($signalEvent));

                ++$sequence;

                continue;
            }

            if ($current instanceof ChildWorkflowCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);
                WorkflowStepHistory::assertCompatible($run, $sequence, WorkflowStepHistory::CHILD_WORKFLOW);

                $resolutionEvent = ChildRunHistory::resolutionEventForSequence($run, $sequence);
                $childRun = ChildRunHistory::childRunForSequence($run, $sequence);

                if ($resolutionEvent !== null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    if ($resolutionEvent->event_type === HistoryEventType::ChildRunCompleted) {
                        $current = $result->send(ChildRunHistory::outputForResolution($resolutionEvent, $childRun));
                    } else {
                        $current = $result->throw(ChildRunHistory::exceptionForResolution($resolutionEvent, $childRun));
                    }

                    ++$sequence;

                    continue;
                }

                if (ChildRunHistory::parentHistoryBlocksResolutionWithoutEvent($run, $sequence)) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return new ReplayState($workflow, $sequence, $current);
                }

                if ($childRun === null) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return new ReplayState($workflow, $sequence, $current);
                }

                $childStatus = ChildRunHistory::resolvedStatus(null, $childRun);

                if ($childStatus === null || in_array($childStatus, [
                    RunStatus::Pending,
                    RunStatus::Running,
                    RunStatus::Waiting,
                ], true)) {
                    $this->syncWorkflowCursor($workflow, $sequence + 1);
                    return new ReplayState($workflow, $sequence, $current);
                }

                $this->syncWorkflowCursor($workflow, $sequence + 1);
                if ($childStatus === RunStatus::Completed) {
                    $current = $result->send(ChildRunHistory::outputForChildRun($childRun));
                } else {
                    $current = $result->throw(ChildRunHistory::exceptionForChildRun($childRun));
                }

                ++$sequence;

                continue;
            }

            if ($current instanceof AllCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);

                $leafDescriptors = $current->leafDescriptors($sequence);
                $groupSize = count($leafDescriptors);

                if ($groupSize === 0) {
                    $this->syncWorkflowCursor($workflow, $sequence);
                    $current = $result->send($current->nestedResults([]));

                    continue;
                }

                $pending = false;
                $results = [];
                $failure = null;

                foreach ($leafDescriptors as $descriptor) {
                    $call = $descriptor['call'];
                    $offset = $descriptor['offset'];
                    $itemSequence = $sequence + $offset;
                    WorkflowStepHistory::assertCompatible(
                        $run,
                        $itemSequence,
                        $call instanceof ActivityCall
                            ? WorkflowStepHistory::ACTIVITY
                            : WorkflowStepHistory::CHILD_WORKFLOW,
                    );

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

                    if (ChildRunHistory::parentHistoryBlocksResolutionWithoutEvent($run, $itemSequence)) {
                        $pending = true;

                        continue;
                    }

                    if (! $childRun instanceof WorkflowRun) {
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
                    $this->syncWorkflowCursor($workflow, $sequence + $groupSize);
                    $current = $result->throw($failure['exception']);
                    $sequence += $groupSize;

                    continue;
                }

                if ($pending) {
                    $this->syncWorkflowCursor($workflow, $sequence + $groupSize);
                    return new ReplayState($workflow, $sequence, $current);
                }

                ksort($results);
                $this->syncWorkflowCursor($workflow, $sequence + $groupSize);
                $current = $result->send($current->nestedResults(array_values($results)));
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
                'Workflow %s yielded %s. v2 currently supports activity(), await(), awaitWithTimeout(), child(), all(), sideEffect(), getVersion(), timer(), awaitSignal(), and continueAsNew() only.',
                $run->workflow_class,
                get_debug_type($current),
            ));
        }
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

    private function signalValue(WorkflowHistoryEvent $event): mixed
    {
        $serialized = $event->payload['value'] ?? null;

        if (! is_string($serialized)) {
            return null;
        }

        return Serializer::unserialize($serialized);
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
                [HistoryEventType::ActivityCompleted, HistoryEventType::ActivityFailed],
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

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
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

        if (is_array($payload) && ! is_string($payload['type'] ?? null) && is_string($event?->payload['exception_type'] ?? null)) {
            $payload['type'] = $event->payload['exception_type'];
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

        return FailureFactory::restoreForReplay($payload, $fallbackClass, $fallbackMessage, $fallbackCode);
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

            $arguments = $this->updateArguments($event, $command);
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
    ): ?string
    {
        $target = $event->payload['update_name'] ?? $command?->targetName();

        if (! is_string($target) || $target === '') {
            return null;
        }

        return WorkflowDefinition::resolveUpdateTarget($workflow::class, $target)['method'] ?? $target;
    }

    /**
     * @return array<int, mixed>
     */
    private function updateArguments(WorkflowHistoryEvent $event, ?WorkflowCommand $command): array
    {
        $serialized = $event->payload['arguments'] ?? null;

        if (is_string($serialized)) {
            $arguments = Serializer::unserialize($serialized);

            return is_array($arguments)
                ? array_values($arguments)
                : [];
        }

        return $command?->payloadArguments() ?? [];
    }

    private function syncWorkflowCursor(Workflow $workflow, int $visibleSequence): void
    {
        $workflow->syncExecutionCursor($visibleSequence);
        $workflow->setCommandDispatchEnabled(false);
    }
}
