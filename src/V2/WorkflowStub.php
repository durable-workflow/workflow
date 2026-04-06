<?php

declare(strict_types=1);

namespace Workflow\V2;

use BadMethodCallException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use ReflectionMethod;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\DuplicateStartPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\FailureFactory;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\RoutingResolver;
use Workflow\V2\Support\RunCommandContract;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\SignalWaits;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\TaskRepair;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\UpdateCommandGate;
use Workflow\V2\Support\WorkerCompatibility;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Support\WorkflowInstanceId;
use Workflow\WorkflowMetadata;

final class WorkflowStub
{
    private ?WorkflowRun $run = null;

    private ?string $selectedRunId = null;

    private ?CommandContext $commandContext = null;

    private function __construct(
        private WorkflowInstance $instance,
        ?WorkflowRun $selectedRun = null,
        private readonly bool $runTargeted = false,
    ) {
        $this->run = $selectedRun ?? $this->instance->currentRun;
        $this->selectedRunId = $this->run?->id;
    }

    public function __call(string $method, array $arguments): mixed
    {
        $this->refresh();

        $workflowClass = $this->run?->workflow_class ?? $this->instance->workflow_class;
        $workflowType = $this->run?->workflow_type ?? $this->instance->workflow_type;
        $resolvedClass = TypeRegistry::resolveWorkflowClass($workflowClass, $workflowType);

        if (WorkflowDefinition::hasQueryMethod($resolvedClass, $method)) {
            return $this->query($method, ...$arguments);
        }

        if (WorkflowDefinition::hasUpdateMethod($resolvedClass, $method)) {
            return $this->update($method, ...$arguments);
        }

        throw new BadMethodCallException(sprintf('Call to undefined method [%s::%s].', static::class, $method));
    }

    /**
     * @param class-string<Workflow> $workflow
     */
    public static function make(string $workflow, ?string $instanceId = null): self
    {
        $workflowType = TypeRegistry::for($workflow);

        if ($instanceId === null) {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()->create([
                'workflow_class' => $workflow,
                'workflow_type' => $workflowType,
                'reserved_at' => now(),
                'run_count' => 0,
            ]);

            return new self($instance->fresh(['currentRun']));
        }

        WorkflowInstanceId::assertValid($instanceId);

        try {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()->create([
                'id' => $instanceId,
                'workflow_class' => $workflow,
                'workflow_type' => $workflowType,
                'reserved_at' => now(),
                'run_count' => 0,
            ]);
        } catch (QueryException $exception) {
            if (! self::duplicateInstanceReservation($exception)) {
                throw $exception;
            }

            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()
                ->with('currentRun')
                ->findOrFail($instanceId);

            if ($instance->workflow_type !== $workflowType) {
                throw new LogicException(sprintf(
                    'Workflow instance [%s] is reserved for durable type [%s] and cannot be reused for [%s].',
                    $instanceId,
                    $instance->workflow_type,
                    $workflowType,
                ));
            }

            if ($instance->workflow_class !== $workflow) {
                $instance->forceFill([
                    'workflow_class' => $workflow,
                ])->save();
            }

            return new self($instance->fresh(['currentRun']));
        }

        return new self($instance->fresh(['currentRun']));
    }

    public static function load(string $instanceId): self
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()
            ->with('currentRun')
            ->findOrFail($instanceId);

        return new self($instance);
    }

    public static function loadRun(string $runId): self
    {
        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()
            ->with('instance.currentRun')
            ->findOrFail($runId);

        $instance = $run->instance;
        $instance->loadMissing('currentRun');

        return new self($instance, $run, true);
    }

    public function id(): string
    {
        return $this->instance->id;
    }

    public function runId(): ?string
    {
        return $this->run?->id;
    }

    public function currentRunId(): ?string
    {
        return $this->instance->currentRun?->id ?? $this->instance->current_run_id;
    }

    public function currentRunIsSelected(): bool
    {
        return $this->selectedRunId !== null && $this->selectedRunId === $this->currentRunId();
    }

    public function status(): string
    {
        return $this->run?->status->value ?? 'reserved';
    }

    public function running(): bool
    {
        return $this->run !== null && in_array($this->run->status, [
            RunStatus::Pending,
            RunStatus::Running,
            RunStatus::Waiting,
        ], true);
    }

    public function completed(): bool
    {
        return $this->run?->status === RunStatus::Completed;
    }

    public function failed(): bool
    {
        return $this->run?->status === RunStatus::Failed;
    }

    public function cancelled(): bool
    {
        return $this->run?->status === RunStatus::Cancelled;
    }

    public function terminated(): bool
    {
        return $this->run?->status === RunStatus::Terminated;
    }

    public function output(): mixed
    {
        return $this->run?->workflowOutput();
    }

    public function query(string $method, ...$arguments): mixed
    {
        $this->refresh();

        if ($this->run === null) {
            throw new LogicException(sprintf('Workflow instance [%s] has not started yet.', $this->instance->id));
        }

        $workflowClass = TypeRegistry::resolveWorkflowClass($this->run->workflow_class, $this->run->workflow_type);

        if (! WorkflowDefinition::hasQueryMethod($workflowClass, $method)) {
            throw new LogicException(sprintf(
                'Method [%s::%s] is not a v2 query method.',
                $workflowClass,
                $method,
            ));
        }

        return (new QueryStateReplayer())->query($this->run, $method, $arguments);
    }

    public function summary(): ?WorkflowRunSummary
    {
        if ($this->run === null) {
            return null;
        }

        return WorkflowRunSummary::query()->find($this->run->id);
    }

    public function refresh(): self
    {
        $this->instance = WorkflowInstance::query()
            ->with('currentRun')
            ->findOrFail($this->instance->id);

        if ($this->runTargeted && $this->selectedRunId !== null) {
            /** @var WorkflowRun $selectedRun */
            $selectedRun = WorkflowRun::query()->findOrFail($this->selectedRunId);
            $this->run = $selectedRun;
        } else {
            $this->run = $this->instance->currentRun;
            $this->selectedRunId = $this->run?->id;
        }

        return $this;
    }

    public function withCommandContext(CommandContext $commandContext): self
    {
        $clone = clone $this;
        $clone->commandContext = $commandContext;

        return $clone;
    }

    public function start(...$arguments): StartResult
    {
        $result = $this->attemptStart(...$arguments);

        if ($result->rejected()) {
            throw new LogicException(sprintf('Workflow instance [%s] has already started.', $this->instance->id));
        }

        return $result;
    }

    public function attemptStart(...$arguments): StartResult
    {
        /** @var WorkflowCommand|null $command */
        $command = null;

        [$arguments, $startOptions] = $this->extractStartArguments($arguments);
        $metadata = WorkflowMetadata::fromStartArguments($arguments);
        $task = null;

        DB::transaction(function () use ($metadata, $startOptions, &$task, &$command): void {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()
                ->lockForUpdate()
                ->findOrFail($this->instance->id);

            if ($instance->current_run_id !== null) {
                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()
                    ->lockForUpdate()
                    ->findOrFail($instance->current_run_id);

                $canReturnExisting = $startOptions->duplicateStartPolicy === DuplicateStartPolicy::ReturnExistingActive
                    && in_array($run->status, [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting], true);

                /** @var WorkflowCommand $command */
                $command = WorkflowCommand::record($instance, $run, $this->commandAttributes([
                    'command_type' => CommandType::Start->value,
                    'target_scope' => 'instance',
                    'status' => $canReturnExisting
                        ? CommandStatus::Accepted->value
                        : CommandStatus::Rejected->value,
                    'outcome' => $canReturnExisting
                        ? CommandOutcome::ReturnedExistingActive->value
                        : CommandOutcome::RejectedDuplicate->value,
                    'payload_codec' => config('workflows.serializer'),
                    'payload' => Serializer::serialize($metadata->arguments),
                    'rejection_reason' => $canReturnExisting
                        ? null
                        : 'instance_already_started',
                    'accepted_at' => $canReturnExisting
                        ? now()
                        : null,
                    'applied_at' => $canReturnExisting
                        ? now()
                        : null,
                    'rejected_at' => $canReturnExisting
                        ? null
                        : now(),
                ]));

                WorkflowHistoryEvent::record($run, $canReturnExisting
                    ? HistoryEventType::StartAccepted
                    : HistoryEventType::StartRejected, [
                        'workflow_command_id' => $command->id,
                        'workflow_instance_id' => $instance->id,
                        'workflow_run_id' => $run->id,
                        'workflow_class' => $run->workflow_class,
                        'workflow_type' => $run->workflow_type,
                        'outcome' => $command->outcome?->value,
                        'rejection_reason' => $command->rejection_reason,
                    ], null, $command->id);

                RunSummaryProjector::project(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                );

                return;
            }

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()->create([
                'workflow_instance_id' => $instance->id,
                'run_number' => $instance->run_count + 1,
                'workflow_class' => $instance->workflow_class,
                'workflow_type' => $instance->workflow_type,
                'status' => RunStatus::Pending->value,
                'compatibility' => WorkerCompatibility::current(),
                'payload_codec' => config('workflows.serializer'),
                'arguments' => \Workflow\Serializers\Serializer::serialize($metadata->arguments),
                'connection' => RoutingResolver::workflowConnection($instance->workflow_class, $metadata),
                'queue' => RoutingResolver::workflowQueue($instance->workflow_class, $metadata),
                'started_at' => now(),
                'last_progress_at' => now(),
                'last_history_sequence' => 0,
            ]);

            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::record($instance, $run, $this->commandAttributes([
                'command_type' => CommandType::Start->value,
                'target_scope' => 'instance',
                'status' => CommandStatus::Accepted->value,
                'outcome' => CommandOutcome::StartedNew->value,
                'payload_codec' => config('workflows.serializer'),
                'payload' => Serializer::serialize($metadata->arguments),
                'accepted_at' => now(),
                'applied_at' => now(),
            ]));

            $instance->forceFill([
                'current_run_id' => $run->id,
                'started_at' => now(),
                'run_count' => $run->run_number,
            ])->save();

            $commandContract = RunCommandContract::snapshot($run->workflow_class);

            WorkflowHistoryEvent::record($run, HistoryEventType::StartAccepted, [
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'workflow_class' => $run->workflow_class,
                'workflow_type' => $run->workflow_type,
                'outcome' => $command->outcome?->value,
            ], null, $command->id);

            WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
                'workflow_class' => $run->workflow_class,
                'workflow_type' => $run->workflow_type,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'workflow_command_id' => $command->id,
                'declared_signals' => $commandContract['signals'],
                'declared_updates' => $commandContract['updates'],
            ], null, $command->id);

            /** @var WorkflowTask $task */
            $task = WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now(),
                'payload' => [],
                'connection' => $run->connection,
                'queue' => $run->queue,
                'compatibility' => $run->compatibility,
            ]);

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'failures', 'historyEvents'])
            );
        });

        $this->refresh();

        if ($task instanceof WorkflowTask) {
            TaskDispatcher::dispatch($task);
        }

        if (! $command instanceof WorkflowCommand) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] failed to record a start command.',
                $this->instance->id
            ));
        }

        return StartResult::fromCommand($command);
    }

    public function cancel(): CommandResult
    {
        $result = $this->attemptCancel();

        if ($result->rejected()) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] cannot be cancelled: %s.',
                $this->instance->id,
                $result->rejectionReason() ?? 'unknown',
            ));
        }

        return $result;
    }

    public function signal(string $name, ...$arguments): CommandResult
    {
        $result = $this->attemptSignal($name, ...$arguments);

        if ($result->rejected()) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] cannot receive signal [%s]: %s.',
                $this->instance->id,
                $name,
                $result->rejectionReason() ?? 'unknown',
            ));
        }

        return $result;
    }

    public function update(string $method, ...$arguments): mixed
    {
        $result = $this->attemptUpdate($method, ...$arguments);

        if ($result->rejected()) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] cannot apply update [%s]: %s.',
                $this->instance->id,
                $method,
                $result->rejectionReason() ?? 'unknown',
            ));
        }

        if ($result->failed()) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] update [%s] failed: %s.',
                $this->instance->id,
                $method,
                $result->failureMessage() ?? 'unknown',
            ));
        }

        return $result->result();
    }

    public function attemptUpdate(string $method, ...$arguments): UpdateResult
    {
        $this->refresh();

        /** @var WorkflowCommand|null $command */
        $command = null;
        /** @var WorkflowFailure|null $failure */
        $failure = null;
        $result = null;

        DB::transaction(function () use ($method, $arguments, &$command, &$failure, &$result): void {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()
                ->lockForUpdate()
                ->findOrFail($this->instance->id);

            if ($instance->current_run_id === null) {
                $command = $this->rejectCommand(
                    $instance,
                    null,
                    CommandType::Update,
                    'instance_not_started',
                    $this->commandTargetScope(),
                );

                return;
            }

            if ($this->runTargeted) {
                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()
                    ->lockForUpdate()
                    ->findOrFail($this->selectedRunId);

                if ($run->id !== $instance->current_run_id) {
                    $command = $this->rejectCommand(
                        $instance,
                        $run,
                        CommandType::Update,
                        'selected_run_not_current',
                        $this->commandTargetScope(),
                    );

                    return;
                }
            } else {
                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()
                    ->lockForUpdate()
                    ->findOrFail($instance->current_run_id);
            }

            if (in_array($run->status, [
                RunStatus::Completed,
                RunStatus::Failed,
                RunStatus::Cancelled,
                RunStatus::Terminated,
            ], true)) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    CommandType::Update,
                    'run_not_active',
                    $this->commandTargetScope(),
                );

                return;
            }

            $this->loadLockedRunRelations($run, $instance);

            if (! RunCommandContract::hasUpdateMethod($run, $method)) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    CommandType::Update,
                    'unknown_update',
                    $this->commandTargetScope(),
                    [
                        'payload_codec' => config('workflows.serializer'),
                        'payload' => Serializer::serialize([
                            'name' => $method,
                            'arguments' => $arguments,
                        ]),
                    ],
                );

                return;
            }

            if (UpdateCommandGate::blockedReason($run) !== null) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    CommandType::Update,
                    UpdateCommandGate::BLOCKED_BY_PENDING_SIGNAL,
                    $this->commandTargetScope(),
                    [
                        'payload_codec' => config('workflows.serializer'),
                        'payload' => Serializer::serialize([
                            'name' => $method,
                            'arguments' => $arguments,
                        ]),
                    ],
                );

                return;
            }

            $resolvedClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
            $replayState = (new QueryStateReplayer())->replayState($run);

            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::record($instance, $run, $this->commandAttributes([
                'command_type' => CommandType::Update->value,
                'target_scope' => $this->commandTargetScope(),
                'status' => CommandStatus::Accepted->value,
                'payload_codec' => config('workflows.serializer'),
                'payload' => Serializer::serialize([
                    'name' => $method,
                    'arguments' => $arguments,
                ]),
                'accepted_at' => now(),
            ]));

            WorkflowHistoryEvent::record($run, HistoryEventType::UpdateAccepted, [
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'update_name' => $method,
                'arguments' => Serializer::serialize($arguments),
                'sequence' => $replayState->sequence,
            ], null, $command->id);

            try {
                $parameters = $replayState->workflow->resolveMethodDependencies(
                    $arguments,
                    new ReflectionMethod($replayState->workflow, $method),
                );
                $result = $replayState->workflow->{$method}(...$parameters);

                WorkflowHistoryEvent::record($run, HistoryEventType::UpdateApplied, [
                    'workflow_command_id' => $command->id,
                    'workflow_instance_id' => $instance->id,
                    'workflow_run_id' => $run->id,
                    'update_name' => $method,
                    'arguments' => Serializer::serialize($arguments),
                    'sequence' => $replayState->sequence,
                ], null, $command->id);

                WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted, [
                    'workflow_command_id' => $command->id,
                    'workflow_instance_id' => $instance->id,
                    'workflow_run_id' => $run->id,
                    'update_name' => $method,
                    'sequence' => $replayState->sequence,
                    'result' => Serializer::serialize($result),
                ], null, $command->id);

                $command->forceFill([
                    'outcome' => CommandOutcome::UpdateCompleted->value,
                    'applied_at' => now(),
                ])->save();
            } catch (Throwable $throwable) {
                /** @var WorkflowFailure $failure */
                $failure = WorkflowFailure::query()->create(array_merge(
                    FailureFactory::make($throwable),
                    [
                        'workflow_run_id' => $run->id,
                        'source_kind' => 'workflow_command',
                        'source_id' => $command->id,
                        'propagation_kind' => 'update',
                        'handled' => false,
                    ],
                ));

                WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted, [
                    'workflow_command_id' => $command->id,
                    'workflow_instance_id' => $instance->id,
                    'workflow_run_id' => $run->id,
                    'update_name' => $method,
                    'sequence' => $replayState->sequence,
                    'failure_id' => $failure->id,
                    'exception_class' => $failure->exception_class,
                    'message' => $failure->message,
                    'code' => $throwable->getCode(),
                    'exception' => FailureFactory::payload($throwable),
                ], null, $command->id);

                $command->forceFill([
                    'outcome' => CommandOutcome::UpdateFailed->value,
                    'applied_at' => now(),
                ])->save();
            }

            $run->forceFill([
                'last_progress_at' => now(),
            ])->save();

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            );
        });

        $this->refresh();

        if (! $command instanceof WorkflowCommand) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] failed to record an update command.',
                $this->instance->id,
            ));
        }

        return UpdateResult::fromCommand($command, $result, $failure);
    }

    public function repair(): CommandResult
    {
        $result = $this->attemptRepair();

        if ($result->rejected()) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] cannot be repaired: %s.',
                $this->instance->id,
                $result->rejectionReason() ?? 'unknown',
            ));
        }

        return $result;
    }

    public function attemptSignal(string $name, ...$arguments): CommandResult
    {
        if ($name === '') {
            throw new LogicException('Signal name cannot be empty.');
        }

        /** @var WorkflowCommand|null $command */
        $command = null;
        $task = null;

        DB::transaction(function () use ($name, $arguments, &$command, &$task): void {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()
                ->lockForUpdate()
                ->findOrFail($this->instance->id);

            if ($instance->current_run_id === null) {
                $command = $this->rejectCommand(
                    $instance,
                    null,
                    CommandType::Signal,
                    'instance_not_started',
                    $this->commandTargetScope(),
                );

                return;
            }

            if ($this->runTargeted) {
                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()
                    ->lockForUpdate()
                    ->findOrFail($this->selectedRunId);

                if ($run->id !== $instance->current_run_id) {
                    $command = $this->rejectCommand(
                        $instance,
                        $run,
                        CommandType::Signal,
                        'selected_run_not_current',
                        $this->commandTargetScope(),
                    );

                    return;
                }
            } else {
                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()
                    ->lockForUpdate()
                    ->findOrFail($instance->current_run_id);
            }

            if (in_array($run->status, [
                RunStatus::Completed,
                RunStatus::Failed,
                RunStatus::Cancelled,
                RunStatus::Terminated,
            ], true)) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    CommandType::Signal,
                    'run_not_active',
                    $this->commandTargetScope(),
                );

                return;
            }

            $this->loadLockedRunRelations($run, $instance);

            if (! RunCommandContract::hasSignal($run, $name)) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    CommandType::Signal,
                    'unknown_signal',
                    $this->commandTargetScope(),
                    [
                        'payload_codec' => config('workflows.serializer'),
                        'payload' => Serializer::serialize([
                            'name' => $name,
                            'arguments' => $arguments,
                        ]),
                    ],
                );

                return;
            }

            $signalWaitId = $this->signalWaitIdForAcceptedCommand($run, $name);

            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::record($instance, $run, $this->commandAttributes([
                'command_type' => CommandType::Signal->value,
                'target_scope' => $this->commandTargetScope(),
                'status' => CommandStatus::Accepted->value,
                'outcome' => CommandOutcome::SignalReceived->value,
                'payload_codec' => config('workflows.serializer'),
                'payload' => Serializer::serialize([
                    'name' => $name,
                    'arguments' => $arguments,
                ]),
                'accepted_at' => now(),
            ]));

            WorkflowHistoryEvent::record($run, HistoryEventType::SignalReceived, array_filter([
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'signal_name' => $name,
                'signal_wait_id' => $signalWaitId,
            ], static fn (mixed $value): bool => $value !== null), null, $command->id);

            if (! $this->hasOpenTask($run->id)) {
                /** @var WorkflowTask $task */
                $task = WorkflowTask::query()->create([
                    'workflow_run_id' => $run->id,
                    'task_type' => TaskType::Workflow->value,
                    'status' => TaskStatus::Ready->value,
                    'available_at' => now(),
                    'payload' => [],
                    'connection' => $run->connection,
                    'queue' => $run->queue,
                    'compatibility' => $run->compatibility,
                ]);
            }

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            );
        });

        $this->refresh();

        if ($task instanceof WorkflowTask) {
            TaskDispatcher::dispatch($task);
        }

        if (! $command instanceof WorkflowCommand) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] failed to record a signal command.',
                $this->instance->id
            ));
        }

        return new CommandResult($command);
    }

    public function attemptRepair(): CommandResult
    {
        /** @var WorkflowCommand|null $command */
        $command = null;
        $task = null;

        DB::transaction(function () use (&$command, &$task): void {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()
                ->lockForUpdate()
                ->findOrFail($this->instance->id);

            if ($instance->current_run_id === null) {
                $command = $this->rejectCommand(
                    $instance,
                    null,
                    CommandType::Repair,
                    'instance_not_started',
                    $this->commandTargetScope(),
                );

                return;
            }

            if ($this->runTargeted) {
                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()
                    ->lockForUpdate()
                    ->findOrFail($this->selectedRunId);

                if ($run->id !== $instance->current_run_id) {
                    $command = $this->rejectCommand(
                        $instance,
                        $run,
                        CommandType::Repair,
                        'selected_run_not_current',
                        $this->commandTargetScope(),
                    );

                    return;
                }
            } else {
                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()
                    ->lockForUpdate()
                    ->findOrFail($instance->current_run_id);
            }

            if (in_array($run->status, [
                RunStatus::Completed,
                RunStatus::Failed,
                RunStatus::Cancelled,
                RunStatus::Terminated,
            ], true)) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    CommandType::Repair,
                    'run_not_active',
                    $this->commandTargetScope(),
                );

                return;
            }

            $run->setRelation('instance', $instance);
            $run->setRelation(
                'tasks',
                WorkflowTask::query()
                    ->where('workflow_run_id', $run->id)
                    ->orderBy('available_at')
                    ->lockForUpdate()
                    ->get()
            );
            $run->setRelation(
                'activityExecutions',
                ActivityExecution::query()
                    ->where('workflow_run_id', $run->id)
                    ->orderBy('sequence')
                    ->lockForUpdate()
                    ->get()
            );
            $run->setRelation(
                'timers',
                WorkflowTimer::query()
                    ->where('workflow_run_id', $run->id)
                    ->orderBy('sequence')
                    ->lockForUpdate()
                    ->get()
            );
            $run->setRelation(
                'failures',
                WorkflowFailure::query()
                    ->where('workflow_run_id', $run->id)
                    ->latest('created_at')
                    ->lockForUpdate()
                    ->get()
            );
            $run->setRelation(
                'historyEvents',
                WorkflowHistoryEvent::query()
                    ->where('workflow_run_id', $run->id)
                    ->orderBy('sequence')
                    ->lockForUpdate()
                    ->get()
            );
            $run->setRelation(
                'childLinks',
                \Workflow\V2\Models\WorkflowLink::query()
                    ->where('parent_workflow_run_id', $run->id)
                    ->oldest('created_at')
                    ->lockForUpdate()
                    ->get()
            );

            $summary = RunSummaryProjector::project($run);

            if ($summary->liveness_state === 'repair_needed') {
                $task = TaskRepair::repairRun($run, $summary);
            }

            $run->forceFill([
                'last_progress_at' => now(),
            ])->save();

            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::record($instance, $run, $this->commandAttributes([
                'command_type' => CommandType::Repair->value,
                'target_scope' => $this->commandTargetScope(),
                'status' => CommandStatus::Accepted->value,
                'outcome' => $task instanceof WorkflowTask
                    ? CommandOutcome::RepairDispatched->value
                    : CommandOutcome::RepairNotNeeded->value,
                'payload_codec' => config('workflows.serializer'),
                'payload' => Serializer::serialize([
                    'liveness_state' => $summary->liveness_state,
                    'wait_kind' => $summary->wait_kind,
                    'task_id' => $task?->id,
                    'task_type' => $task?->task_type?->value,
                ]),
                'accepted_at' => now(),
                'applied_at' => now(),
            ]));

            WorkflowHistoryEvent::record($run, HistoryEventType::RepairRequested, [
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'command_type' => CommandType::Repair->value,
                'outcome' => $command->outcome?->value,
                'liveness_state' => $summary->liveness_state,
                'wait_kind' => $summary->wait_kind,
                'task_id' => $task?->id,
                'task_type' => $task?->task_type?->value,
            ], $task?->id, $command->id);

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            );
        });

        $this->refresh();

        if ($task instanceof WorkflowTask) {
            TaskDispatcher::dispatch($task);
        }

        if (! $command instanceof WorkflowCommand) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] failed to record a repair command.',
                $this->instance->id,
            ));
        }

        return new CommandResult($command);
    }

    public function attemptCancel(): CommandResult
    {
        return $this->attemptTerminalCommand(
            CommandType::Cancel,
            RunStatus::Cancelled,
            HistoryEventType::CancelRequested,
            HistoryEventType::WorkflowCancelled,
            'cancelled',
        );
    }

    public function terminate(): CommandResult
    {
        $result = $this->attemptTerminate();

        if ($result->rejected()) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] cannot be terminated: %s.',
                $this->instance->id,
                $result->rejectionReason() ?? 'unknown',
            ));
        }

        return $result;
    }

    public function attemptTerminate(): CommandResult
    {
        return $this->attemptTerminalCommand(
            CommandType::Terminate,
            RunStatus::Terminated,
            HistoryEventType::TerminateRequested,
            HistoryEventType::WorkflowTerminated,
            'terminated',
        );
    }

    /**
     * @param array<int, mixed> $arguments
     * @return array{0: array<int, mixed>, 1: StartOptions}
     */
    private function extractStartArguments(array $arguments): array
    {
        $startOptions = null;

        foreach ($arguments as $index => $argument) {
            if (! $argument instanceof StartOptions) {
                continue;
            }

            $startOptions = $argument;
            unset($arguments[$index]);
        }

        return [array_values($arguments), $startOptions ?? StartOptions::rejectDuplicate()];
    }

    private function attemptTerminalCommand(
        CommandType $commandType,
        RunStatus $terminalStatus,
        HistoryEventType $requestedEventType,
        HistoryEventType $terminalEventType,
        string $closedReason,
    ): CommandResult {
        /** @var WorkflowCommand|null $command */
        $command = null;
        $parentTasks = [];

        DB::transaction(function () use (
            &$command,
            &$parentTasks,
            $commandType,
            $terminalStatus,
            $requestedEventType,
            $terminalEventType,
            $closedReason,
        ): void {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()
                ->lockForUpdate()
                ->findOrFail($this->instance->id);

            if ($instance->current_run_id === null) {
                $command = $this->rejectCommand(
                    $instance,
                    null,
                    $commandType,
                    'instance_not_started',
                    $this->commandTargetScope(),
                );

                return;
            }

            if ($this->runTargeted) {
                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()
                    ->lockForUpdate()
                    ->findOrFail($this->selectedRunId);

                if ($run->id !== $instance->current_run_id) {
                    $command = $this->rejectCommand(
                        $instance,
                        $run,
                        $commandType,
                        'selected_run_not_current',
                        $this->commandTargetScope(),
                    );

                    return;
                }
            } else {
                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()
                    ->lockForUpdate()
                    ->findOrFail($instance->current_run_id);
            }

            $openTasks = WorkflowTask::query()
                ->where('workflow_run_id', $run->id)
                ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $openActivityExecutions = ActivityExecution::query()
                ->where('workflow_run_id', $run->id)
                ->whereIn('status', [ActivityStatus::Pending->value, ActivityStatus::Running->value])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $openTimers = WorkflowTimer::query()
                ->where('workflow_run_id', $run->id)
                ->where('status', TimerStatus::Pending->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (in_array($run->status, [
                RunStatus::Completed,
                RunStatus::Failed,
                RunStatus::Cancelled,
                RunStatus::Terminated,
            ], true)) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    $commandType,
                    'run_not_active',
                    $this->commandTargetScope(),
                );

                return;
            }

            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::record($instance, $run, $this->commandAttributes([
                'command_type' => $commandType->value,
                'target_scope' => $this->commandTargetScope(),
                'status' => CommandStatus::Accepted->value,
                'outcome' => match ($commandType) {
                    CommandType::Cancel => CommandOutcome::Cancelled->value,
                    CommandType::Terminate => CommandOutcome::Terminated->value,
                    default => null,
                },
                'payload_codec' => config('workflows.serializer'),
                'accepted_at' => now(),
            ]));

            WorkflowHistoryEvent::record($run, $requestedEventType, [
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'command_type' => $commandType->value,
            ], null, $command->id);

            foreach ($openTasks as $task) {
                $task->forceFill([
                    'status' => TaskStatus::Cancelled,
                    'lease_expires_at' => null,
                    'last_error' => null,
                ])->save();
            }

            foreach ($openActivityExecutions as $execution) {
                $execution->forceFill([
                    'status' => ActivityStatus::Cancelled,
                    'closed_at' => $execution->closed_at ?? now(),
                ])->save();
            }

            foreach ($openTimers as $timer) {
                $timer->forceFill([
                    'status' => TimerStatus::Cancelled,
                ])->save();
            }

            $run->forceFill([
                'status' => $terminalStatus,
                'closed_reason' => $closedReason,
                'closed_at' => now(),
                'last_progress_at' => now(),
            ])->save();

            WorkflowHistoryEvent::record($run, $terminalEventType, [
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'closed_reason' => $closedReason,
            ], null, $command->id);

            $command->forceFill([
                'applied_at' => now(),
            ])->save();

            $parentTasks = $this->createParentResumeTasks($run);

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            );
        });

        $this->refresh();

        foreach ($parentTasks as $task) {
            if ($task instanceof WorkflowTask) {
                TaskDispatcher::dispatch($task);
            }
        }

        if (! $command instanceof WorkflowCommand) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] failed to record a %s command.',
                $this->instance->id,
                $commandType->value,
            ));
        }

        return new CommandResult($command);
    }

    private function rejectCommand(
        WorkflowInstance $instance,
        ?WorkflowRun $run,
        CommandType $commandType,
        string $reason,
        string $targetScope = 'instance',
        array $attributes = [],
    ): WorkflowCommand {
        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::record($instance, $run, $this->commandAttributes(array_merge([
            'command_type' => $commandType->value,
            'target_scope' => $targetScope,
            'status' => CommandStatus::Rejected->value,
            'outcome' => match ($reason) {
                'instance_not_started' => CommandOutcome::RejectedNotStarted->value,
                'run_not_active' => CommandOutcome::RejectedNotActive->value,
                'selected_run_not_current' => CommandOutcome::RejectedNotCurrent->value,
                'unknown_signal' => CommandOutcome::RejectedUnknownSignal->value,
                'unknown_update' => CommandOutcome::RejectedUnknownUpdate->value,
                UpdateCommandGate::BLOCKED_BY_PENDING_SIGNAL => CommandOutcome::RejectedPendingSignal->value,
                default => null,
            },
            'payload_codec' => config('workflows.serializer'),
            'rejection_reason' => $reason,
            'rejected_at' => now(),
        ], $attributes)));

        return $command;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function commandAttributes(array $attributes): array
    {
        return array_merge($this->resolvedCommandContext()->attributes(), $attributes);
    }

    private function resolvedCommandContext(): CommandContext
    {
        return $this->commandContext ?? CommandContext::phpApi();
    }

    private function hasOpenTask(string $runId): bool
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->exists();
    }

    private function loadLockedRunRelations(WorkflowRun $run, WorkflowInstance $instance): void
    {
        $run->setRelation('instance', $instance);
        $run->setRelation(
            'tasks',
            WorkflowTask::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('available_at')
                ->lockForUpdate()
                ->get()
        );
        $run->setRelation(
            'activityExecutions',
            ActivityExecution::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get()
        );
        $run->setRelation(
            'timers',
            WorkflowTimer::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get()
        );
        $run->setRelation(
            'failures',
            WorkflowFailure::query()
                ->where('workflow_run_id', $run->id)
                ->latest('created_at')
                ->lockForUpdate()
                ->get()
        );
        $run->setRelation(
            'commands',
            WorkflowCommand::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('command_sequence')
                ->oldest('created_at')
                ->oldest('id')
                ->lockForUpdate()
                ->get()
        );
        $run->setRelation(
            'historyEvents',
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get()
        );
        $run->setRelation(
            'childLinks',
            \Workflow\V2\Models\WorkflowLink::query()
                ->where('parent_workflow_run_id', $run->id)
                ->oldest('created_at')
                ->lockForUpdate()
                ->get()
        );
    }

    private function signalWaitIdForAcceptedCommand(WorkflowRun $run, string $name): ?string
    {
        $hasEarlierPendingSignal = $run->commands->contains(
            static fn (WorkflowCommand $command): bool => $command->command_type === CommandType::Signal
                && $command->status === CommandStatus::Accepted
                && $command->applied_at === null
                && $command->targetName() === $name
        );

        if ($hasEarlierPendingSignal) {
            return null;
        }

        return SignalWaits::openWaitIdForName($run, $name);
    }

    private function commandTargetScope(): string
    {
        return $this->runTargeted ? 'run' : 'instance';
    }

    /**
     * @return list<WorkflowTask>
     */
    private function createParentResumeTasks(WorkflowRun $childRun): array
    {
        $tasks = [];

        $parentLinks = \Workflow\V2\Models\WorkflowLink::query()
            ->where('child_workflow_run_id', $childRun->id)
            ->where('link_type', 'child_workflow')
            ->lockForUpdate()
            ->get();

        foreach ($parentLinks as $parentLink) {
            /** @var WorkflowRun|null $parentRun */
            $parentRun = WorkflowRun::query()
                ->lockForUpdate()
                ->find($parentLink->parent_workflow_run_id);

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

            /** @var WorkflowTask $task */
            $task = WorkflowTask::query()->create([
                'workflow_run_id' => $parentRun->id,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now(),
                'payload' => [],
                'connection' => $parentRun->connection,
                'queue' => $parentRun->queue,
                'compatibility' => $parentRun->compatibility,
            ]);

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

            $tasks[] = $task;
        }

        return $tasks;
    }

    private static function duplicateInstanceReservation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = $exception->errorInfo[1] ?? null;

        if (in_array($sqlState, ['23000', '23505'], true)) {
            return true;
        }

        if (in_array($driverCode, [19, 1062, 1555, 2067, 2601, 2627], true)) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'unique violation');
    }
}
