<?php

declare(strict_types=1);

namespace Workflow\V2;

use BadMethodCallException;
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
use Workflow\V2\Support\AwaitCall;
use Workflow\V2\Support\AwaitWithTimeoutCall;
use Workflow\V2\Support\FailureFactory;
use Workflow\V2\Support\ChildRunHistory;
use Workflow\V2\Support\CurrentRunResolver;
use Workflow\V2\Support\ParallelChildGroup;
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
    public const DEFAULT_VERSION = -1;

    private ?WorkflowRun $run = null;

    private ?string $selectedRunId = null;

    private ?CommandContext $commandContext = null;

    private function __construct(
        private WorkflowInstance $instance,
        ?WorkflowRun $selectedRun = null,
        private readonly bool $runTargeted = false,
    ) {
        $this->run = $selectedRun ?? CurrentRunResolver::forInstance($this->instance);
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
            $target = WorkflowDefinition::resolveUpdateTarget($resolvedClass, $method);

            return $this->update($target['name'] ?? $method, ...$arguments);
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

            return new self($instance->fresh());
        }

        WorkflowInstanceId::assertValid($instanceId);

        $instance = self::reserveCallerSuppliedInstance(
            workflow: $workflow,
            workflowType: $workflowType,
            instanceId: $instanceId,
        );

        return new self($instance->fresh());
    }

    public static function load(string $instanceId): self
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()
            ->findOrFail($instanceId);

        return new self($instance);
    }

    public static function loadSelection(string $instanceId, ?string $runId = null): self
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()
            ->findOrFail($instanceId);

        if ($runId === null) {
            return new self($instance);
        }

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()
            ->where('workflow_instance_id', $instanceId)
            ->whereKey($runId)
            ->firstOrFail();

        return new self($instance, $run, true);
    }

    public static function loadRun(string $runId): self
    {
        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()
            ->with('instance')
            ->findOrFail($runId);

        $instance = $run->instance;

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
        return $this->currentRunForInstance($this->instance)?->id;
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
            ->findOrFail($this->instance->id);

        if ($this->runTargeted && $this->selectedRunId !== null) {
            /** @var WorkflowRun $selectedRun */
            $selectedRun = WorkflowRun::query()->findOrFail($this->selectedRunId);
            $this->run = $selectedRun;
        } else {
            $this->run = $this->currentRunForInstance($this->instance);
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

            $run = $this->currentRunForInstance($instance, true);

            if ($run instanceof WorkflowRun) {

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
                    ], null, $command);

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
            ], null, $command);

            WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
                'workflow_class' => $run->workflow_class,
                'workflow_type' => $run->workflow_type,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'workflow_command_id' => $command->id,
                'declared_signals' => $commandContract['signals'],
                'declared_signal_contracts' => $commandContract['signal_contracts'],
                'declared_updates' => $commandContract['updates'],
                'declared_update_contracts' => $commandContract['update_contracts'],
            ], null, $command);

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
        $result = $this->attemptSignalWithArguments($name, $arguments);

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
        return $this->attemptUpdateWithArguments($method, $arguments);
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    public function attemptUpdateWithArguments(string $method, array $arguments): UpdateResult
    {
        $this->refresh();

        /** @var WorkflowCommand|null $command */
        $command = null;
        /** @var WorkflowFailure|null $failure */
        $failure = null;
        $result = null;
        $resumeTask = null;

        DB::transaction(function () use ($method, $arguments, &$command, &$failure, &$result, &$resumeTask): void {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()
                ->lockForUpdate()
                ->findOrFail($this->instance->id);

            $currentRun = $this->currentRunForInstance($instance, true);

            if (! $currentRun instanceof WorkflowRun) {
                $command = $this->rejectCommand(
                    $instance,
                    null,
                    CommandType::Update,
                    'instance_not_started',
                    $this->commandTargetScope(),
                    $this->updateCommandPayloadAttributes($method, $arguments),
                );

                return;
            }

            if ($this->runTargeted) {
                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()
                    ->lockForUpdate()
                    ->findOrFail($this->selectedRunId);
                $updateName = $method;
                $updateCommandAttributes = $this->updateCommandPayloadAttributes($updateName, $arguments);

                if ($run->id !== $currentRun->id) {
                    $command = $this->rejectCommand(
                        $instance,
                        $run,
                        CommandType::Update,
                        'selected_run_not_current',
                        $this->commandTargetScope(),
                        $updateCommandAttributes,
                        HistoryEventType::UpdateRejected,
                        $this->updateRejectedEventPayload($instance, $run, $updateName, $arguments),
                    );

                    return;
                }
            } else {
                $run = $currentRun;
                $updateName = $method;
                $updateCommandAttributes = $this->updateCommandPayloadAttributes($updateName, $arguments);
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
                    $updateCommandAttributes,
                    HistoryEventType::UpdateRejected,
                    $this->updateRejectedEventPayload($instance, $run, $updateName, $arguments),
                );

                return;
            }

            $this->loadLockedRunRelations($run, $instance);

            if (! RunCommandContract::hasUpdateMethod($run, $updateName)) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    CommandType::Update,
                    'unknown_update',
                    $this->commandTargetScope(),
                    $updateCommandAttributes,
                    HistoryEventType::UpdateRejected,
                    $this->updateRejectedEventPayload($instance, $run, $updateName, $arguments),
                );

                return;
            }

            $validatedArguments = $this->validatedUpdateArgumentsForRun($run, $updateName, $arguments);

            if ($validatedArguments['validation_errors'] !== []) {
                $updateCommandAttributes = $this->updateCommandPayloadAttributes(
                    $updateName,
                    $arguments,
                    $validatedArguments['validation_errors'],
                );

                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    CommandType::Update,
                    'invalid_update_arguments',
                    $this->commandTargetScope(),
                    $updateCommandAttributes,
                    HistoryEventType::UpdateRejected,
                    $this->updateRejectedEventPayload(
                        $instance,
                        $run,
                        $updateName,
                        $arguments,
                        $validatedArguments['validation_errors'],
                    ),
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
                    $updateCommandAttributes,
                    HistoryEventType::UpdateRejected,
                    $this->updateRejectedEventPayload($instance, $run, $updateName, $arguments),
                );

                return;
            }

            $arguments = $validatedArguments['arguments'];
            $updateMethod = $this->resolveUpdateTargetForRun($run, $updateName)['method'];
            $replayState = (new QueryStateReplayer())->replayState($run);

            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::record($instance, $run, $this->commandAttributes(array_merge([
                'command_type' => CommandType::Update->value,
                'target_scope' => $this->commandTargetScope(),
                'status' => CommandStatus::Accepted->value,
                'accepted_at' => now(),
            ], $updateCommandAttributes)));

            WorkflowHistoryEvent::record($run, HistoryEventType::UpdateAccepted, [
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'update_name' => $updateName,
                'arguments' => Serializer::serialize($arguments),
                'sequence' => $replayState->sequence,
            ], null, $command);

            try {
                $parameters = $replayState->workflow->resolveMethodDependencies(
                    $arguments,
                    new ReflectionMethod($replayState->workflow, $updateMethod),
                );
                $result = $replayState->workflow->{$updateMethod}(...$parameters);

                WorkflowHistoryEvent::record($run, HistoryEventType::UpdateApplied, [
                    'workflow_command_id' => $command->id,
                    'workflow_instance_id' => $instance->id,
                    'workflow_run_id' => $run->id,
                    'update_name' => $updateName,
                    'arguments' => Serializer::serialize($arguments),
                    'sequence' => $replayState->sequence,
                ], null, $command);

                WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted, [
                    'workflow_command_id' => $command->id,
                    'workflow_instance_id' => $instance->id,
                    'workflow_run_id' => $run->id,
                    'update_name' => $updateName,
                    'sequence' => $replayState->sequence,
                    'result' => Serializer::serialize($result),
                ], null, $command);

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
                    'update_name' => $updateName,
                    'sequence' => $replayState->sequence,
                    'failure_id' => $failure->id,
                    'exception_class' => $failure->exception_class,
                    'message' => $failure->message,
                    'code' => $throwable->getCode(),
                    'exception' => FailureFactory::payload($throwable),
                ], null, $command);

                $command->forceFill([
                    'outcome' => CommandOutcome::UpdateFailed->value,
                    'applied_at' => now(),
                ])->save();
            }

            $run->forceFill([
                'last_progress_at' => now(),
            ])->save();

            if (
                ! $this->hasOpenWorkflowTask($run->id)
                && ($replayState->current instanceof AwaitCall || $replayState->current instanceof AwaitWithTimeoutCall)
            ) {
                /** @var WorkflowTask $resumeTask */
                $resumeTask = WorkflowTask::query()->create([
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

        if ($resumeTask instanceof WorkflowTask) {
            TaskDispatcher::dispatch($resumeTask);
        }

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
        return $this->attemptSignalWithArguments($name, $arguments);
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    public function attemptSignalWithArguments(string $name, array $arguments): CommandResult
    {
        $arguments = array_is_list($arguments)
            ? array_values($arguments)
            : $arguments;

        if ($name === '') {
            throw new LogicException('Signal name cannot be empty.');
        }

        return $this->attemptSignalInternal($name, $arguments);
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function attemptSignalInternal(string $name, array $arguments): CommandResult
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

            $currentRun = $this->currentRunForInstance($instance, true);

            if (! $currentRun instanceof WorkflowRun) {
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

                if ($run->id !== $currentRun->id) {
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
                $run = $currentRun;
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
                    $this->signalCommandPayloadAttributes($name, $arguments),
                );

                return;
            }

            $validatedArguments = $this->validatedSignalArgumentsForRun($run, $name, $arguments);

            if ($validatedArguments['validation_errors'] !== []) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    CommandType::Signal,
                    'invalid_signal_arguments',
                    $this->commandTargetScope(),
                    $this->signalCommandPayloadAttributes(
                        $name,
                        $arguments,
                        $validatedArguments['validation_errors'],
                    ),
                );

                return;
            }

            $arguments = $validatedArguments['arguments'];

            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::record($instance, $run, $this->commandAttributes([
                'command_type' => CommandType::Signal->value,
                'target_scope' => $this->commandTargetScope(),
                'status' => CommandStatus::Accepted->value,
                'outcome' => CommandOutcome::SignalReceived->value,
                ...$this->signalCommandPayloadAttributes($name, $arguments),
                'accepted_at' => now(),
            ]));

            $signalWaitId = $this->signalWaitIdForAcceptedCommand($run, $name, $command->id);

            WorkflowHistoryEvent::record($run, HistoryEventType::SignalReceived, array_filter([
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'signal_name' => $name,
                'signal_wait_id' => $signalWaitId,
            ], static fn (mixed $value): bool => $value !== null), null, $command);

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

            $currentRun = $this->currentRunForInstance($instance, true);

            if (! $currentRun instanceof WorkflowRun) {
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

                if ($run->id !== $currentRun->id) {
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
                $run = $currentRun;
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
            ], $task, $command);

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

            $currentRun = $this->currentRunForInstance($instance, true);

            if (! $currentRun instanceof WorkflowRun) {
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

                if ($run->id !== $currentRun->id) {
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
                $run = $currentRun;
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
            ], null, $command);

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
            ], null, $command);

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
        ?HistoryEventType $historyEventType = null,
        array $historyPayload = [],
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
                'invalid_signal_arguments' => CommandOutcome::RejectedInvalidArguments->value,
                'invalid_update_arguments' => CommandOutcome::RejectedInvalidArguments->value,
                UpdateCommandGate::BLOCKED_BY_PENDING_SIGNAL => CommandOutcome::RejectedPendingSignal->value,
                default => null,
            },
            'payload_codec' => config('workflows.serializer'),
            'rejection_reason' => $reason,
            'rejected_at' => now(),
        ], $attributes)));

        if ($run instanceof WorkflowRun && $historyEventType instanceof HistoryEventType) {
            WorkflowHistoryEvent::record(
                $run,
                $historyEventType,
                array_filter([
                    'workflow_command_id' => $command->id,
                    'workflow_instance_id' => $instance->id,
                    'workflow_run_id' => $run->id,
                ] + $historyPayload, static fn (mixed $value): bool => $value !== null),
                null,
                $command,
            );
        }

        return $command;
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @param array<string, list<string>> $validationErrors
     * @return array<string, mixed>
     */
    private function signalCommandPayloadAttributes(
        string $name,
        array $arguments,
        array $validationErrors = [],
    ): array
    {
        return [
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize([
                'name' => $name,
                'arguments' => $arguments,
                'validation_errors' => $validationErrors,
            ]),
        ];
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @param array<string, list<string>> $validationErrors
     * @return array<string, mixed>
     */
    private function updateCommandPayloadAttributes(
        string $method,
        array $arguments,
        array $validationErrors = [],
    ): array
    {
        return [
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize([
                'name' => $method,
                'arguments' => $arguments,
                'validation_errors' => $validationErrors,
            ]),
        ];
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @param array<string, list<string>> $validationErrors
     * @return array<string, mixed>
     */
    private function updateRejectedEventPayload(
        WorkflowInstance $instance,
        WorkflowRun $run,
        string $updateName,
        array $arguments,
        array $validationErrors = [],
    ): array {
        return [
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => $updateName,
            'arguments' => Serializer::serialize($arguments),
            'validation_errors' => $validationErrors,
        ];
    }

    /**
     * @return array{name: string, method: string}
     */
    private function resolveUpdateTargetForRun(WorkflowRun $run, string $target): array
    {
        try {
            $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        } catch (LogicException) {
            return [
                'name' => $target,
                'method' => $target,
            ];
        }

        return WorkflowDefinition::resolveUpdateTarget($workflowClass, $target) ?? [
            'name' => $target,
            'method' => $target,
        ];
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private function validatedSignalArgumentsForRun(WorkflowRun $run, string $signalName, array $arguments): array
    {
        if (array_is_list($arguments)) {
            $normalized = array_values($arguments);
            $contract = RunCommandContract::signalContract($run, $signalName);

            if ($contract === null) {
                try {
                    $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
                } catch (LogicException) {
                    return ['arguments' => $normalized, 'validation_errors' => []];
                }

                $contract = WorkflowDefinition::signalContract($workflowClass, $signalName);
            }

            return $contract === null
                ? ['arguments' => $normalized, 'validation_errors' => []]
                : $this->normalizePositionalCommandArguments($contract, $arguments, 'signal');
        }

        $contract = RunCommandContract::signalContract($run, $signalName);

        if ($contract === null) {
            try {
                $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
            } catch (LogicException) {
                return ['arguments' => [$arguments], 'validation_errors' => []];
            }

            $contract = WorkflowDefinition::signalContract($workflowClass, $signalName);
        }

        return $contract === null
            ? ['arguments' => [$arguments], 'validation_errors' => []]
            : $this->normalizeNamedCommandArguments($contract, $arguments);
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private function validatedUpdateArgumentsForRun(WorkflowRun $run, string $updateName, array $arguments): array
    {
        if (array_is_list($arguments)) {
            $normalized = array_values($arguments);
        } else {
            $normalized = [];
        }

        $contract = RunCommandContract::updateContract($run, $updateName);

        if ($contract === null) {
            try {
                $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
            } catch (LogicException) {
                return array_is_list($arguments)
                    ? ['arguments' => $normalized, 'validation_errors' => []]
                    : [
                        'arguments' => [],
                        'validation_errors' => [
                            'arguments' => ['Named arguments require a durable or loadable workflow update contract.'],
                        ],
                    ];
            }

            $contract = WorkflowDefinition::updateContract($workflowClass, $updateName);
        }

        if ($contract === null) {
            return array_is_list($arguments)
                ? ['arguments' => $normalized, 'validation_errors' => []]
                : [
                    'arguments' => [],
                    'validation_errors' => [
                        'arguments' => ['Named arguments require a durable or loadable workflow update contract.'],
                    ],
                ];
        }

        return array_is_list($arguments)
            ? $this->normalizePositionalCommandArguments($contract, $arguments, 'update')
            : $this->normalizeNamedCommandArguments($contract, $arguments);
    }

    /**
     * @param array{name: string, parameters: list<array<string, mixed>>} $contract
     * @param array<int, mixed> $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private function normalizePositionalCommandArguments(array $contract, array $arguments, string $commandType): array
    {
        $normalized = [];
        $errors = [];
        $providedCount = count($arguments);
        $consumed = 0;

        foreach ($contract['parameters'] as $parameter) {
            if (($parameter['variadic'] ?? false) === true) {
                while ($consumed < $providedCount) {
                    $normalized[] = $arguments[$consumed];
                    $consumed++;
                }

                continue;
            }

            if ($consumed < $providedCount) {
                $normalized[] = $arguments[$consumed];
                $consumed++;

                continue;
            }

            if (($parameter['default_available'] ?? false) === true) {
                $normalized[] = $parameter['default'] ?? null;

                continue;
            }

            if (($parameter['required'] ?? false) === true) {
                $errors[$parameter['name']][] = sprintf(
                    'The %s argument is required.',
                    $parameter['name'],
                );
            }
        }

        if ($consumed < $providedCount) {
            $errors['arguments'][] = sprintf(
                'Too many arguments were provided for %s [%s].',
                $commandType,
                $contract['name'],
            );
        }

        return [
            'arguments' => $normalized,
            'validation_errors' => $errors,
        ];
    }

    /**
     * @param array{name: string, parameters: list<array<string, mixed>>} $contract
     * @param array<string, mixed> $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private function normalizeNamedCommandArguments(array $contract, array $arguments): array
    {
        $normalized = [];
        $errors = [];
        $knownParameters = [];

        foreach ($contract['parameters'] as $parameter) {
            $name = $parameter['name'];
            $knownParameters[] = $name;

            if (($parameter['variadic'] ?? false) === true) {
                if (! array_key_exists($name, $arguments)) {
                    continue;
                }

                $values = $arguments[$name];

                if (is_array($values)) {
                    array_push($normalized, ...array_values($values));
                } else {
                    $normalized[] = $values;
                }

                continue;
            }

            if (array_key_exists($name, $arguments)) {
                $normalized[] = $arguments[$name];

                continue;
            }

            if (($parameter['default_available'] ?? false) === true) {
                $normalized[] = $parameter['default'] ?? null;

                continue;
            }

            if (($parameter['required'] ?? false) === true) {
                $errors[$name][] = sprintf('The %s argument is required.', $name);
            }
        }

        foreach (array_keys($arguments) as $name) {
            if (in_array($name, $knownParameters, true)) {
                continue;
            }

            $errors[(string) $name][] = sprintf('Unknown argument [%s].', (string) $name);
        }

        return [
            'arguments' => $normalized,
            'validation_errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function commandAttributes(array $attributes): array
    {
        return array_merge($this->resolvedCommandContext()->attributes(), $attributes);
    }

    private function currentRunForInstance(
        WorkflowInstance $instance,
        bool $lockForUpdate = false,
    ): ?WorkflowRun {
        $run = CurrentRunResolver::forInstance($instance, lockForUpdate: $lockForUpdate);

        if ($lockForUpdate) {
            CurrentRunResolver::syncPointer($instance, $run);
        }

        return $run;
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

    private function hasOpenWorkflowTask(string $runId): bool
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
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

    private function signalWaitIdForAcceptedCommand(WorkflowRun $run, string $name, string $commandId): string
    {
        $hasEarlierPendingSignal = $run->commands->contains(
            static fn (WorkflowCommand $command): bool => $command->command_type === CommandType::Signal
                && $command->status === CommandStatus::Accepted
                && $command->applied_at === null
                && $command->targetName() === $name
        );

        if ($hasEarlierPendingSignal) {
            return SignalWaits::bufferedWaitIdForCommandId($commandId);
        }

        return SignalWaits::openWaitIdForName($run, $name)
            ?? SignalWaits::bufferedWaitIdForCommandId($commandId);
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

            /** @var WorkflowInstance|null $parentInstance */
            $parentInstance = WorkflowInstance::query()
                ->lockForUpdate()
                ->find($parentRun->workflow_instance_id);

            if ($parentInstance instanceof WorkflowInstance) {
                $this->loadLockedRunRelations($parentRun, $parentInstance);
            }

            if (is_int($parentReference['parent_sequence'])) {
                $parallelMetadata = ParallelChildGroup::metadataForSequence($parentRun, $parentReference['parent_sequence']);
                $childStatus = ChildRunHistory::resolvedStatus(
                    ChildRunHistory::resolutionEventForSequence($parentRun, $parentReference['parent_sequence']),
                    $childRun,
                );

                if (
                    $parallelMetadata !== null
                    && $childStatus instanceof RunStatus
                    && ! ParallelChildGroup::shouldWakeParentOnChildClosure($parentRun, $parallelMetadata, $childStatus)
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

    /**
     * @param class-string<Workflow> $workflow
     */
    private static function reserveCallerSuppliedInstance(
        string $workflow,
        string $workflowType,
        string $instanceId,
    ): WorkflowInstance
    {
        $now = now();

        WorkflowInstance::query()->insertOrIgnore([
            'id' => $instanceId,
            'workflow_class' => $workflow,
            'workflow_type' => $workflowType,
            'reserved_at' => $now,
            'run_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

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

        return $instance;
    }
}
