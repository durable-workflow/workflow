<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\DuplicateStartPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\WorkflowExecutionUnavailableException;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class DefaultWorkflowControlPlane implements WorkflowControlPlane
{
    public function start(string $workflowType, ?string $instanceId = null, array $options = []): array
    {
        $resolvedClass = $this->tryResolveWorkflowClass($workflowType);
        $arguments = $options['arguments'] ?? null;
        $connection = $options['connection'] ?? null;
        $queue = $options['queue'] ?? null;
        $businessKey = $options['business_key'] ?? null;
        $labels = $options['labels'] ?? null;
        $memo = $options['memo'] ?? null;
        $duplicatePolicy = ($options['duplicate_start_policy'] ?? null) === 'return_existing_active'
            ? DuplicateStartPolicy::ReturnExistingActive
            : DuplicateStartPolicy::RejectDuplicate;

        $workflowClass = $resolvedClass ?? $workflowType;

        /** @var WorkflowCommand|null $command */
        $command = null;
        $task = null;
        $instance = null;

        DB::transaction(function () use (
            $workflowType,
            $workflowClass,
            $resolvedClass,
            $instanceId,
            $arguments,
            $connection,
            $queue,
            $businessKey,
            $labels,
            $memo,
            $duplicatePolicy,
            &$command,
            &$task,
            &$instance,
        ): void {
            $instance = $this->resolveOrCreateInstance(
                $workflowType,
                $workflowClass,
                $instanceId,
            );

            $currentRun = CurrentRunResolver::forInstance($instance, lockForUpdate: true);
            CurrentRunResolver::syncPointer($instance, $currentRun);

            if ($currentRun instanceof WorkflowRun) {
                $canReturnExisting = $duplicatePolicy === DuplicateStartPolicy::ReturnExistingActive
                    && in_array($currentRun->status, [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting], true);

                $command = WorkflowCommand::record($instance, $currentRun, $this->commandAttributes([
                    'command_type' => CommandType::Start->value,
                    'target_scope' => 'instance',
                    'status' => $canReturnExisting
                        ? CommandStatus::Accepted->value
                        : CommandStatus::Rejected->value,
                    'outcome' => $canReturnExisting
                        ? CommandOutcome::ReturnedExistingActive->value
                        : CommandOutcome::RejectedDuplicate->value,
                    'payload_codec' => config('workflows.serializer'),
                    'payload' => is_string($arguments) ? $arguments : null,
                    'rejection_reason' => $canReturnExisting ? null : 'instance_already_started',
                    'accepted_at' => $canReturnExisting ? now() : null,
                    'applied_at' => $canReturnExisting ? now() : null,
                    'rejected_at' => $canReturnExisting ? null : now(),
                ]));

                WorkflowHistoryEvent::record($currentRun, $canReturnExisting
                    ? HistoryEventType::StartAccepted
                    : HistoryEventType::StartRejected, [
                        'workflow_command_id' => $command->id,
                        'workflow_instance_id' => $instance->id,
                        'workflow_run_id' => $currentRun->id,
                        'workflow_class' => $currentRun->workflow_class,
                        'workflow_type' => $currentRun->workflow_type,
                        'business_key' => $currentRun->business_key,
                        'visibility_labels' => $currentRun->visibility_labels,
                        'memo' => $currentRun->memo,
                        'outcome' => $command->outcome?->value,
                        'rejection_reason' => $command->rejection_reason,
                    ], null, $command);

                RunSummaryProjector::project(
                    $currentRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                );

                return;
            }

            $commandContract = $resolvedClass !== null
                ? RunCommandContract::snapshot($resolvedClass)
                : null;

            $fingerprint = $resolvedClass !== null
                ? WorkflowDefinition::fingerprint($resolvedClass)
                : null;

            if ($resolvedClass !== null && $connection === null) {
                $connection = $this->classPropertyDefault($resolvedClass, 'connection');
            }

            if ($resolvedClass !== null && $queue === null) {
                $queue = $this->classPropertyDefault($resolvedClass, 'queue');
            }

            if ($instance->workflow_class !== $workflowClass) {
                $instance->forceFill(['workflow_class' => $workflowClass])->save();
            }

            /** @var WorkflowRun $run */
            $run = $this->runQuery()->create([
                'workflow_instance_id' => $instance->id,
                'run_number' => $instance->run_count + 1,
                'workflow_class' => $workflowClass,
                'workflow_type' => $workflowType,
                'business_key' => $businessKey ?? $instance->business_key,
                'visibility_labels' => $labels ?? (is_array($instance->visibility_labels) ? $instance->visibility_labels : null),
                'memo' => $memo ?? (is_array($instance->memo) ? $instance->memo : null),
                'status' => RunStatus::Pending->value,
                'compatibility' => WorkerCompatibility::current(),
                'payload_codec' => config('workflows.serializer'),
                'arguments' => is_string($arguments) ? $arguments : null,
                'connection' => $connection,
                'queue' => $queue,
                'started_at' => now(),
                'last_progress_at' => now(),
                'last_history_sequence' => 0,
            ]);

            $command = WorkflowCommand::record($instance, $run, $this->commandAttributes([
                'command_type' => CommandType::Start->value,
                'target_scope' => 'instance',
                'status' => CommandStatus::Accepted->value,
                'outcome' => CommandOutcome::StartedNew->value,
                'payload_codec' => config('workflows.serializer'),
                'payload' => is_string($arguments) ? $arguments : null,
                'accepted_at' => now(),
                'applied_at' => now(),
            ]));

            $instance->forceFill([
                'current_run_id' => $run->id,
                'business_key' => $run->business_key,
                'visibility_labels' => $run->visibility_labels,
                'memo' => $run->memo,
                'started_at' => now(),
                'run_count' => $run->run_number,
            ])->save();

            WorkflowHistoryEvent::record($run, HistoryEventType::StartAccepted, [
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'workflow_class' => $run->workflow_class,
                'workflow_type' => $run->workflow_type,
                'business_key' => $run->business_key,
                'visibility_labels' => $run->visibility_labels,
                'memo' => $run->memo,
                'outcome' => $command->outcome?->value,
            ], null, $command);

            $startedPayload = array_filter([
                'workflow_class' => $run->workflow_class,
                'workflow_type' => $run->workflow_type,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'workflow_command_id' => $command->id,
                'business_key' => $run->business_key,
                'visibility_labels' => $run->visibility_labels,
                'memo' => $run->memo,
                'workflow_definition_fingerprint' => $fingerprint,
            ], static fn (mixed $v): bool => $v !== null);

            if ($commandContract !== null) {
                $startedPayload['declared_queries'] = $commandContract['queries'];
                $startedPayload['declared_query_contracts'] = $commandContract['query_contracts'];
                $startedPayload['declared_signals'] = $commandContract['signals'];
                $startedPayload['declared_signal_contracts'] = $commandContract['signal_contracts'];
                $startedPayload['declared_updates'] = $commandContract['updates'];
                $startedPayload['declared_update_contracts'] = $commandContract['update_contracts'];
                $startedPayload['declared_entry_method'] = $commandContract['entry_method'];
                $startedPayload['declared_entry_mode'] = $commandContract['entry_mode'];
                $startedPayload['declared_entry_declaring_class'] = $commandContract['entry_declaring_class'];
            }

            WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, $startedPayload, null, $command);

            /** @var WorkflowTask $task */
            $task = $this->taskQuery()->create([
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

        if ($task instanceof WorkflowTask) {
            try {
                TaskDispatcher::dispatch($task);
            } catch (Throwable) {
                // Dispatch is a delivery hint; the durable task is already persisted
                // and will be picked up by the next poll cycle even if queue dispatch
                // fails (e.g. sync driver in server environments).
            }
        }

        $accepted = $command instanceof WorkflowCommand
            && $command->status === CommandStatus::Accepted;

        return [
            'started' => $accepted,
            'workflow_instance_id' => $instance instanceof WorkflowInstance ? $instance->id : ($instanceId ?? ''),
            'workflow_run_id' => $command?->workflow_run_id,
            'workflow_type' => $workflowType,
            'outcome' => $command?->outcome?->value ?? 'unknown',
            'task_id' => $task instanceof WorkflowTask ? $task->id : null,
            'reason' => $accepted ? null : ($command?->rejection_reason ?? 'start_failed'),
        ];
    }

    public function signal(string $instanceId, string $name, array $options = []): array
    {
        $arguments = $options['arguments'] ?? [];

        try {
            $stub = WorkflowStub::load($instanceId);
            $result = $stub->signal($name, ...$arguments);

            return [
                'accepted' => true,
                'workflow_instance_id' => $instanceId,
                'workflow_command_id' => $result->commandId(),
                'reason' => null,
            ];
        } catch (ModelNotFoundException $e) {
            return [
                'accepted' => false,
                'workflow_instance_id' => $instanceId,
                'workflow_command_id' => null,
                'reason' => 'instance_not_found',
            ];
        } catch (LogicException $e) {
            return [
                'accepted' => false,
                'workflow_instance_id' => $instanceId,
                'workflow_command_id' => null,
                'reason' => $e->getMessage(),
            ];
        }
    }

    public function query(string $instanceId, string $name, array $options = []): array
    {
        $arguments = $options['arguments'] ?? [];

        try {
            $stub = WorkflowStub::load($instanceId);
            $result = $stub->query($name, ...$arguments);

            return [
                'success' => true,
                'workflow_instance_id' => $instanceId,
                'result' => $result,
                'reason' => null,
            ];
        } catch (ModelNotFoundException $e) {
            return [
                'success' => false,
                'workflow_instance_id' => $instanceId,
                'result' => null,
                'reason' => 'instance_not_found',
            ];
        } catch (WorkflowExecutionUnavailableException $e) {
            return [
                'success' => false,
                'workflow_instance_id' => $instanceId,
                'result' => null,
                'reason' => $e->getMessage(),
            ];
        } catch (LogicException $e) {
            return [
                'success' => false,
                'workflow_instance_id' => $instanceId,
                'result' => null,
                'reason' => $e->getMessage(),
            ];
        }
    }

    public function update(string $instanceId, string $name, array $options = []): array
    {
        $arguments = $options['arguments'] ?? [];

        try {
            $stub = WorkflowStub::load($instanceId);
            $result = $stub->submitUpdate($name, ...$arguments);

            return [
                'accepted' => $result->accepted(),
                'workflow_instance_id' => $instanceId,
                'update_id' => $result->updateId(),
                'reason' => $result->rejected() ? $result->rejectionReason() : null,
            ];
        } catch (ModelNotFoundException $e) {
            return [
                'accepted' => false,
                'workflow_instance_id' => $instanceId,
                'update_id' => null,
                'reason' => 'instance_not_found',
            ];
        } catch (LogicException $e) {
            return [
                'accepted' => false,
                'workflow_instance_id' => $instanceId,
                'update_id' => null,
                'reason' => $e->getMessage(),
            ];
        }
    }

    public function cancel(string $instanceId, array $options = []): array
    {
        $reason = $options['reason'] ?? null;

        try {
            $stub = WorkflowStub::load($instanceId);
            $result = $stub->attemptCancel(is_string($reason) ? $reason : null);

            return [
                'accepted' => $result->accepted(),
                'workflow_instance_id' => $instanceId,
                'workflow_command_id' => $result->commandId(),
                'reason' => $result->rejected() ? $result->rejectionReason() : null,
            ];
        } catch (ModelNotFoundException $e) {
            return [
                'accepted' => false,
                'workflow_instance_id' => $instanceId,
                'workflow_command_id' => null,
                'reason' => 'instance_not_found',
            ];
        } catch (LogicException $e) {
            return [
                'accepted' => false,
                'workflow_instance_id' => $instanceId,
                'workflow_command_id' => null,
                'reason' => $e->getMessage(),
            ];
        }
    }

    public function terminate(string $instanceId, array $options = []): array
    {
        $reason = $options['reason'] ?? null;

        try {
            $stub = WorkflowStub::load($instanceId);
            $result = $stub->attemptTerminate(is_string($reason) ? $reason : null);

            return [
                'accepted' => $result->accepted(),
                'workflow_instance_id' => $instanceId,
                'workflow_command_id' => $result->commandId(),
                'reason' => $result->rejected() ? $result->rejectionReason() : null,
            ];
        } catch (ModelNotFoundException $e) {
            return [
                'accepted' => false,
                'workflow_instance_id' => $instanceId,
                'workflow_command_id' => null,
                'reason' => 'instance_not_found',
            ];
        } catch (LogicException $e) {
            return [
                'accepted' => false,
                'workflow_instance_id' => $instanceId,
                'workflow_command_id' => null,
                'reason' => $e->getMessage(),
            ];
        }
    }

    private function tryResolveWorkflowClass(string $workflowType): ?string
    {
        try {
            return TypeRegistry::resolveWorkflowClass($workflowType, $workflowType);
        } catch (LogicException) {
            return null;
        }
    }

    private function resolveOrCreateInstance(
        string $workflowType,
        string $workflowClass,
        ?string $instanceId,
    ): WorkflowInstance {
        if ($instanceId === null) {
            /** @var WorkflowInstance $instance */
            $instance = $this->instanceQuery()->create([
                'workflow_class' => $workflowClass,
                'workflow_type' => $workflowType,
                'reserved_at' => now(),
                'run_count' => 0,
            ]);

            return $instance->fresh();
        }

        WorkflowInstanceId::assertValid($instanceId);

        $now = now();

        $this->instanceQuery()->insertOrIgnore([
            'id' => $instanceId,
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
            'reserved_at' => $now,
            'run_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var WorkflowInstance $instance */
        $instance = $this->instanceQuery()
            ->with('currentRun')
            ->lockForUpdate()
            ->findOrFail($instanceId);

        if ($instance->workflow_type !== $workflowType) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] is reserved for durable type [%s] and cannot be reused for [%s].',
                $instanceId,
                $instance->workflow_type,
                $workflowType,
            ));
        }

        if ($instance->workflow_class !== $workflowClass) {
            $instance->forceFill(['workflow_class' => $workflowClass])->save();
        }

        return $instance;
    }

    private function classPropertyDefault(string $workflowClass, string $property): ?string
    {
        $defaults = DefaultPropertyCache::for($workflowClass);

        return isset($defaults[$property]) && is_string($defaults[$property]) && $defaults[$property] !== ''
            ? $defaults[$property]
            : null;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function commandAttributes(array $attributes): array
    {
        return array_merge(CommandContext::controlPlane()->attributes(), $attributes);
    }

    private function instanceQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return ConfiguredV2Models::query('instance_model', WorkflowInstance::class);
    }

    private function runQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return ConfiguredV2Models::query('run_model', WorkflowRun::class);
    }

    private function taskQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return ConfiguredV2Models::query('task_model', WorkflowTask::class);
    }
}
