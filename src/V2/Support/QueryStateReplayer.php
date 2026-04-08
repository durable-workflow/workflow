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

final class QueryStateReplayer
{
    public function query(WorkflowRun $run, string $method, array $arguments = []): mixed
    {
        $workflow = $this->replay($run);
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
        $arguments = $workflow->resolveMethodDependencies(
            $run->workflowArguments(),
            new ReflectionMethod($workflow, 'execute'),
        );
        $result = $workflow->execute(...$arguments);

        if (! $result instanceof Generator) {
            return new ReplayState($workflow, 0, null);
        }

        $current = $result->current();
        $sequence = 1;

        while (true) {
            if (! $result->valid()) {
                return new ReplayState($workflow, $sequence, null);
            }

            if ($current instanceof ActivityCall) {
                $activityCompletion = $this->activityCompletionEvent($run, $sequence);

                if ($activityCompletion !== null) {
                    if ($activityCompletion->event_type === HistoryEventType::ActivityCompleted) {
                        $current = $result->send($this->activityResult($activityCompletion));
                    } else {
                        $current = $result->throw($this->activityException($activityCompletion, null, $run));
                    }

                    ++$sequence;

                    continue;
                }

                /** @var ActivityExecution|null $execution */
                $execution = $run->activityExecutions->firstWhere('sequence', $sequence);

                if ($execution === null || in_array(
                    $execution->status,
                    [ActivityStatus::Pending, ActivityStatus::Running],
                    true
                )) {
                    $this->applyRecordedUpdates($run, $workflow, $sequence);

                    return new ReplayState($workflow, $sequence, $current);
                }

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

                $resolutionEvent = $this->conditionWaitResolutionEvent($run, $sequence);

                if ($resolutionEvent === null) {
                    return new ReplayState($workflow, $sequence, $current);
                }

                $current = $result->send(
                    $resolutionEvent->event_type === HistoryEventType::ConditionWaitSatisfied
                );

                ++$sequence;

                continue;
            }

            if ($current instanceof TimerCall) {
                if ($this->timerFiredEvent($run, $sequence) !== null) {
                    $current = $result->send(true);

                    ++$sequence;

                    continue;
                }

                $timer = $run->timers->firstWhere('sequence', $sequence);

                if ($timer === null || $timer->status === TimerStatus::Pending) {
                    $this->applyRecordedUpdates($run, $workflow, $sequence);

                    return new ReplayState($workflow, $sequence, $current);
                }

                $current = $result->send(true);

                ++$sequence;

                continue;
            }

            if ($current instanceof SideEffectCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);

                $sideEffectEvent = $this->sideEffectEvent($run, $sequence);

                if ($sideEffectEvent === null) {
                    return new ReplayState($workflow, $sequence, $current);
                }

                $current = $result->send($this->sideEffectResult($sideEffectEvent));

                ++$sequence;

                continue;
            }

            if ($current instanceof VersionCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);

                $resolution = VersionResolver::resolve(
                    $run,
                    $this->versionMarkerEvent($run, $sequence),
                    $current,
                    $sequence,
                );

                $current = $result->send($resolution->version);

                if ($resolution->advancesSequence) {
                    ++$sequence;
                }

                continue;
            }

            if ($current instanceof SignalCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);

                $signalEvent = $this->appliedSignalEvent($run, $sequence, $current);

                if ($signalEvent === null) {
                    return new ReplayState($workflow, $sequence, $current);
                }

                $current = $result->send($this->signalValue($signalEvent));

                ++$sequence;

                continue;
            }

            if ($current instanceof ChildWorkflowCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);

                $resolutionEvent = ChildRunHistory::resolutionEventForSequence($run, $sequence);
                $childRun = ChildRunHistory::childRunForSequence($run, $sequence);

                if ($resolutionEvent !== null) {
                    if ($resolutionEvent->event_type === HistoryEventType::ChildRunCompleted) {
                        $current = $result->send(ChildRunHistory::outputForResolution($resolutionEvent, $childRun));
                    } else {
                        $current = $result->throw(ChildRunHistory::exceptionForResolution($resolutionEvent, $childRun));
                    }

                    ++$sequence;

                    continue;
                }

                if ($childRun === null) {
                    return new ReplayState($workflow, $sequence, $current);
                }

                $childStatus = ChildRunHistory::resolvedStatus(null, $childRun);

                if ($childStatus === null || in_array($childStatus, [
                    RunStatus::Pending,
                    RunStatus::Running,
                    RunStatus::Waiting,
                ], true)) {
                    return new ReplayState($workflow, $sequence, $current);
                }

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

                if ($current->calls === []) {
                    $current = $result->send([]);

                    continue;
                }

                $pending = false;
                $results = [];
                $failure = null;
                $groupSize = count($current->calls);

                foreach ($current->calls as $index => $call) {
                    $itemSequence = $sequence + $index;

                    if ($call instanceof ActivityCall) {
                        $activityCompletion = $this->activityCompletionEvent($run, $itemSequence);

                        if ($activityCompletion !== null) {
                            if ($activityCompletion->event_type === HistoryEventType::ActivityCompleted) {
                                $results[$index] = $this->activityResult($activityCompletion);

                                continue;
                            }

                            $failure = $this->selectParallelFailure(
                                $failure,
                                $index,
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
                            $results[$index] = $execution->activityResult();

                            continue;
                        }

                        $failure = $this->selectParallelFailure(
                            $failure,
                            $index,
                            $this->activityException(null, $execution, $run),
                            $execution->closed_at?->getTimestampMs() ?? PHP_INT_MAX,
                        );

                        continue;
                    }

                    if (! $call instanceof ChildWorkflowCall) {
                        throw new LogicException(sprintf(
                            'Workflow\\V2\\all() encountered unsupported call [%s].',
                            get_debug_type($call),
                        ));
                    }

                    $resolutionEvent = ChildRunHistory::resolutionEventForSequence($run, $itemSequence);
                    $childRun = ChildRunHistory::childRunForSequence($run, $itemSequence);

                    if ($resolutionEvent !== null) {
                        if ($resolutionEvent->event_type === HistoryEventType::ChildRunCompleted) {
                            $results[$index] = ChildRunHistory::outputForResolution($resolutionEvent, $childRun);

                            continue;
                        }

                        $failure = $this->selectParallelFailure(
                            $failure,
                            $index,
                            ChildRunHistory::exceptionForResolution($resolutionEvent, $childRun),
                            $resolutionEvent->recorded_at?->getTimestampMs()
                                ?? $resolutionEvent->created_at?->getTimestampMs()
                                ?? PHP_INT_MAX,
                        );

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
                        $results[$index] = ChildRunHistory::outputForChildRun($childRun);

                        continue;
                    }

                    $failure = $this->selectParallelFailure(
                        $failure,
                        $index,
                        ChildRunHistory::exceptionForChildRun($childRun),
                        $childRun->closed_at?->getTimestampMs() ?? PHP_INT_MAX,
                    );
                }

                if ($failure !== null) {
                    $current = $result->throw($failure['exception']);
                    $sequence += $groupSize;

                    continue;
                }

                if ($pending) {
                    return new ReplayState($workflow, $sequence, $current);
                }

                ksort($results);
                $current = $result->send(array_values($results));
                $sequence += $groupSize;

                continue;
            }

            if ($current instanceof ContinueAsNewCall) {
                return new ReplayState($workflow, $sequence, $current);
            }

            throw new UnsupportedWorkflowYieldException(sprintf(
                'Workflow %s yielded %s. v2 currently supports activity(), await(), awaitWithTimeout(), child(), all(), sideEffect(), getVersion(), timer(), awaitSignal(), and continueAsNew() only.',
                $run->workflow_class,
                get_debug_type($current),
            ));
        }
    }

    /**
     * @param array{index: int, exception: Throwable, recorded_at: int}|null $currentFailure
     * @return array{index: int, exception: Throwable, recorded_at: int}
     */
    private function selectParallelFailure(
        ?array $currentFailure,
        int $index,
        Throwable $exception,
        int $recordedAt,
    ): array {
        if (
            $currentFailure === null
            || $recordedAt < $currentFailure['recorded_at']
            || ($recordedAt === $currentFailure['recorded_at'] && $index < $currentFailure['index'])
        ) {
            return [
                'index' => $index,
                'exception' => $exception,
                'recorded_at' => $recordedAt,
            ];
        }

        return $currentFailure;
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
}
