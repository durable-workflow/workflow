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
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Exceptions\UnsupportedWorkflowYieldException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
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

        while (true) {
            if (! $result->valid()) {
                try {
                    $this->completeRun($run, $task, $result->getReturn());
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);
                }

                return null;
            }

            if ($current instanceof ActivityCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);

                $activityCompletion = $this->activityCompletionEvent($run, $sequence);

                if ($activityCompletion !== null) {
                    try {
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
                    return $this->scheduleActivity($run, $task, $sequence, $current);
                }

                if (in_array($execution->status, [ActivityStatus::Pending, ActivityStatus::Running], true)) {
                    return $this->waitForNextResumeSource($run, $task);
                }

                try {
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

            if ($current instanceof SideEffectCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);

                $sideEffectEvent = $this->sideEffectEvent($run, $sequence);

                try {
                    if ($sideEffectEvent === null) {
                        $sideEffectEvent = $this->recordSideEffect($run, $task, $sequence, $current);
                    }

                    $current = $result->send($this->sideEffectResult($sideEffectEvent));
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                ++$sequence;
                continue;
            }

            if ($current instanceof TimerCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);

                if ($this->timerFiredEvent($run, $sequence) !== null) {
                    try {
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
                            $current = $result->send(true);
                        } catch (Throwable $throwable) {
                            $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                            return null;
                        }

                        ++$sequence;

                        continue;
                    }

                    return $this->scheduleTimer($run, $task, $sequence, $current);
                }

                if ($timer->status === TimerStatus::Pending) {
                    return $this->waitForNextResumeSource($run, $task);
                }

                try {
                    $current = $result->send(true);
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                ++$sequence;
                continue;
            }

            if ($current instanceof SignalCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);

                $signalEvent = $this->appliedSignalEvent($run, $sequence, $current);

                if ($signalEvent !== null) {
                    try {
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
                        $current = $result->send($this->signalValue($signalEvent));
                    } catch (Throwable $throwable) {
                        $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                        return null;
                    }

                    ++$sequence;
                    continue;
                }

                $this->recordSignalWait($run, $task, $sequence, $current);

                return $this->waitForNextResumeSource($run, $task);
            }

            if ($current instanceof ChildWorkflowCall) {
                $this->applyRecordedUpdates($run, $workflow, $sequence);

                $resolutionEvent = ChildRunHistory::resolutionEventForSequence($run, $sequence);
                $childRun = ChildRunHistory::childRunForSequence($run, $sequence);

                if ($resolutionEvent !== null) {
                    try {
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
                        return $this->waitForNextResumeSource($run, $task);
                    }

                    return $this->scheduleChildWorkflow($run, $task, $sequence, $current);
                }

                $childStatus = ChildRunHistory::resolvedStatus(null, $childRun);

                if (in_array($childStatus, [
                    RunStatus::Pending,
                    RunStatus::Running,
                    RunStatus::Waiting,
                ], true)) {
                    return $this->waitForNextResumeSource($run, $task);
                }

                $resolutionEvent = $this->recordChildResolution($run, $task, $sequence, $childRun);

                try {
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

            if ($current instanceof ContinueAsNewCall) {
                return $this->continueAsNew($run, $task, $sequence, $current);
            }

            $this->failRun(
                $run,
                $task,
                new UnsupportedWorkflowYieldException(sprintf(
                    'Workflow %s yielded %s. v2 currently supports activity(), child(), sideEffect(), timer(), awaitSignal(), and continueAsNew() only.',
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
    ): WorkflowTask {
        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'activity_class' => $activityCall->activity,
            'activity_type' => TypeRegistry::for($activityCall->activity),
            'status' => ActivityStatus::Pending->value,
            'arguments' => Serializer::serialize($activityCall->arguments),
            'connection' => RoutingResolver::activityConnection($activityCall->activity, $run),
            'queue' => RoutingResolver::activityQueue($activityCall->activity, $run),
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $sequence,
        ], $task);

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

        $this->markRunWaiting($run, $task);

        return $activityTask;
    }

    private function scheduleChildWorkflow(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        ChildWorkflowCall $childWorkflowCall,
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

        $startCommand = $this->recordWorkflowStartCommand(
            $run,
            $sequence,
            $childInstance,
            $childRun,
            $metadata->arguments,
            $now,
        );

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()->create([
            'link_type' => 'child_workflow',
            'sequence' => $sequence,
            'parent_workflow_instance_id' => $run->workflow_instance_id,
            'parent_workflow_run_id' => $run->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ChildWorkflowScheduled, [
            'sequence' => $sequence,
            'workflow_link_id' => $link->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_class' => $childRun->workflow_class,
            'child_workflow_type' => $childRun->workflow_type,
        ], $task);

        WorkflowHistoryEvent::record($run, HistoryEventType::ChildRunStarted, [
            'sequence' => $sequence,
            'workflow_link_id' => $link->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_class' => $childRun->workflow_class,
            'child_workflow_type' => $childRun->workflow_type,
            'child_run_number' => $childRun->run_number,
        ], $task);

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
            'parent_workflow_instance_id' => $run->workflow_instance_id,
            'parent_workflow_run_id' => $run->id,
            'parent_sequence' => $sequence,
            'workflow_link_id' => $link->id,
            'declared_signals' => $commandContract['signals'],
            'declared_updates' => $commandContract['updates'],
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

        $this->markRunWaiting($run, $task);

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

        $command->forceFill([
            'applied_at' => now(),
        ])->save();

        return WorkflowHistoryEvent::record($run, HistoryEventType::SignalApplied, array_filter([
            'workflow_command_id' => $command->id,
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

        return WorkflowHistoryEvent::record($run, $eventType, array_filter([
            'sequence' => $sequence,
            'workflow_link_id' => $link?->id,
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
    ): WorkflowTask {
        $now = now();
        $commandContract = RunCommandContract::snapshot($run->workflow_class);
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()
            ->lockForUpdate()
            ->findOrFail($run->workflow_instance_id);

        /** @var WorkflowRun $continuedRun */
        $continuedRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $run->workflow_instance_id,
            'run_number' => $run->run_number + 1,
            'workflow_class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
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

        $startCommand = $this->recordWorkflowStartCommand(
            $run,
            $sequence,
            $instance,
            $continuedRun,
            $continueAsNew->arguments,
            $now,
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

        foreach ($parentChildLinks as $parentChildLink) {
            WorkflowLink::query()->create([
                'link_type' => 'child_workflow',
                'sequence' => $parentChildLink->sequence,
                'parent_workflow_instance_id' => $parentChildLink->parent_workflow_instance_id,
                'parent_workflow_run_id' => $parentChildLink->parent_workflow_run_id,
                'child_workflow_instance_id' => $continuedRun->workflow_instance_id,
                'child_workflow_run_id' => $continuedRun->id,
                'is_primary_parent' => $parentChildLink->is_primary_parent,
            ]);
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
            'outcome' => $startCommand->outcome?->value,
        ], null, $startCommand);

        WorkflowHistoryEvent::record($continuedRun, HistoryEventType::WorkflowStarted, [
            'workflow_class' => $continuedRun->workflow_class,
            'workflow_type' => $continuedRun->workflow_type,
            'workflow_instance_id' => $continuedRun->workflow_instance_id,
            'workflow_run_id' => $continuedRun->id,
            'workflow_command_id' => $startCommand->id,
            'continued_from_run_id' => $run->id,
            'workflow_link_id' => $link->id,
            'declared_signals' => $commandContract['signals'],
            'declared_updates' => $commandContract['updates'],
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

        $parentRunIds = $parentLinks
            ->pluck('parent_workflow_run_id')
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        if ($parentRunIds === []) {
            $parentReference = ChildRunHistory::parentReferenceForRun($childRun);

            if ($parentReference !== null) {
                $parentRunIds = [$parentReference['parent_workflow_run_id']];
            }
        }

        foreach ($parentRunIds as $parentRunId) {
            /** @var WorkflowRun|null $parentRun */
            $parentRun = WorkflowRun::query()
                ->lockForUpdate()
                ->find($parentRunId);

            if ($parentRun === null || in_array($parentRun->status, [
                RunStatus::Completed,
                RunStatus::Failed,
                RunStatus::Cancelled,
                RunStatus::Terminated,
            ], true)) {
                continue;
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

            $method = $event->payload['update_name'] ?? $command?->targetName();

            if (! is_string($method) || $method === '') {
                throw new LogicException(sprintf(
                    'Workflow update event [%s] is missing an update method name.',
                    $event->id,
                ));
            }

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
    }
}
