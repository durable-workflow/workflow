<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\DuplicateStartPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\InvalidQueryArgumentsException;
use Workflow\V2\Exceptions\WorkflowExecutionUnavailableException;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\UpdateResult;
use Workflow\V2\WorkflowStub;

final class DefaultWorkflowControlPlane implements WorkflowControlPlane
{
    public function start(string $workflowType, ?string $instanceId = null, array $options = []): array
    {
        $resolvedClass = $this->tryResolveWorkflowClass($workflowType);
        $arguments = $options['arguments'] ?? null;
        $payloadCodec = isset($options['payload_codec']) && is_string(
            $options['payload_codec']
        ) && $options['payload_codec'] !== ''
            ? CodecRegistry::canonicalize($options['payload_codec'])
            : CodecRegistry::defaultCodec();
        $connection = $options['connection'] ?? null;
        $queue = $options['queue'] ?? null;
        $businessKey = $options['business_key'] ?? null;
        $labels = $options['labels'] ?? null;
        $memo = $options['memo'] ?? null;
        $searchAttributes = $options['search_attributes'] ?? null;
        $namespace = $this->resolveNamespace($options);
        $commandContext = $this->commandContext($options);
        $executionTimeoutSeconds = isset($options['execution_timeout_seconds']) ? (int) $options['execution_timeout_seconds'] : null;
        $runTimeoutSeconds = isset($options['run_timeout_seconds']) ? (int) $options['run_timeout_seconds'] : null;
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
            $searchAttributes,
            $namespace,
            $commandContext,
            $executionTimeoutSeconds,
            $runTimeoutSeconds,
            $duplicatePolicy,
            $payloadCodec,
            &$command,
            &$task,
            &$instance,
        ): void {
            $instance = $this->resolveOrCreateInstance($workflowType, $workflowClass, $instanceId, $namespace);

            $currentRun = CurrentRunResolver::forInstance($instance, lockForUpdate: true);
            CurrentRunResolver::syncPointer($instance, $currentRun);

            if ($currentRun instanceof WorkflowRun) {
                $canReturnExisting = $duplicatePolicy === DuplicateStartPolicy::ReturnExistingActive
                    && in_array(
                        $currentRun->status,
                        [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting],
                        true
                    );

                $command = WorkflowCommand::record($instance, $currentRun, $this->commandAttributes($commandContext, [
                    'command_type' => CommandType::Start->value,
                    'target_scope' => 'instance',
                    'status' => $canReturnExisting
                        ? CommandStatus::Accepted->value
                        : CommandStatus::Rejected->value,
                    'outcome' => $canReturnExisting
                        ? CommandOutcome::ReturnedExistingActive->value
                        : CommandOutcome::RejectedDuplicate->value,
                    'payload_codec' => $currentRun->payload_codec ?? $payloadCodec,
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
                        'memo' => $currentRun->typedMemos(),
                        'search_attributes' => $currentRun->typedSearchAttributes(),
                        'outcome' => $command->outcome?->value,
                        'rejection_reason' => $command->rejection_reason,
                    ], null, $command);

                $this->projectRun(
                    $currentRun->fresh(
                        ['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents']
                    ) ?? $currentRun
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

            $startBlockedReason = WorkflowStartGate::blockedReason(
                WorkerCompatibility::current(),
                $connection,
                $queue,
            );

            if ($startBlockedReason !== null) {
                $blockedMessage = WorkflowStartGate::blockedMessage(
                    sprintf('Workflow instance [%s] cannot start.', $instance->id),
                    WorkerCompatibility::current(),
                    $connection,
                    $queue,
                ) ?? sprintf('Workflow instance [%s] cannot start.', $instance->id);

                $command = WorkflowCommand::record($instance, null, $this->commandAttributes($commandContext, [
                    'command_type' => CommandType::Start->value,
                    'target_scope' => 'instance',
                    'status' => CommandStatus::Rejected->value,
                    'outcome' => CommandOutcome::RejectedCompatibilityBlocked->value,
                    'payload_codec' => CodecRegistry::defaultCodec(),
                    'payload' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), array_filter([
                        'reason' => $startBlockedReason,
                        'message' => $blockedMessage,
                        'arguments_blob' => is_string($arguments) ? $arguments : null,
                    ], static fn (mixed $value): bool => $value !== null)),
                    'rejection_reason' => $startBlockedReason,
                    'rejected_at' => now(),
                ]));

                return;
            }

            if ($instance->workflow_class !== $workflowClass) {
                $instance->forceFill([
                    'workflow_class' => $workflowClass,
                ])->save();
            }

            $startedAt = now();
            $executionDeadlineAt = $executionTimeoutSeconds !== null && $executionTimeoutSeconds > 0
                ? $startedAt->copy()
                    ->addSeconds($executionTimeoutSeconds)
                : null;
            $runDeadlineAt = $runTimeoutSeconds !== null && $runTimeoutSeconds > 0
                ? $startedAt->copy()
                    ->addSeconds($runTimeoutSeconds)
                : null;

            /** @var WorkflowRun $run */
            $run = $this->runQuery()
                ->create([
                    'workflow_instance_id' => $instance->id,
                    'run_number' => $instance->run_count + 1,
                    'workflow_class' => $workflowClass,
                    'workflow_type' => $workflowType,
                    'namespace' => $namespace,
                    'business_key' => $businessKey ?? $instance->business_key,
                    'visibility_labels' => $labels ?? (is_array(
                        $instance->visibility_labels
                    ) ? $instance->visibility_labels : null),
                    'run_timeout_seconds' => $runTimeoutSeconds,
                    'execution_deadline_at' => $executionDeadlineAt,
                    'run_deadline_at' => $runDeadlineAt,
                    'status' => RunStatus::Pending->value,
                    'compatibility' => WorkerCompatibility::current(),
                    'payload_codec' => $payloadCodec,
                    'arguments' => is_string($arguments) ? $arguments : null,
                    'connection' => $connection,
                    'queue' => $queue,
                    'started_at' => $startedAt,
                    'last_progress_at' => $startedAt,
                    'last_history_sequence' => 0,
                ]);

            $this->seedTypedVisibilityMetadata(
                $run,
                is_array($memo) ? $memo : null,
                is_array($searchAttributes) ? $searchAttributes : null,
            );

            $command = WorkflowCommand::record($instance, $run, $this->commandAttributes($commandContext, [
                'command_type' => CommandType::Start->value,
                'target_scope' => 'instance',
                'status' => CommandStatus::Accepted->value,
                'outcome' => CommandOutcome::StartedNew->value,
                'payload_codec' => $payloadCodec,
                'payload' => is_string($arguments) ? $arguments : null,
                'accepted_at' => now(),
                'applied_at' => now(),
            ]));

            $instance->forceFill([
                'current_run_id' => $run->id,
                'business_key' => $run->business_key,
                'visibility_labels' => $run->visibility_labels,
                'memo' => $run->typedMemos(),
                'execution_timeout_seconds' => $executionTimeoutSeconds,
                'started_at' => $startedAt,
                'run_count' => $run->run_number,
            ])->save();

            $memoPayload = self::visibilityMetadataPayload($memo);
            $searchAttributesPayload = self::visibilityMetadataPayload($searchAttributes);

            WorkflowHistoryEvent::record($run, HistoryEventType::StartAccepted, [
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'workflow_class' => $run->workflow_class,
                'workflow_type' => $run->workflow_type,
                'business_key' => $run->business_key,
                'visibility_labels' => $run->visibility_labels,
                'memo' => $memoPayload,
                'search_attributes' => $searchAttributesPayload,
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
                'memo' => $memoPayload,
                'search_attributes' => $searchAttributesPayload,
                'execution_timeout_seconds' => $executionTimeoutSeconds,
                'run_timeout_seconds' => $runTimeoutSeconds,
                'execution_deadline_at' => $executionDeadlineAt?->toIso8601String(),
                'run_deadline_at' => $runDeadlineAt?->toIso8601String(),
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
            $task = $this->taskQuery()
                ->create([
                    'workflow_run_id' => $run->id,
                    'namespace' => $namespace,
                    'task_type' => TaskType::Workflow->value,
                    'status' => TaskStatus::Ready->value,
                    'available_at' => now(),
                    'payload' => [],
                    'connection' => $run->connection,
                    'queue' => $run->queue,
                    'compatibility' => $run->compatibility,
                ]);
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
            'message' => $accepted ? null : $command?->commandMessage(),
        ];
    }

    public function signal(string $instanceId, string $name, array $options = []): array
    {
        $loaded = $this->loadControlPlaneWorkflow($instanceId, $options);

        if (($loaded['error'] ?? null) !== null) {
            return $loaded['error'];
        }

        $stub = $loaded['workflow'] ?? null;

        if (! $stub instanceof WorkflowStub) {
            return $this->notFoundControlPlaneResult($instanceId, 'workflow_command_id');
        }

        $arguments = $this->commandArguments($options);
        $payloadCodec = $options['payload_codec'] ?? null;
        $payloadBlob = $options['payload_blob'] ?? null;

        $result = $stub
            ->withCommandContext($this->commandContext($options))
            ->attemptSignalWithArguments($name, $arguments, $payloadCodec, $payloadBlob);

        return array_merge(
            CommandResponse::payload($result),
            [
                'accepted' => $result->accepted(),
                'workflow_instance_id' => $instanceId,
                'workflow_command_id' => $result->commandId(),
                'signal_name' => $name,
                'command_reason' => $result->reason(),
                'reason' => $result->rejected() ? $result->rejectionReason() : null,
                'status' => $this->signalStatus($result),
            ],
        );
    }

    public function query(string $instanceId, string $name, array $options = []): array
    {
        $loaded = $this->loadControlPlaneWorkflow($instanceId, $options);

        if (($loaded['error'] ?? null) !== null) {
            return $loaded['error'];
        }

        $stub = $loaded['workflow'] ?? null;

        if (! $stub instanceof WorkflowStub) {
            return [
                'success' => false,
                'workflow_instance_id' => $instanceId,
                'workflow_id' => $instanceId,
                'result' => null,
                'reason' => 'instance_not_found',
                'status' => 404,
            ];
        }

        $queryName = $stub->resolveQueryTarget($name)['name'] ?? null;

        if ($queryName === null) {
            return [
                'success' => false,
                'workflow_instance_id' => $instanceId,
                'workflow_id' => $instanceId,
                'run_id' => $stub->runId(),
                'target_scope' => 'instance',
                'query_name' => $name,
                'result' => null,
                'reason' => 'query_not_found',
                'message' => sprintf('Workflow query [%s] is not declared on workflow [%s].', $name, $instanceId),
                'status' => 404,
            ];
        }

        try {
            $result = $stub->queryWithArguments($name, $this->commandArguments($options));
        } catch (InvalidQueryArgumentsException $exception) {
            return [
                'success' => false,
                'workflow_instance_id' => $instanceId,
                'workflow_id' => $instanceId,
                'run_id' => $stub->runId(),
                'target_scope' => 'instance',
                'query_name' => $exception->queryName(),
                'result' => null,
                'reason' => 'invalid_query_arguments',
                'message' => $exception->getMessage(),
                'validation_errors' => $exception->validationErrors(),
                'status' => 422,
            ];
        } catch (WorkflowExecutionUnavailableException $exception) {
            return [
                'success' => false,
                'workflow_instance_id' => $instanceId,
                'workflow_id' => $instanceId,
                'run_id' => $stub->runId(),
                'target_scope' => 'instance',
                'query_name' => $exception->targetName(),
                'result' => null,
                'reason' => $exception->blockedReason(),
                'blocked_reason' => $exception->blockedReason(),
                'message' => $exception->getMessage(),
                'status' => 409,
            ];
        } catch (LogicException $exception) {
            return [
                'success' => false,
                'workflow_instance_id' => $instanceId,
                'workflow_id' => $instanceId,
                'run_id' => $stub->runId(),
                'target_scope' => 'instance',
                'query_name' => $queryName,
                'result' => null,
                'reason' => 'query_rejected',
                'message' => $exception->getMessage(),
                'status' => 409,
            ];
        }

        $codec = $stub->payloadCodec();

        return [
            'success' => true,
            'workflow_instance_id' => $instanceId,
            'workflow_id' => $instanceId,
            'run_id' => $stub->runId(),
            'target_scope' => 'instance',
            'query_name' => $queryName,
            'result' => $result,
            'result_envelope' => $result !== null ? [
                'codec' => $codec,
                'blob' => Serializer::serializeWithCodec($codec, $result),
            ] : null,
            'reason' => null,
            'status' => 200,
        ];
    }

    public function update(string $instanceId, string $name, array $options = []): array
    {
        $loaded = $this->loadControlPlaneWorkflow($instanceId, $options);

        if (($loaded['error'] ?? null) !== null) {
            return $loaded['error'];
        }

        $stub = $loaded['workflow'] ?? null;

        if (! $stub instanceof WorkflowStub) {
            return $this->notFoundControlPlaneResult($instanceId, 'update_id');
        }

        $waitFor = array_key_exists('wait_for', $options)
            ? UpdateWaitPolicy::requestedWaitFor($options['wait_for'])
            : UpdateWaitPolicy::WAIT_FOR_ACCEPTED;

        $stub = $stub->withCommandContext($this->commandContext($options));

        if (($options['wait_timeout_seconds'] ?? null) !== null && $waitFor !== UpdateWaitPolicy::WAIT_FOR_ACCEPTED) {
            $stub = $stub->withUpdateWaitTimeout(
                UpdateWaitPolicy::requestedTimeoutSeconds($options['wait_timeout_seconds'] ?? null),
            );
        }

        $result = $waitFor === UpdateWaitPolicy::WAIT_FOR_ACCEPTED
            ? $stub->submitUpdateWithArguments($name, $this->commandArguments($options))
            : $stub->attemptUpdateWithArguments($name, $this->commandArguments($options));

        return array_merge(
            CommandResponse::payload($result),
            [
                'accepted' => $result->accepted(),
                'workflow_instance_id' => $instanceId,
                'update_id' => $result->updateId(),
                'update_name' => $result->updateName() ?? $name,
                'command_reason' => $result->reason(),
                'reason' => $result->rejected() ? $result->rejectionReason() : null,
                'status' => $this->updateStatus($result),
            ],
        );
    }

    public function cancel(string $instanceId, array $options = []): array
    {
        $loaded = $this->loadControlPlaneWorkflow($instanceId, $options);

        if (($loaded['error'] ?? null) !== null) {
            return $loaded['error'];
        }

        $stub = $loaded['workflow'] ?? null;

        if (! $stub instanceof WorkflowStub) {
            return $this->notFoundControlPlaneResult($instanceId, 'workflow_command_id');
        }

        $reason = is_string($options['reason'] ?? null) ? $options['reason'] : null;

        $result = $stub
            ->withCommandContext($this->commandContext($options))
            ->attemptCancel($reason);

        return array_merge(
            CommandResponse::payload($result),
            [
                'accepted' => $result->accepted(),
                'workflow_instance_id' => $instanceId,
                'workflow_command_id' => $result->commandId(),
                'command_reason' => $result->reason(),
                'reason' => $result->rejected() ? $result->rejectionReason() : null,
                'status' => $result->accepted() ? 200 : 409,
            ],
        );
    }

    public function terminate(string $instanceId, array $options = []): array
    {
        $loaded = $this->loadControlPlaneWorkflow($instanceId, $options);

        if (($loaded['error'] ?? null) !== null) {
            return $loaded['error'];
        }

        $stub = $loaded['workflow'] ?? null;

        if (! $stub instanceof WorkflowStub) {
            return $this->notFoundControlPlaneResult($instanceId, 'workflow_command_id');
        }

        $reason = is_string($options['reason'] ?? null) ? $options['reason'] : null;

        $result = $stub
            ->withCommandContext($this->commandContext($options))
            ->attemptTerminate($reason);

        return array_merge(
            CommandResponse::payload($result),
            [
                'accepted' => $result->accepted(),
                'workflow_instance_id' => $instanceId,
                'workflow_command_id' => $result->commandId(),
                'command_reason' => $result->reason(),
                'reason' => $result->rejected() ? $result->rejectionReason() : null,
                'status' => $result->accepted() ? 200 : 409,
            ],
        );
    }

    public function repair(string $instanceId, array $options = []): array
    {
        $loaded = $this->loadControlPlaneWorkflow($instanceId, $options);

        if (($loaded['error'] ?? null) !== null) {
            return $loaded['error'];
        }

        $stub = $loaded['workflow'] ?? null;

        if (! $stub instanceof WorkflowStub) {
            return $this->notFoundControlPlaneResult($instanceId, 'workflow_command_id');
        }

        $result = $stub
            ->withCommandContext($this->commandContext($options))
            ->attemptRepair();

        return array_merge(
            CommandResponse::payload($result),
            [
                'accepted' => $result->accepted(),
                'workflow_instance_id' => $instanceId,
                'workflow_command_id' => $result->commandId(),
                'command_reason' => $result->reason(),
                'reason' => $result->rejected() ? $result->rejectionReason() : null,
                'status' => $result->accepted() ? 200 : 409,
            ],
        );
    }

    public function archive(string $instanceId, array $options = []): array
    {
        $loaded = $this->loadControlPlaneWorkflow($instanceId, $options);

        if (($loaded['error'] ?? null) !== null) {
            return $loaded['error'];
        }

        $stub = $loaded['workflow'] ?? null;

        if (! $stub instanceof WorkflowStub) {
            return $this->notFoundControlPlaneResult($instanceId, 'workflow_command_id');
        }

        $reason = is_string($options['reason'] ?? null) ? $options['reason'] : null;

        $result = $stub
            ->withCommandContext($this->commandContext($options))
            ->attemptArchive($reason);

        return array_merge(
            CommandResponse::payload($result),
            [
                'accepted' => $result->accepted(),
                'workflow_instance_id' => $instanceId,
                'workflow_command_id' => $result->commandId(),
                'command_reason' => $result->reason(),
                'reason' => $result->rejected() ? $result->rejectionReason() : null,
                'status' => $result->accepted() ? 200 : 409,
            ],
        );
    }

    public function describe(string $instanceId, array $options = []): array
    {
        $runId = $options['run_id'] ?? null;

        $notFound = [
            'found' => false,
            'workflow_instance_id' => $instanceId,
            'workflow_type' => null,
            'workflow_class' => null,
            'business_key' => null,
            'run' => null,
            'run_count' => 0,
            'actions' => [
                'can_signal' => false,
                'can_query' => false,
                'can_update' => false,
                'can_cancel' => false,
                'can_terminate' => false,
                'can_repair' => false,
                'can_archive' => false,
            ],
            'reason' => 'instance_not_found',
        ];

        try {
            /** @var \Workflow\V2\Models\WorkflowInstance $instance */
            $instance = $this->instanceQuery()
                ->with('currentRun')
                ->findOrFail($instanceId);
        } catch (ModelNotFoundException) {
            return $notFound;
        }

        try {
            $run = is_string($runId)
                ? SelectedRunLocator::forInstanceOrFail($instance, $runId, ['summary'])
                : SelectedRunLocator::forInstanceOrFail($instance, null, ['summary']);
        } catch (ModelNotFoundException) {
            return array_merge($notFound, [
                'found' => true,
                'workflow_type' => $instance->workflow_type,
                'workflow_class' => $instance->workflow_class,
                'business_key' => $instance->business_key ?? null,
                'run_count' => (int) $instance->run_count,
                'reason' => 'run_not_found',
            ]);
        }

        $summary = $run->summary;
        $currentRun = $instance->currentRun;
        $isCurrentRun = $currentRun !== null && $currentRun->id === $run->id;
        $isOpen = ! $run->status->isTerminal();
        $classResolvable = $this->tryResolveWorkflowClass(
            $instance->workflow_type ?? $instance->workflow_class,
        ) !== null;

        return [
            'found' => true,
            'workflow_instance_id' => $instance->id,
            'workflow_type' => $instance->workflow_type,
            'workflow_class' => $instance->workflow_class,
            'namespace' => $instance->namespace ?? null,
            'business_key' => $instance->business_key ?? null,
            'execution_timeout_seconds' => $instance->execution_timeout_seconds !== null
                ? (int) $instance->execution_timeout_seconds
                : null,
            'run' => [
                'workflow_run_id' => $run->id,
                'run_number' => (int) $run->run_number,
                'is_current_run' => $isCurrentRun,
                'status' => $run->status->value,
                'status_bucket' => $run->status->statusBucket()
->value,
                'closed_reason' => $summary?->closed_reason,
                'compatibility' => $run->compatibility,
                'connection' => $run->connection,
                'queue' => $run->queue,
                'run_timeout_seconds' => $run->run_timeout_seconds !== null
                    ? (int) $run->run_timeout_seconds
                    : null,
                'execution_deadline_at' => $run->execution_deadline_at?->toIso8601String(),
                'run_deadline_at' => $run->run_deadline_at?->toIso8601String(),
                'started_at' => $run->started_at?->toIso8601String(),
                'closed_at' => $run->closed_at?->toIso8601String(),
                'last_progress_at' => $run->last_progress_at?->toIso8601String(),
                'wait_kind' => $summary?->wait_kind,
                'wait_reason' => $summary?->wait_reason,
            ],
            'run_count' => (int) $instance->run_count,
            'actions' => [
                'can_signal' => $isCurrentRun && $isOpen,
                'can_query' => $classResolvable && $isOpen,
                'can_update' => $isCurrentRun && $isOpen && $classResolvable,
                'can_cancel' => $isCurrentRun && $isOpen,
                'can_terminate' => $isCurrentRun && $isOpen,
                'can_repair' => $isCurrentRun && $isOpen,
                'can_archive' => $run->status->isTerminal() && $run->archived_at === null,
            ],
            'reason' => null,
        ];
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
        ?string $namespace = null,
    ): WorkflowInstance {
        if ($instanceId === null) {
            /** @var WorkflowInstance $instance */
            $instance = $this->instanceQuery()
                ->create([
                    'workflow_class' => $workflowClass,
                    'workflow_type' => $workflowType,
                    'namespace' => $namespace,
                    'reserved_at' => now(),
                    'run_count' => 0,
                ]);

            return $instance->fresh();
        }

        WorkflowInstanceId::assertValid($instanceId);

        $now = now();

        $this->instanceQuery()
            ->insertOrIgnore([
                'id' => $instanceId,
                'workflow_class' => $workflowClass,
                'workflow_type' => $workflowType,
                'namespace' => $namespace,
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
            $instance->forceFill([
                'workflow_class' => $workflowClass,
            ])->save();
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
    private function commandAttributes(CommandContext $commandContext, array $attributes): array
    {
        return array_merge($commandContext->attributes(), $attributes);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{
     *     workflow: WorkflowStub|null,
     *     error: array<string, mixed>|null,
     * }
     */
    private function loadControlPlaneWorkflow(string $instanceId, array $options): array
    {
        $namespace = isset($options['namespace']) && is_string($options['namespace'])
            ? $options['namespace']
            : null;

        try {
            $query = $this->instanceQuery();

            if ($namespace !== null) {
                $query->where('namespace', $namespace);
            }

            /** @var WorkflowInstance $instance */
            $instance = $query->findOrFail($instanceId);
        } catch (ModelNotFoundException) {
            return [
                'workflow' => null,
                'error' => null,
            ];
        }

        if (($options['strict_configured_type_validation'] ?? false) === true) {
            $run = CurrentRunResolver::forInstance($instance);

            if ($run instanceof WorkflowRun) {
                $message = is_string($run->workflow_type)
                    ? $this->configuredWorkflowValidationMessage($run->workflow_type)
                    : null;

                if ($message !== null) {
                    return [
                        'workflow' => null,
                        'error' => [
                            'workflow_instance_id' => $instanceId,
                            'workflow_id' => $instanceId,
                            'run_id' => $run->id,
                            'workflow_type' => $run->workflow_type,
                            'blocked_reason' => 'configured_workflow_type_invalid',
                            'reason' => 'configured_workflow_type_invalid',
                            'message' => $message,
                            'status' => 409,
                        ],
                    ];
                }
            }
        }

        return [
            'workflow' => WorkflowStub::load($instanceId, $namespace),
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int|string, mixed>
     */
    private function commandArguments(array $options): array
    {
        $arguments = $options['arguments'] ?? [];

        return is_array($arguments) ? $arguments : [];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function commandContext(array $options): CommandContext
    {
        $commandContext = $options['command_context'] ?? null;

        return $commandContext instanceof CommandContext
            ? $commandContext
            : CommandContext::controlPlane();
    }

    private function configuredWorkflowValidationMessage(string $workflowType): ?string
    {
        if (class_exists($workflowType) && is_subclass_of($workflowType, \Workflow\V2\Workflow::class)) {
            return null;
        }

        $configured = config('workflows.v2.types.workflows', []);

        if (! is_array($configured) || ! array_key_exists($workflowType, $configured)) {
            return null;
        }

        $workflowClass = $configured[$workflowType];

        if (! is_string($workflowClass) || ! class_exists($workflowClass) || ! is_subclass_of(
            $workflowClass,
            \Workflow\V2\Workflow::class
        )) {
            return sprintf(
                'Configured durable workflow type [%s] points to [%s], which is not a loadable workflow class.',
                $workflowType,
                is_scalar($workflowClass) ? (string) $workflowClass : get_debug_type($workflowClass),
            );
        }

        return null;
    }

    private function resolveNamespace(array $options): ?string
    {
        $namespace = $options['namespace'] ?? config('workflows.v2.namespace');

        return is_string($namespace) && trim($namespace) !== ''
            ? trim($namespace)
            : null;
    }

    private function notFoundControlPlaneResult(string $instanceId, string $idField): array
    {
        return [
            'accepted' => false,
            'workflow_instance_id' => $instanceId,
            'workflow_id' => $instanceId,
            $idField => null,
            'reason' => 'instance_not_found',
            'status' => 404,
        ];
    }

    private function signalStatus(\Workflow\V2\CommandResult $result): int
    {
        return match ($result->outcome()) {
            CommandOutcome::RejectedUnknownSignal->value => 404,
            CommandOutcome::RejectedInvalidArguments->value => 422,
            default => $result->accepted() ? 202 : 409,
        };
    }

    private function updateStatus(UpdateResult $result): int
    {
        return match (true) {
            $result->outcome() === CommandOutcome::RejectedUnknownUpdate
->value => 404,
            $result->outcome() === CommandOutcome::RejectedInvalidArguments
->value => 422,
            $result->rejected() => 409,
            $result->failed() => 422,
            $result->updateStatus() === 'accepted' => 202,
            default => 200,
        };
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

    private function projectRun(WorkflowRun $run): void
    {
        $this->historyProjectionRole()
            ->projectRun($run);
    }

    private function historyProjectionRole(): HistoryProjectionRole
    {
        /** @var HistoryProjectionRole $role */
        $role = app(HistoryProjectionRole::class);

        return $role;
    }

    /**
     * @param array<string, mixed>|null $memo
     * @param array<string, scalar|null>|null $searchAttributes
     */
    private function seedTypedVisibilityMetadata(WorkflowRun $run, ?array $memo, ?array $searchAttributes): void
    {
        if (is_array($memo) && $memo !== []) {
            app(MemoUpsertService::class)->upsert($run, new UpsertMemosCall($memo), 0);
            $run->unsetRelation('memos');
        }

        if (is_array($searchAttributes) && $searchAttributes !== []) {
            app(SearchAttributeUpsertService::class)->upsert(
                $run,
                new UpsertSearchAttributesCall($searchAttributes),
                0,
            );
            $run->unsetRelation('searchAttributes');
        }
    }

    /**
     * @param array<string, mixed>|null $values
     *
     * @return array<string, mixed>|null
     */
    private static function visibilityMetadataPayload(?array $values): ?array
    {
        return is_array($values) && $values !== [] ? $values : null;
    }
}
