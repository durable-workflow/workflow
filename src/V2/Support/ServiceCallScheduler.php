<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallFailureReason;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Exceptions\ServiceOperationAuthorizationException;
use Workflow\V2\Jobs\RunServiceCall;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceCallDispatch;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

final class ServiceCallScheduler
{
    public const STATE_SCHEDULED = 'scheduled';

    public const STATE_CLAIMED = 'claimed';

    public const STATE_COMPLETED = 'completed';

    private const AFTER_COMMIT_OPTION = '__schedule_service_call_after_commit';

    private const MIN_ORPHAN_GRACE_SECONDS = 3_600;

    private const ORPHAN_CLEANUP_BATCH_CAP = 100;

    private const EFFECT_TOKEN_OPTION = '__service_call_effect_token';

    private static ?object $afterCommitAuthority = null;

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function withAfterCommitAuthority(array $options): array
    {
        $options[self::AFTER_COMMIT_OPTION] = self::afterCommitAuthority();

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function hasAfterCommitAuthority(array $options): bool
    {
        return ($options[self::AFTER_COMMIT_OPTION] ?? null) === self::afterCommitAuthority();
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function schedule(
        WorkflowServiceCall $call,
        WorkflowServiceOperation $operation,
        array $options,
        int $maxAttempts,
    ): void {
        unset($options[self::AFTER_COMMIT_OPTION]);
        self::assertSharedConnection($call);
        $dispatch = self::dispatchQuery()
            ->lockForUpdate()
            ->find($call->id);

        if (! $dispatch instanceof WorkflowServiceCallDispatch) {
            /** @var WorkflowServiceCallDispatch $dispatch */
            $dispatch = self::dispatchQuery()->create([
                'service_call_id' => $call->id,
                'state' => self::STATE_SCHEDULED,
                'dispatch_options' => self::persistableOptions($operation, $options),
                'attempt' => 1,
                'max_attempts' => max(1, $maxAttempts),
                'available_at' => now(),
                'claim_generation' => 0,
            ]);
        }

        if ($dispatch->state === self::STATE_COMPLETED) {
            return;
        }

        $connection = $call->getConnection();
        $connection->afterCommit(static function () use ($call): void {
            self::enqueue($call->id);
        });
    }

    public static function enqueue(string $serviceCallId): bool
    {
        $availableAt = self::withLockedDispatch($serviceCallId, static function (
            WorkflowServiceCall $call,
            WorkflowServiceCallDispatch $dispatch,
        ): ?CarbonInterface {
            if (self::terminal($call) || $dispatch->state === self::STATE_COMPLETED) {
                self::completeDispatch($dispatch);

                return null;
            }

            if ($dispatch->state === self::STATE_CLAIMED
                && $dispatch->claim_expires_at !== null
                && $dispatch->claim_expires_at->isFuture()) {
                return null;
            }

            $now = now();
            $dispatch->forceFill([
                'dispatch_attempted_at' => $now,
                'repair_available_at' => $now->copy()
                    ->addSeconds(self::repairAfterSeconds()),
            ])->save();

            return $dispatch->available_at;
        });

        if (! $availableAt instanceof CarbonInterface) {
            return false;
        }

        $job = RunServiceCall::dispatch($serviceCallId);
        if ($availableAt->isFuture()) {
            $job->delay($availableAt);
        }

        return true;
    }

    public static function claim(string $serviceCallId): ?ServiceCallAttemptClaim
    {
        $callModel = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)->getModel();
        $connection = $callModel->getConnection();

        return $connection->transaction(static function () use ($serviceCallId): ?ServiceCallAttemptClaim {
            /** @var WorkflowServiceCall|null $call */
            $call = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
                ->lockForUpdate()
                ->find($serviceCallId);
            if (! $call instanceof WorkflowServiceCall) {
                return null;
            }
            self::assertSharedConnection($call);

            /** @var WorkflowServiceCallDispatch|null $dispatch */
            $dispatch = self::dispatchQuery()
                ->lockForUpdate()
                ->find($serviceCallId);
            if (! $dispatch instanceof WorkflowServiceCallDispatch || $dispatch->state === self::STATE_COMPLETED) {
                return null;
            }
            if (self::terminal($call)) {
                self::completeDispatch($dispatch);

                return null;
            }
            if (ServiceCallDeadline::expireLocked($call, now())) {
                self::completeDispatch($dispatch);

                return null;
            }
            if ($dispatch->available_at->isFuture()) {
                return null;
            }
            if ($dispatch->state === self::STATE_CLAIMED
                && ($dispatch->claim_expires_at === null || $dispatch->claim_expires_at->isFuture())) {
                return null;
            }

            $run = self::lockedCallerRun($call);
            if (! $run instanceof WorkflowRun
                || $run->status->isTerminal()
                || is_string($run->cancellation_request_command_id)) {
                self::cancelForTerminalCaller($call, $dispatch, $run);

                return null;
            }

            try {
                [$endpoint, $service, $operation] = self::lockedCatalog($call);
                $principal = self::acceptedPrincipal($call);
                $scope = [
                    'namespace' => $call->target_namespace,
                    'caller_namespace' => $run->namespace,
                    'caller_workflow_instance_id' => $run->workflow_instance_id,
                    'caller_workflow_run_id' => $run->id,
                    'endpoint_name' => $call->endpoint_name,
                    'service_name' => $call->service_name,
                    'operation_name' => $call->operation_name,
                ];
                WorkflowServiceOperationAuthority::authorizeExistingCall($call, $principal, $scope);
            } catch (ServiceOperationAuthorizationException $failure) {
                self::failAuthorization($call, $dispatch, $failure->getMessage());

                return null;
            }

            $catalogRevision = self::revision([
                'endpoint' => self::catalogRevisionFields($endpoint),
                'service' => self::catalogRevisionFields($service),
                'operation' => self::catalogRevisionFields($operation),
            ]);
            $policyRevision = self::revision([
                'endpoint' => $endpoint->boundary_policy,
                'service' => $service->boundary_policy,
                'operation' => $operation->boundary_policy,
            ]);
            $generation = (int) $dispatch->claim_generation + 1;
            $token = hash('sha256', (string) Str::uuid());
            $now = now();
            $options = is_array($dispatch->dispatch_options) ? $dispatch->dispatch_options : [];
            $options = self::withEffectToken(self::withClaimTargetIdentifiers($options));
            $dispatch->forceFill([
                'state' => self::STATE_CLAIMED,
                'dispatch_options' => $options,
                'claim_generation' => $generation,
                'claim_token' => $token,
                'claimed_at' => $now,
                'claim_expires_at' => $now->copy()
                    ->addSeconds(self::claimLeaseSeconds()),
                'authorized_catalog_revision' => $catalogRevision,
                'authorized_policy_revision' => $policyRevision,
                'repair_available_at' => null,
                'last_error' => null,
            ])->save();

            $effectToken = $options[self::EFFECT_TOKEN_OPTION];
            unset($options[self::EFFECT_TOKEN_OPTION]);
            $options['service_call_id'] = $call->id;
            $options['service_call_attempt'] = $dispatch->attempt;
            $options['service_call_max_attempts'] = $dispatch->max_attempts;
            $options['service_call_idempotency_key'] = self::attemptIdentity($call->id, $dispatch->attempt);

            $effectOperation = clone $operation;
            $effectOperation->forceFill([
                'handler_binding_kind' => $options['accepted_handler_binding_kind'] ?? null,
                'handler_binding' => $options['accepted_handler_binding'] ?? null,
                'handler_target_reference' => $options['accepted_handler_target_reference'] ?? null,
                'operation_mode' => $options['accepted_operation_mode'] ?? $call->operation_mode,
            ]);

            return new ServiceCallAttemptClaim(
                call: $call,
                operation: $effectOperation,
                request: self::boundaryRequest($call, $principal, $endpoint, $service, $operation),
                options: $options,
                attempt: $dispatch->attempt,
                maxAttempts: $dispatch->max_attempts,
                generation: $generation,
                token: $token,
                catalogRevision: $catalogRevision,
                policyRevision: $policyRevision,
                effectToken: $effectToken,
            );
        });
    }

    /**
     * @param array<string, mixed> $attemptEntry
     */
    public static function completeAttempt(
        ServiceCallAttemptClaim $claim,
        array $attemptEntry,
        bool $retry,
        ?float $backoffSeconds,
    ): bool {
        $reenqueue = false;
        $recorded = self::withLockedDispatch($claim->call->id, static function (
            WorkflowServiceCall $call,
            WorkflowServiceCallDispatch $dispatch,
        ) use ($claim, $attemptEntry, $retry, $backoffSeconds, &$reenqueue): bool {
            if (! self::ownsLockedClaim($dispatch, $claim)) {
                return false;
            }

            $metadata = is_array($call->metadata) ? $call->metadata : [];
            $attempts = isset($metadata['service_call_attempts']) && is_array($metadata['service_call_attempts'])
                ? array_values(array_filter($metadata['service_call_attempts'], 'is_array'))
                : [];
            $attempts[] = $attemptEntry;
            $metadata['service_call_attempts'] = $attempts;
            $metadata['retry_attempt_count'] = count($attempts);
            $call->metadata = $metadata;
            if ($call->status === ServiceCallStatus::Failed->value) {
                $outcomeMetadata = is_array($call->outcome_metadata) ? $call->outcome_metadata : [];
                $outcomeMetadata['service_call_attempts'] = $attempts;
                $outcomeMetadata['retry_attempt_count'] = count($attempts);
                $call->outcome_metadata = $outcomeMetadata;
            }

            if ($retry && ! self::terminal($call) && $dispatch->attempt < $dispatch->max_attempts) {
                $availableAt = now()
                    ->addMilliseconds((int) ceil(max(0.0, $backoffSeconds ?? 0.0) * 1000));
                $options = is_array($dispatch->dispatch_options) ? $dispatch->dispatch_options : [];
                $options[self::EFFECT_TOKEN_OPTION] = self::newEffectToken();
                $dispatch->forceFill([
                    'state' => self::STATE_SCHEDULED,
                    'dispatch_options' => $options,
                    'attempt' => $dispatch->attempt + 1,
                    'available_at' => $availableAt,
                    'claim_token' => null,
                    'claimed_at' => null,
                    'claim_expires_at' => null,
                    'dispatch_attempted_at' => null,
                    'repair_available_at' => null,
                    'completed_at' => null,
                ])->save();
                $reenqueue = true;
            } else {
                self::completeDispatch($dispatch);
            }

            $call->save();

            return true;
        });

        if ($recorded === true && $reenqueue) {
            self::enqueue($claim->call->id);
        }

        return $recorded === true;
    }

    public static function ownsLockedClaim(
        WorkflowServiceCallDispatch $dispatch,
        ServiceCallAttemptClaim $claim,
    ): bool {
        $options = is_array($dispatch->dispatch_options) ? $dispatch->dispatch_options : [];
        $effectToken = $options[self::EFFECT_TOKEN_OPTION] ?? null;

        return $dispatch->state === self::STATE_CLAIMED
            && $dispatch->attempt === $claim->attempt
            && $dispatch->claim_generation === $claim->generation
            && hash_equals((string) $dispatch->claim_token, $claim->token)
            && is_string($effectToken)
            && hash_equals($effectToken, $claim->effectToken());
    }

    public static function ownsClaim(ServiceCallAttemptClaim $claim): bool
    {
        return self::withLockedDispatch($claim->call->id, static function (
            WorkflowServiceCall $call,
            WorkflowServiceCallDispatch $dispatch,
        ) use ($claim): bool {
            if (self::terminal($call) || ! self::ownsLockedClaim($dispatch, $claim)) {
                return false;
            }
            if (ServiceCallDeadline::expireLocked($call, now())) {
                self::completeDispatch($dispatch);

                return false;
            }

            $run = self::lockedCallerRun($call);
            if (! $run instanceof WorkflowRun
                || $run->status->isTerminal()
                || is_string($run->cancellation_request_command_id)) {
                self::cancelForTerminalCaller($call, $dispatch, $run);

                return false;
            }

            return true;
        }) === true;
    }

    /**
     * @return list<string>
     */
    public static function dueIds(
        int $limit,
        array $runIds = [],
        ?string $instanceId = null,
        ?string $connection = null,
        ?string $queue = null,
    ): array {
        $now = now()
            ->utc()
            ->format('Y-m-d H:i:s.u');

        $query = self::dispatchQuery()
            ->where(static function ($query) use ($now): void {
                $query->where(static function ($scheduled) use ($now): void {
                    $scheduled->where('state', self::STATE_SCHEDULED)
                        ->where('available_at', '<=', $now)
                        ->where(static function ($repair) use ($now): void {
                            $repair->whereNull('repair_available_at')
                                ->orWhere('repair_available_at', '<=', $now);
                        });
                })->orWhere(static function ($claimed) use ($now): void {
                    $claimed->where('state', self::STATE_CLAIMED)
                        ->whereNotNull('claim_expires_at')
                        ->where('claim_expires_at', '<=', $now);
                });
            });
        if ($runIds !== []) {
            $query->whereHas('call', static fn ($call) => $call->whereIn('caller_workflow_run_id', $runIds));
        }
        if ($instanceId !== null) {
            $query->whereHas(
                'call',
                static fn ($call) => $call->where('caller_workflow_instance_id', $instanceId),
            );
        }
        if ($connection !== null || $queue !== null) {
            $query->whereHas('call.callerRun', static function ($run) use ($connection, $queue): void {
                if ($connection !== null) {
                    $run->where('connection', $connection);
                }
                if ($queue !== null) {
                    $run->where('queue', $queue);
                }
            });
        }

        return $query
            ->orderBy('available_at')
            ->orderBy('service_call_id')
            ->limit(max(1, $limit))
            ->pluck('service_call_id')
            ->all();
    }

    /**
     * @return array{candidates: int, enqueued: int, failures: list<array{call_id: string, message: string}>}
     */
    public static function repairDue(
        int $limit,
        array $runIds = [],
        ?string $instanceId = null,
        ?string $connection = null,
        ?string $queue = null,
    ): array {
        self::cleanupOrphans($limit);
        $ids = self::dueIds($limit, $runIds, $instanceId, $connection, $queue);
        $report = [
            'candidates' => count($ids),
            'enqueued' => 0,
            'failures' => [],
        ];
        foreach ($ids as $id) {
            try {
                if (self::enqueue($id)) {
                    $report['enqueued']++;
                }
            } catch (Throwable $failure) {
                report($failure);
                $report['failures'][] = [
                    'call_id' => $id,
                    'message' => $failure->getMessage(),
                ];
            }
        }

        return $report;
    }

    public static function cleanupOrphans(int $limit): int
    {
        $cutoff = now()
            ->subSeconds(self::orphanGraceSeconds())
            ->utc()
            ->format('Y-m-d H:i:s.u');
        $ids = self::dispatchQuery()
            ->whereDoesntHave('call')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('service_call_id')
            ->limit(max(1, min($limit, self::ORPHAN_CLEANUP_BATCH_CAP)))
            ->pluck('service_call_id')
            ->all();

        return $ids === []
            ? 0
            : self::dispatchQuery()
                ->whereKey($ids)
                ->whereDoesntHave('call')
                ->where('created_at', '<=', $cutoff)
                ->delete();
    }

    public static function attemptIdentity(string $serviceCallId, int $attempt): string
    {
        return sprintf('workflow-service-call:%s:attempt:%d', $serviceCallId, $attempt);
    }

    private static function afterCommitAuthority(): object
    {
        return self::$afterCommitAuthority ??= new \stdClass();
    }

    private static function lockedCallerRun(WorkflowServiceCall $call): ?WorkflowRun
    {
        if (! is_string($call->caller_workflow_run_id) || $call->caller_workflow_run_id === '') {
            return null;
        }

        /** @var WorkflowRun|null $run */
        $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->lockForUpdate()
            ->find($call->caller_workflow_run_id);
        if (! $run instanceof WorkflowRun
            || $run->id !== $call->caller_workflow_run_id
            || $run->workflow_instance_id !== $call->caller_workflow_instance_id
            || $run->namespace !== $call->caller_namespace) {
            return null;
        }

        return $run;
    }

    /**
     * @return array{WorkflowServiceEndpoint, WorkflowService, WorkflowServiceOperation}
     */
    private static function lockedCatalog(WorkflowServiceCall $call): array
    {
        /** @var WorkflowServiceEndpoint|null $endpoint */
        $endpoint = ConfiguredV2Models::query('service_endpoint_model', WorkflowServiceEndpoint::class)
            ->lockForUpdate()
            ->find($call->workflow_service_endpoint_id);
        /** @var WorkflowService|null $service */
        $service = ConfiguredV2Models::query('service_model', WorkflowService::class)
            ->lockForUpdate()
            ->find($call->workflow_service_id);
        /** @var WorkflowServiceOperation|null $operation */
        $operation = ConfiguredV2Models::query('service_operation_model', WorkflowServiceOperation::class)
            ->lockForUpdate()
            ->find($call->workflow_service_operation_id);
        if (! $endpoint instanceof WorkflowServiceEndpoint
            || ! $service instanceof WorkflowService
            || ! $operation instanceof WorkflowServiceOperation) {
            throw new ServiceOperationAuthorizationException('service_call_not_owned');
        }

        return [$endpoint, $service, $operation];
    }

    private static function acceptedPrincipal(WorkflowServiceCall $call): ServiceCallPrincipal
    {
        if (! is_string($call->caller_principal_subject) || trim($call->caller_principal_subject) === ''
            || ! is_string($call->caller_principal_method) || trim($call->caller_principal_method) === '') {
            throw new ServiceOperationAuthorizationException('service_operation_replay_authority_incomplete');
        }
        if ($call->caller_principal_roles !== null && ! is_array($call->caller_principal_roles)) {
            throw new ServiceOperationAuthorizationException('service_operation_replay_authority_incomplete');
        }
        if ($call->caller_principal_claims !== null && ! is_array($call->caller_principal_claims)) {
            throw new ServiceOperationAuthorizationException('service_operation_replay_authority_incomplete');
        }

        return ServiceCallPrincipal::fromAuditArray([
            'subject' => $call->caller_principal_subject,
            'method' => $call->caller_principal_method,
            'roles' => $call->caller_principal_roles,
            'tenant' => $call->caller_principal_tenant,
            'claims' => $call->caller_principal_claims,
        ]);
    }

    private static function boundaryRequest(
        WorkflowServiceCall $call,
        ServiceCallPrincipal $principal,
        WorkflowServiceEndpoint $endpoint,
        WorkflowService $service,
        WorkflowServiceOperation $operation,
    ): ServiceBoundaryRequest {
        return new ServiceBoundaryRequest(
            principal: $principal,
            callerNamespace: $call->caller_namespace,
            targetNamespace: $call->target_namespace ?? '',
            endpointName: $call->endpoint_name,
            serviceName: $call->service_name,
            operationName: $call->operation_name,
            operationMode: ServiceCallOperationMode::tryFromCatalog($call->operation_mode)
                ?? ServiceCallOperationMode::Async,
            resolvedBindingKind: $call->resolved_binding_kind,
            resolvedTargetReference: $call->resolved_target_reference,
            callerWorkflowInstanceId: $call->caller_workflow_instance_id,
            callerWorkflowRunId: $call->caller_workflow_run_id,
            linkedWorkflowInstanceId: $call->linked_workflow_instance_id,
            linkedWorkflowRunId: $call->linked_workflow_run_id,
            linkedWorkflowUpdateId: $call->linked_workflow_update_id,
            idempotencyKey: $call->idempotency_key,
            endpointBoundaryPolicy: is_array($endpoint->boundary_policy) ? $endpoint->boundary_policy : [],
            serviceBoundaryPolicy: is_array($service->boundary_policy) ? $service->boundary_policy : [],
            operationBoundaryPolicy: is_array($operation->boundary_policy) ? $operation->boundary_policy : [],
            deadlinePolicy: is_array($call->deadline_policy) ? $call->deadline_policy : null,
            idempotencyPolicy: is_array($call->idempotency_policy) ? $call->idempotency_policy : null,
            cancellationPolicy: is_array($call->cancellation_policy) ? $call->cancellation_policy : null,
            retryPolicy: is_array($call->retry_policy) ? $call->retry_policy : null,
        );
    }

    private static function cancelForTerminalCaller(
        WorkflowServiceCall $call,
        WorkflowServiceCallDispatch $dispatch,
        ?WorkflowRun $run,
    ): void {
        $cancellationRequested = $run instanceof WorkflowRun
            && is_string($run->cancellation_request_command_id);
        $reason = $cancellationRequested ? 'caller_cancellation_requested' : 'caller_run_terminal';
        $message = $cancellationRequested
            ? 'Caller workflow cancellation was requested before the service-call effect was invoked.'
            : ($run instanceof WorkflowRun
                ? sprintf('Caller workflow run is terminal (%s).', $run->status->value)
                : 'Caller workflow authority is unavailable.');
        $call->forceFill([
            'status' => ServiceCallStatus::Cancelled->value,
            'outcome' => ServiceCallOutcome::Cancelled->value,
            'outcome_category' => ServiceCallOutcome::Cancelled->category(),
            'outcome_reason' => $reason,
            'outcome_message' => $message,
            'failure_message' => $message,
            'outcome_metadata' => [
                'failure_reason' => ServiceCallFailureReason::Cancellation->value,
                'caller_run_status' => $run?->status->value,
                'cancellation_request_command_id' => $cancellationRequested
                    ? $run->cancellation_request_command_id
                    : null,
            ],
            'cancelled_at' => now(),
        ])->save();
        self::completeDispatch($dispatch);
        if (! $cancellationRequested) {
            ServiceCallWorkflowDelivery::enqueue($call);
        }
    }

    private static function failAuthorization(
        WorkflowServiceCall $call,
        WorkflowServiceCallDispatch $dispatch,
        string $reason,
    ): void {
        $reason = trim($reason) !== '' ? $reason : 'service_operation_authority_invalid';
        $call->forceFill([
            'status' => ServiceCallStatus::Failed->value,
            'outcome' => ServiceCallOutcome::RejectedForbidden->value,
            'outcome_category' => ServiceCallOutcome::RejectedForbidden->category(),
            'outcome_reason' => $reason,
            'outcome_message' => $reason,
            'failure_message' => $reason,
            'outcome_metadata' => [
                'failure_reason' => ServiceCallFailureReason::PolicyRejection->value,
            ],
            'failed_at' => now(),
        ])->save();
        $dispatch->last_error = $reason;
        self::completeDispatch($dispatch);
        ServiceCallWorkflowDelivery::enqueue($call);
    }

    private static function completeDispatch(WorkflowServiceCallDispatch $dispatch): void
    {
        $options = is_array($dispatch->dispatch_options) ? $dispatch->dispatch_options : [];
        unset($options[self::EFFECT_TOKEN_OPTION]);
        $dispatch->forceFill([
            'state' => self::STATE_COMPLETED,
            'dispatch_options' => $options,
            'claim_token' => null,
            'claimed_at' => null,
            'claim_expires_at' => null,
            'repair_available_at' => null,
            'completed_at' => $dispatch->completed_at ?? now(),
        ])->save();
    }

    private static function terminal(WorkflowServiceCall $call): bool
    {
        return in_array($call->status, [
            ServiceCallStatus::Completed->value,
            ServiceCallStatus::Failed->value,
            ServiceCallStatus::Cancelled->value,
        ], true);
    }

    private static function assertSharedConnection(WorkflowServiceCall $call): void
    {
        $dispatch = self::dispatchQuery()->getModel();
        if ($dispatch->getConnection() !== $call->getConnection()) {
            throw new \RuntimeException('service_operation_storage_connection_mismatch');
        }
    }

    /**
     * @param callable(WorkflowServiceCall, WorkflowServiceCallDispatch): mixed $callback
     */
    private static function withLockedDispatch(string $serviceCallId, callable $callback): mixed
    {
        $callModel = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)->getModel();

        return $callModel->getConnection()
            ->transaction(static function () use ($serviceCallId, $callback): mixed {
                /** @var WorkflowServiceCall|null $call */
                $call = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
                    ->lockForUpdate()
                    ->find($serviceCallId);
                if (! $call instanceof WorkflowServiceCall) {
                    return null;
                }
                self::assertSharedConnection($call);
                /** @var WorkflowServiceCallDispatch|null $dispatch */
                $dispatch = self::dispatchQuery()
                    ->lockForUpdate()
                    ->find($serviceCallId);
                if (! $dispatch instanceof WorkflowServiceCallDispatch) {
                    return null;
                }

                return $callback($call, $dispatch);
            });
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function persistableOptions(WorkflowServiceOperation $operation, array $options): array
    {
        $persisted = [];
        foreach ([
            'arguments', 'payload_blob', 'payload_codec', 'target_workflow_instance_id',
            'target_workflow_run_id', 'workflow_instance_id', 'connection', 'queue', 'business_key',
            'labels', 'memo', 'search_attributes', 'duplicate_start_policy', 'request_payload_reference',
            'activity_execution_id', 'owning_workflow_instance_id', 'owning_workflow_run_id',
            'carrier_request_id', 'bound_workflow_instance_id',
        ] as $key) {
            $value = $options[$key] ?? null;
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || is_array($value)) {
                $persisted[$key] = $value;
            }
        }
        $persisted['wait_for'] = UpdateWaitPolicy::WAIT_FOR_ACCEPTED;
        $persisted['accepted_handler_binding_kind'] = $operation->handler_binding_kind;
        $binding = is_array($operation->handler_binding) ? $operation->handler_binding : [];
        $persisted['accepted_handler_binding'] = $binding;
        $persisted['accepted_handler_target_reference'] = $operation->handler_target_reference;
        $persisted['accepted_operation_mode'] = $operation->operation_mode;
        $persisted[self::EFFECT_TOKEN_OPTION] = self::newEffectToken();

        $kind = ServiceCallBindingKind::tryFromHandlerBindingKind((string) $operation->handler_binding_kind);
        if ($kind === ServiceCallBindingKind::WorkflowRun
            && ! self::hasString($binding, ['workflow_instance_id', 'instance_id'])
            && ! self::hasString($persisted, ['workflow_instance_id'])) {
            $persisted['workflow_instance_id'] = (string) Str::ulid();
        }

        return $persisted;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function withEffectToken(array $options): array
    {
        $token = $options[self::EFFECT_TOKEN_OPTION] ?? null;
        if (! is_string($token) || preg_match('/^service-call-v1:[a-f0-9]{64}$/D', $token) !== 1) {
            $options[self::EFFECT_TOKEN_OPTION] = self::newEffectToken();
        }

        return $options;
    }

    private static function newEffectToken(): string
    {
        return 'service-call-v1:' . bin2hex(random_bytes(32));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function withClaimTargetIdentifiers(array $options): array
    {
        $binding = is_array($options['accepted_handler_binding'] ?? null)
            ? $options['accepted_handler_binding']
            : [];
        $kind = ServiceCallBindingKind::tryFromHandlerBindingKind(
            (string) ($options['accepted_handler_binding_kind'] ?? ''),
        );
        if ($kind === ServiceCallBindingKind::ActivityExecution
            && ! self::hasString($binding, ['activity_execution_id'])
            && ! self::hasString($options, ['activity_execution_id'])) {
            $options['activity_execution_id'] = (string) Str::ulid();
        }
        if ($kind === ServiceCallBindingKind::InvocableCarrierRequest
            && ! self::hasString($binding, ['carrier_request_id'])
            && ! self::hasString($options, ['carrier_request_id'])) {
            $options['carrier_request_id'] = (string) Str::ulid();
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $keys
     */
    private static function hasString(array $values, array $keys): bool
    {
        foreach ($keys as $key) {
            if (is_string($values[$key] ?? null) && trim($values[$key]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function catalogRevisionFields(object $model): array
    {
        return [
            'id' => $model->getAttribute('id'),
            'updated_at' => $model->getRawOriginal('updated_at'),
            'boundary_policy' => $model->getAttribute('boundary_policy'),
            'handler_binding_kind' => $model->getAttribute('handler_binding_kind'),
            'handler_binding' => $model->getAttribute('handler_binding'),
            'handler_target_reference' => $model->getAttribute('handler_target_reference'),
        ];
    }

    private static function revision(array $value): string
    {
        self::sortRecursive($value);

        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function sortRecursive(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$child) {
            if (is_array($child)) {
                self::sortRecursive($child);
            }
        }
    }

    private static function claimLeaseSeconds(): int
    {
        return max(1, (int) config('workflows.v2.service_call_dispatch.claim_lease_seconds', 60));
    }

    private static function repairAfterSeconds(): int
    {
        return max(1, (int) config('workflows.v2.service_call_dispatch.repair_after_seconds', 5));
    }

    private static function orphanGraceSeconds(): int
    {
        return max(
            self::MIN_ORPHAN_GRACE_SECONDS,
            (int) config('workflows.v2.service_call_dispatch.orphan_grace_seconds', 3_600),
        );
    }

    /**
     * @return Builder<WorkflowServiceCallDispatch>
     */
    private static function dispatchQuery(): Builder
    {
        return ConfiguredV2Models::query('service_call_dispatch_model', WorkflowServiceCallDispatch::class);
    }
}
