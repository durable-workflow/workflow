<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Generator;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
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

final class WorkflowExecutor
{
    public function run(WorkflowRun $run, WorkflowTask $task): ?WorkflowTask
    {
        $run->load(['instance', 'activityExecutions', 'timers', 'failures', 'tasks', 'commands', 'historyEvents']);

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

                        $current = $result->throw($this->activityException($execution));
                    }
                } catch (Throwable $throwable) {
                    $this->failRun($run, $task, $throwable, 'workflow_run', $run->id);

                    return null;
                }

                ++$sequence;
                continue;
            }

            if ($current instanceof TimerCall) {
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
                    $signalEvent = $this->applySignal($run, $task, $sequence, $current, $signalCommand);

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

            if ($current instanceof ContinueAsNewCall) {
                return $this->continueAsNew($run, $task, $current);
            }

            $this->failRun(
                $run,
                $task,
                new UnsupportedWorkflowYieldException(sprintf(
                    'Workflow %s yielded %s. v2 currently supports activity(), timer(), awaitSignal(), and continueAsNew() only.',
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
        ], $task->id);

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
        ]);

        $this->markRunWaiting($run, $task);

        return $activityTask;
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
        ], $task->id);

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
        ], $task->id);

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerFired, [
            'timer_id' => $timer->id,
            'sequence' => $sequence,
            'delay_seconds' => $timer->delay_seconds,
            'fired_at' => $timer->fired_at?->toJSON(),
        ], $task->id);
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
    ): WorkflowHistoryEvent {
        $value = $this->signalPayloadValue($command);

        $command->forceFill([
            'applied_at' => now(),
        ])->save();

        return WorkflowHistoryEvent::record($run, HistoryEventType::SignalApplied, [
            'workflow_command_id' => $command->id,
            'signal_name' => $signalCall->name,
            'sequence' => $sequence,
            'value' => Serializer::serialize($value),
        ], $task->id, $command->id);
    }

    private function recordSignalWait(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        SignalCall $signalCall,
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
            'sequence' => $sequence,
        ], $task->id);
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
        ContinueAsNewCall $continueAsNew,
    ): WorkflowTask {
        $now = now();
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

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()->create([
            'link_type' => 'continue_as_new',
            'parent_workflow_instance_id' => $run->workflow_instance_id,
            'parent_workflow_run_id' => $run->id,
            'child_workflow_instance_id' => $continuedRun->workflow_instance_id,
            'child_workflow_run_id' => $continuedRun->id,
            'is_primary_parent' => true,
        ]);

        $run->forceFill([
            'status' => RunStatus::Completed,
            'closed_reason' => 'continued',
            'closed_at' => $now,
            'last_progress_at' => $now,
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowContinuedAsNew, [
            'continued_to_run_id' => $continuedRun->id,
            'continued_to_run_number' => $continuedRun->run_number,
            'workflow_link_id' => $link->id,
            'closed_reason' => 'continued',
        ], $task->id);

        WorkflowHistoryEvent::record($continuedRun, HistoryEventType::WorkflowStarted, [
            'workflow_class' => $continuedRun->workflow_class,
            'workflow_type' => $continuedRun->workflow_type,
            'workflow_instance_id' => $continuedRun->workflow_instance_id,
            'workflow_run_id' => $continuedRun->id,
            'continued_from_run_id' => $run->id,
            'workflow_link_id' => $link->id,
        ]);

        /** @var WorkflowTask $continuedTask */
        $continuedTask = WorkflowTask::query()->create([
            'workflow_run_id' => $continuedRun->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $now,
            'payload' => [],
            'connection' => $continuedRun->connection,
            'queue' => $continuedRun->queue,
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
        ], $task->id);

        $task->forceFill([
            'status' => TaskStatus::Completed,
            'lease_expires_at' => null,
        ])->save();

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
        ], $task->id);

        $task->forceFill([
            'status' => TaskStatus::Failed,
            'lease_expires_at' => null,
        ])->save();

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
    }

    private function activityException(ActivityExecution $execution): Throwable
    {
        /** @var array{class?: string, message?: string, code?: int} $payload */
        $payload = is_string($execution->exception)
            ? Serializer::unserialize($execution->exception)
            : [];

        return new RuntimeException(
            sprintf('[%s] %s', $payload['class'] ?? RuntimeException::class, $payload['message'] ?? 'Activity failed'),
            (int) ($payload['code'] ?? 0),
        );
    }
}
