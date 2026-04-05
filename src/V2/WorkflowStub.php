<?php

declare(strict_types=1);

namespace Workflow\V2;

use BadMethodCallException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use ReflectionClass;
use Workflow\QueryMethod;
use Workflow\Serializers\Serializer;
use Workflow\UpdateMethod;
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
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\RoutingResolver;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\TypeRegistry;
use Workflow\WorkflowMetadata;

final class WorkflowStub
{
    /**
     * @var array<class-string, array<string, true>>
     */
    private static array $queryMethodCache = [];

    /**
     * @var array<class-string, array<string, true>>
     */
    private static array $updateMethodCache = [];

    private ?WorkflowRun $run = null;
    private ?string $selectedRunId = null;

    private function __construct(
        private WorkflowInstance $instance,
        ?WorkflowRun $selectedRun = null,
        private readonly bool $runTargeted = false,
    ) {
        $this->run = $selectedRun ?? $this->instance->currentRun;
        $this->selectedRunId = $this->run?->id;
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

        try {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()->create([
                'id' => $instanceId,
                'workflow_class' => $workflow,
                'workflow_type' => $workflowType,
                'reserved_at' => now(),
                'run_count' => 0,
            ]);
        } catch (QueryException) {
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
            throw new LogicException(sprintf(
                'Workflow instance [%s] has not started yet.',
                $this->instance->id,
            ));
        }

        $workflowClass = TypeRegistry::resolveWorkflowClass($this->run->workflow_class, $this->run->workflow_type);

        if (! self::isQueryMethod($workflowClass, $method)) {
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

    public function __call(string $method, array $arguments): mixed
    {
        $this->refresh();

        $workflowClass = $this->run?->workflow_class ?? $this->instance->workflow_class;
        $workflowType = $this->run?->workflow_type ?? $this->instance->workflow_type;
        $resolvedClass = TypeRegistry::resolveWorkflowClass($workflowClass, $workflowType);

        if (self::isQueryMethod($resolvedClass, $method)) {
            return $this->query($method, ...$arguments);
        }

        if (self::isUpdateMethod($resolvedClass, $method)) {
            throw new LogicException(sprintf(
                'Method [%s::%s] is marked with #[UpdateMethod], but v2 updates are not implemented yet.',
                $resolvedClass,
                $method,
            ));
        }

        throw new BadMethodCallException(sprintf(
            'Call to undefined method [%s::%s].',
            static::class,
            $method,
        ));
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
                $command = WorkflowCommand::record($instance, $run, [
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
                ]);

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
                'payload_codec' => config('workflows.serializer'),
                'arguments' => \Workflow\Serializers\Serializer::serialize($metadata->arguments),
                'connection' => RoutingResolver::workflowConnection($instance->workflow_class, $metadata),
                'queue' => RoutingResolver::workflowQueue($instance->workflow_class, $metadata),
                'started_at' => now(),
                'last_progress_at' => now(),
                'last_history_sequence' => 0,
            ]);

            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::record($instance, $run, [
                'command_type' => CommandType::Start->value,
                'target_scope' => 'instance',
                'status' => CommandStatus::Accepted->value,
                'outcome' => CommandOutcome::StartedNew->value,
                'payload_codec' => config('workflows.serializer'),
                'payload' => Serializer::serialize($metadata->arguments),
                'accepted_at' => now(),
                'applied_at' => now(),
            ]);

            $instance->forceFill([
                'current_run_id' => $run->id,
                'started_at' => now(),
                'run_count' => $run->run_number,
            ])->save();

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

            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::record($instance, $run, [
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
            ]);

            WorkflowHistoryEvent::record($run, HistoryEventType::SignalReceived, [
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'signal_name' => $name,
            ], null, $command->id);

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

            $summary = RunSummaryProjector::project($run);

            if ($summary->liveness_state === 'repair_needed') {
                $task = $this->createRepairTask($run, $summary);
            }

            $run->forceFill([
                'last_progress_at' => now(),
            ])->save();

            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::record($instance, $run, [
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
            ]);

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

        DB::transaction(function () use (
            &$command,
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
            $command = WorkflowCommand::record($instance, $run, [
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
            ]);

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

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            );
        });

        $this->refresh();

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
    ): WorkflowCommand {
        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::record($instance, $run, [
            'command_type' => $commandType->value,
            'target_scope' => $targetScope,
            'status' => CommandStatus::Rejected->value,
            'outcome' => match ($reason) {
                'instance_not_started' => CommandOutcome::RejectedNotStarted->value,
                'run_not_active' => CommandOutcome::RejectedNotActive->value,
                'selected_run_not_current' => CommandOutcome::RejectedNotCurrent->value,
                default => null,
            },
            'payload_codec' => config('workflows.serializer'),
            'rejection_reason' => $reason,
            'rejected_at' => now(),
        ]);

        return $command;
    }

    private function hasOpenTask(string $runId): bool
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->exists();
    }

    private function createRepairTask(WorkflowRun $run, WorkflowRunSummary $summary): WorkflowTask
    {
        if ($summary->wait_kind === 'activity') {
            /** @var ActivityExecution|null $execution */
            $execution = $run->activityExecutions
                ->first(static fn (ActivityExecution $execution): bool => in_array(
                    $execution->status,
                    [ActivityStatus::Pending, ActivityStatus::Running],
                    true,
                ));

            if ($execution instanceof ActivityExecution) {
                /** @var WorkflowTask $task */
                $task = WorkflowTask::query()->create([
                    'workflow_run_id' => $run->id,
                    'task_type' => TaskType::Activity->value,
                    'status' => TaskStatus::Ready->value,
                    'available_at' => now(),
                    'payload' => [
                        'activity_execution_id' => $execution->id,
                    ],
                    'connection' => $execution->connection ?? $run->connection,
                    'queue' => $execution->queue ?? $run->queue,
                    'repair_count' => 1,
                ]);

                return $task;
            }
        }

        if ($summary->wait_kind === 'timer') {
            /** @var WorkflowTimer|null $timer */
            $timer = $run->timers
                ->first(static fn (WorkflowTimer $timer): bool => $timer->status === TimerStatus::Pending);

            if ($timer instanceof WorkflowTimer) {
                /** @var WorkflowTask $task */
                $task = WorkflowTask::query()->create([
                    'workflow_run_id' => $run->id,
                    'task_type' => TaskType::Timer->value,
                    'status' => TaskStatus::Ready->value,
                    'available_at' => $timer->fire_at !== null && $timer->fire_at->isFuture()
                        ? $timer->fire_at
                        : now(),
                    'payload' => [
                        'timer_id' => $timer->id,
                    ],
                    'connection' => $run->connection,
                    'queue' => $run->queue,
                    'repair_count' => 1,
                ]);

                return $task;
            }
        }

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => [],
            'connection' => $run->connection,
            'queue' => $run->queue,
            'repair_count' => 1,
        ]);

        return $task;
    }

    private function commandTargetScope(): string
    {
        return $this->runTargeted ? 'run' : 'instance';
    }

    private static function isQueryMethod(string $class, string $method): bool
    {
        if (! isset(self::$queryMethodCache[$class])) {
            self::$queryMethodCache[$class] = [];

            foreach ((new ReflectionClass($class))->getMethods() as $reflectionMethod) {
                foreach ($reflectionMethod->getAttributes() as $attribute) {
                    if ($attribute->getName() === QueryMethod::class) {
                        self::$queryMethodCache[$class][$reflectionMethod->getName()] = true;

                        break;
                    }
                }
            }
        }

        return self::$queryMethodCache[$class][$method] ?? false;
    }

    private static function isUpdateMethod(string $class, string $method): bool
    {
        if (! isset(self::$updateMethodCache[$class])) {
            self::$updateMethodCache[$class] = [];

            foreach ((new ReflectionClass($class))->getMethods() as $reflectionMethod) {
                foreach ($reflectionMethod->getAttributes() as $attribute) {
                    if ($attribute->getName() === UpdateMethod::class) {
                        self::$updateMethodCache[$class][$reflectionMethod->getName()] = true;

                        break;
                    }
                }
            }
        }

        return self::$updateMethodCache[$class][$method] ?? false;
    }
}
