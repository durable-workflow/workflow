<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use DateTimeInterface;
use Illuminate\Support\Str;
use Throwable;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallFailureReason;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

/**
 * Durable service-control-plane adapter.
 *
 * The default implementation owns the durable service-call row lifecycle and
 * maps workflow-backed handler bindings onto the existing namespace-aware
 * WorkflowControlPlane. Hosts can still decorate or replace this binding for
 * activity and invocable-carrier transports, but boundary resolution, policy
 * outcomes, and audit rows are usable from the package by default.
 */
final class DefaultServiceControlPlane implements ServiceControlPlane
{
    private const UNRESOLVED = 'unresolved';

    public function __construct(
        private ?WorkflowControlPlane $workflowControlPlane = null,
        private ?ServiceBoundaryPolicy $boundaryPolicy = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function execute(string $endpointName, string $serviceName, string $operationName, array $options = []): array
    {
        $namespace = $this->targetNamespace($options);
        $endpointKey = $this->contractName($endpointName);
        $serviceKey = $this->contractName($serviceName);
        $operationKey = $this->contractName($operationName);

        $explicitCallId = $this->stringFrom($options['service_call_id'] ?? null);

        if ($explicitCallId === null && ($idempotent = $this->idempotentCall($namespace, $endpointKey, $serviceKey, $operationKey, $options)) !== null) {
            return $this->serialize($idempotent) + [
                'accepted' => $this->acceptedShape($idempotent),
                'idempotent_replay' => true,
                'reason' => null,
            ];
        }

        $call = $this->existingCall($explicitCallId, $namespace);
        if ($call instanceof WorkflowServiceCall && $namespace === null) {
            $namespace = $this->stringFrom($call->target_namespace)
                ?? $this->stringFrom($call->namespace);
        }

        if ($explicitCallId !== null && ! $call instanceof WorkflowServiceCall) {
            return [
                'accepted' => false,
                'idempotent_replay' => false,
                'service_call_id' => $explicitCallId,
                'namespace' => $namespace,
                'endpoint_name' => $endpointKey,
                'service_name' => $serviceKey,
                'operation_name' => $operationKey,
                'reason' => 'service_call_not_found',
            ] + $this->emptyCallShape();
        }

        $preAdmitted = $call instanceof WorkflowServiceCall
            && $this->stringOption($options, 'boundary_policy_outcome') === ServiceCallOutcome::Accepted->value
            && $this->outcomeValue($call) === ServiceCallOutcome::Accepted->value
            && (string) $call->status === ServiceCallStatus::Accepted->value;

        if ($call instanceof WorkflowServiceCall && $this->isTerminal($call)) {
            return $this->serialize($call) + [
                'accepted' => $this->acceptedShape($call),
                'idempotent_replay' => false,
                'reason' => null,
            ];
        }

        if (
            $call instanceof WorkflowServiceCall
            && $explicitCallId !== null
            && (string) $call->status === ServiceCallStatus::Started->value
        ) {
            return $this->serialize($call) + [
                'accepted' => true,
                'idempotent_replay' => false,
                'reason' => null,
            ];
        }

        $call ??= $this->newCall();
        $principal = $this->principal($options);
        $callerNamespace = $this->namespaceOption($options, 'caller_namespace')
            ?? ($call instanceof WorkflowServiceCall ? $this->stringFrom($call->caller_namespace) : null)
            ?? $this->configuredNamespace();

        $this->stampPending(
            $call,
            $namespace,
            $callerNamespace,
            $endpointKey,
            $serviceKey,
            $operationKey,
            $principal,
            $options,
        );
        $call->save();

        $endpoint = $this->endpoint($namespace, $endpointKey);

        if (! $endpoint instanceof WorkflowServiceEndpoint) {
            $this->markResolutionFailure($call, 'endpoint');

            return $this->serialize($call) + [
                'accepted' => false,
                'idempotent_replay' => false,
                'reason' => 'endpoint_not_found',
            ];
        }

        $service = $this->service($namespace, $endpoint, $serviceKey);

        if (! $service instanceof WorkflowService) {
            $call->workflow_service_endpoint_id = $endpoint->id;
            $this->markResolutionFailure($call, 'service');

            return $this->serialize($call) + [
                'accepted' => false,
                'idempotent_replay' => false,
                'reason' => 'service_not_found',
            ];
        }

        $operation = $this->operation($namespace, $service, $operationKey);

        if (! $operation instanceof WorkflowServiceOperation) {
            $call->workflow_service_endpoint_id = $endpoint->id;
            $call->workflow_service_id = $service->id;
            $this->markResolutionFailure($call, 'operation');

            return $this->serialize($call) + [
                'accepted' => false,
                'idempotent_replay' => false,
                'reason' => 'operation_not_found',
            ];
        }

        $resolvedBindingKind = $this->resolvedBindingKind($operation);
        $this->applyResolvedCatalog($call, $endpoint, $service, $operation, $namespace, $options, $resolvedBindingKind);

        if ($resolvedBindingKind === null) {
            $this->markUnknownBindingKind($call, $operation);

            return $this->serialize($call) + [
                'accepted' => false,
                'idempotent_replay' => false,
                'reason' => 'unknown_binding_kind',
            ];
        }

        $boundaryRequest = $this->boundaryRequest(
            $principal,
            $callerNamespace,
            $namespace,
            $endpoint,
            $service,
            $operation,
            $call,
            $resolvedBindingKind,
        );

        $decision = $preAdmitted
            ? ServiceBoundaryDecision::allow(
                policyName: $call->policy_name ?? 'pre_admitted',
                metadata: $this->arrayValue($call->outcome_metadata),
            )
            : $this->boundaryPolicy()->evaluate($boundaryRequest);

        if ($decision->isDenied()) {
            $this->markBoundaryRejection($call, $boundaryRequest, $decision);

            return $this->serialize($call) + [
                'accepted' => false,
                'idempotent_replay' => false,
                'reason' => $decision->reason,
            ];
        }

        $this->markAccepted($call, $boundaryRequest, $decision);

        if (($options['dispatch_handler'] ?? true) === false) {
            return $this->serialize($call) + [
                'accepted' => true,
                'idempotent_replay' => false,
                'reason' => null,
            ];
        }

        $handler = $this->dispatchHandler($call, $operation, $boundaryRequest, $options);
        $call->refresh();

        return $this->serialize($call) + [
            'accepted' => $this->acceptedShape($call),
            'idempotent_replay' => false,
            'handler' => $handler,
            'reason' => $this->isFailed($call) ? ($call->outcome_reason ?? 'handler_failed') : null,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function describeCall(string $serviceCallId, array $options = []): array
    {
        $call = $this->call($serviceCallId, $options);

        if (! $call) {
            return ['found' => false, 'service_call_id' => $serviceCallId] + $this->emptyCallShape();
        }

        return ['found' => true] + $this->serialize($call);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function cancelCall(string $serviceCallId, array $options = []): array
    {
        $call = $this->call($serviceCallId, $options);

        if (! $call) {
            return [
                'accepted' => false,
                'service_call_id' => $serviceCallId,
                'namespace' => $options['namespace'] ?? null,
                'status' => null,
                'linked_workflow_instance_id' => null,
                'linked_workflow_run_id' => null,
                'linked_workflow_update_id' => null,
                'reason' => 'service_call_not_found',
            ];
        }

        if ($this->isTerminal($call)) {
            return $this->serialize($call) + [
                'accepted' => false,
                'reason' => 'service_call_terminal',
            ];
        }

        if (! $this->cancellationAllowed($call->cancellation_policy)) {
            return $this->serialize($call) + [
                'accepted' => false,
                'reason' => 'cancellation_not_allowed',
            ];
        }

        $metadata = $this->arrayValue($call->metadata);

        if (
            ($this->arrayValue($call->cancellation_policy)['propagate_to_linked_workflow'] ?? false) === true
            && is_string($call->linked_workflow_instance_id)
            && $call->linked_workflow_instance_id !== ''
        ) {
            $result = $this->workflows()->cancel($call->linked_workflow_instance_id, [
                'namespace' => $call->target_namespace,
                'reason' => $this->stringOption($options, 'reason') ?? 'service_call_cancelled',
            ]);

            $metadata['linked_cancel'] = $this->publicResult($result);
        }

        $now = now();
        $call->status = ServiceCallStatus::Cancelled->value;
        $call->outcome = ServiceCallOutcome::Cancelled->value;
        $call->outcome_category = ServiceCallOutcome::Cancelled->category();
        $call->outcome_reason = 'cancelled_by_request';
        $call->outcome_message = $this->stringOption($options, 'reason');
        $call->outcome_metadata = [
            'failure_reason' => ServiceCallFailureReason::Cancellation->value,
        ];
        $call->metadata = $metadata === [] ? null : $metadata;
        $call->cancelled_at ??= $now;
        $call->failure_message = $this->stringOption($options, 'reason') ?? $call->failure_message;
        $call->save();

        return $this->serialize($call) + [
            'accepted' => true,
            'reason' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(WorkflowServiceCall $call): array
    {
        return [
            'service_call_id' => $call->id,
            'namespace' => $call->namespace,
            'caller_namespace' => $call->caller_namespace,
            'target_namespace' => $call->target_namespace,
            'endpoint_name' => $call->endpoint_name,
            'service_name' => $call->service_name,
            'operation_name' => $call->operation_name,
            'operation_mode' => $call->operation_mode,
            'status' => $call->status,
            'outcome' => $this->outcomeValue($call),
            'outcome_category' => $call->outcome_category,
            'outcome_reason' => $call->outcome_reason,
            'resolved_binding_kind' => $call->resolved_binding_kind,
            'resolved_target_reference' => $call->resolved_target_reference,
            'linked_workflow_instance_id' => $call->linked_workflow_instance_id,
            'linked_workflow_run_id' => $call->linked_workflow_run_id,
            'linked_workflow_update_id' => $call->linked_workflow_update_id,
            'payload_codec' => $call->payload_codec,
            'input_payload_reference' => $call->input_payload_reference,
            'output_payload_reference' => $call->output_payload_reference,
            'failure_payload_reference' => $call->failure_payload_reference,
            'accepted_at' => $this->timestamp($call->accepted_at),
            'started_at' => $this->timestamp($call->started_at),
            'completed_at' => $this->timestamp($call->completed_at),
            'failed_at' => $this->timestamp($call->failed_at),
            'cancelled_at' => $this->timestamp($call->cancelled_at),
            'failure_message' => $call->failure_message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCallShape(): array
    {
        return [
            'namespace' => null,
            'caller_namespace' => null,
            'target_namespace' => null,
            'endpoint_name' => null,
            'service_name' => null,
            'operation_name' => null,
            'operation_mode' => null,
            'status' => null,
            'outcome' => null,
            'outcome_category' => null,
            'outcome_reason' => null,
            'resolved_binding_kind' => null,
            'resolved_target_reference' => null,
            'linked_workflow_instance_id' => null,
            'linked_workflow_run_id' => null,
            'linked_workflow_update_id' => null,
            'payload_codec' => null,
            'input_payload_reference' => null,
            'output_payload_reference' => null,
            'failure_payload_reference' => null,
            'accepted_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'cancelled_at' => null,
            'failure_message' => null,
            'reason' => 'service_call_not_found',
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function targetNamespace(array $options): ?string
    {
        return $this->namespaceOption($options, 'target_namespace')
            ?? $this->namespaceOption($options, 'namespace')
            ?? $this->configuredNamespace();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function namespaceOption(array $options, string $key): ?string
    {
        return isset($options[$key]) && is_string($options[$key]) && trim($options[$key]) !== ''
            ? trim($options[$key])
            : null;
    }

    private function configuredNamespace(): ?string
    {
        $namespace = config('workflows.v2.namespace');

        return is_string($namespace) && trim($namespace) !== ''
            ? trim($namespace)
            : null;
    }

    private function endpoint(?string $namespace, string $endpointName): ?WorkflowServiceEndpoint
    {
        $model = ConfiguredV2Models::resolve('service_endpoint_model', WorkflowServiceEndpoint::class);

        return $model::query()
            ->where('namespace', $namespace)
            ->where('endpoint_name', $endpointName)
            ->first();
    }

    private function service(?string $namespace, WorkflowServiceEndpoint $endpoint, string $serviceName): ?WorkflowService
    {
        $model = ConfiguredV2Models::resolve('service_model', WorkflowService::class);

        return $model::query()
            ->where('namespace', $namespace)
            ->where('workflow_service_endpoint_id', $endpoint->id)
            ->where('service_name', $serviceName)
            ->first();
    }

    private function operation(?string $namespace, WorkflowService $service, string $operationName): ?WorkflowServiceOperation
    {
        $model = ConfiguredV2Models::resolve('service_operation_model', WorkflowServiceOperation::class);

        return $model::query()
            ->where('namespace', $namespace)
            ->where('workflow_service_id', $service->id)
            ->where('operation_name', $operationName)
            ->first();
    }

    private function existingCall(mixed $serviceCallId, ?string $namespace = null): ?WorkflowServiceCall
    {
        $model = ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class);

        if (! is_string($serviceCallId) || trim($serviceCallId) === '') {
            return null;
        }

        $query = $model::query()->whereKey(trim($serviceCallId));

        if ($namespace !== null) {
            $query->where('namespace', $namespace);
        }

        return $query->first();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function call(string $serviceCallId, array $options): ?WorkflowServiceCall
    {
        $model = ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class);
        $query = $model::query()->whereKey(trim($serviceCallId));
        $namespace = $this->targetNamespace($options);

        if ($namespace !== null) {
            $query->where('namespace', $namespace);
        }

        return $query->first();
    }

    private function newCall(): WorkflowServiceCall
    {
        $model = ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class);

        return new $model();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function idempotentCall(
        ?string $namespace,
        string $endpointName,
        string $serviceName,
        string $operationName,
        array $options,
    ): ?WorkflowServiceCall {
        $key = $this->stringOption($options, 'idempotency_key');

        if ($key === null) {
            return null;
        }

        $model = ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class);

        return $model::query()
            ->where('target_namespace', $namespace)
            ->where('endpoint_name', $endpointName)
            ->where('service_name', $serviceName)
            ->where('operation_name', $operationName)
            ->where('idempotency_key', $key)
            ->oldest('created_at')
            ->oldest('id')
            ->first();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function stampPending(
        WorkflowServiceCall $call,
        ?string $namespace,
        ?string $callerNamespace,
        string $endpointName,
        string $serviceName,
        string $operationName,
        ServiceCallPrincipal $principal,
        array $options,
    ): void {
        $call->workflow_service_endpoint_id = $call->workflow_service_endpoint_id ?: self::UNRESOLVED;
        $call->workflow_service_id = $call->workflow_service_id ?: self::UNRESOLVED;
        $call->workflow_service_operation_id = $call->workflow_service_operation_id ?: self::UNRESOLVED;
        $call->namespace = $namespace;
        $call->endpoint_name = $endpointName;
        $call->service_name = $serviceName;
        $call->operation_name = $operationName;
        $call->caller_namespace = $callerNamespace ?? $call->caller_namespace;
        $call->caller_workflow_instance_id = $this->stringOption($options, 'caller_workflow_instance_id')
            ?? $call->caller_workflow_instance_id;
        $call->caller_workflow_run_id = $this->stringOption($options, 'caller_workflow_run_id')
            ?? $call->caller_workflow_run_id;
        $call->target_namespace = $namespace;
        $call->status = ServiceCallStatus::Pending->value;
        $call->operation_mode = ServiceCallOperationMode::tryFromCatalog($this->stringOption($options, 'mode_override'))
            ?->value
            ?? ServiceCallOperationMode::Async->value;
        $call->resolved_binding_kind = self::UNRESOLVED;
        $call->payload_codec = $this->stringOption($options, 'payload_codec') ?? $call->payload_codec;
        $call->input_payload_reference = $this->stringOption($options, 'input_payload_reference')
            ?? $this->stringOption($options, 'request_payload_reference')
            ?? $call->input_payload_reference;
        $call->idempotency_key = $this->stringOption($options, 'idempotency_key') ?? $call->idempotency_key;
        $call->metadata = $this->arrayOption($options, 'metadata') ?: $call->metadata;
        $this->stampPrincipal($call, $principal);
    }

    private function applyResolvedCatalog(
        WorkflowServiceCall $call,
        WorkflowServiceEndpoint $endpoint,
        WorkflowService $service,
        WorkflowServiceOperation $operation,
        ?string $namespace,
        array $options,
        ?string $resolvedBindingKind,
    ): void {
        $call->workflow_service_endpoint_id = $endpoint->id;
        $call->workflow_service_id = $service->id;
        $call->workflow_service_operation_id = $operation->id;
        $call->namespace = $namespace;
        $call->endpoint_name = $endpoint->endpoint_name;
        $call->service_name = $service->service_name;
        $call->operation_name = $operation->operation_name;
        $call->target_namespace = $namespace;
        $call->operation_mode = $this->operationMode($operation, $options)->value;
        $call->resolved_binding_kind = $resolvedBindingKind ?? self::UNRESOLVED;
        $call->resolved_target_reference = $operation->handler_target_reference;
        $call->deadline_policy = $operation->deadline_policy;
        $call->idempotency_policy = $operation->idempotency_policy;
        $call->cancellation_policy = $operation->cancellation_policy;
        $call->retry_policy = $operation->retry_policy;
        $call->boundary_policy = $this->effectiveBoundaryPolicy($endpoint, $service, $operation) ?: null;
    }

    private function boundaryRequest(
        ServiceCallPrincipal $principal,
        ?string $callerNamespace,
        ?string $targetNamespace,
        WorkflowServiceEndpoint $endpoint,
        WorkflowService $service,
        WorkflowServiceOperation $operation,
        WorkflowServiceCall $call,
        string $resolvedBindingKind,
    ): ServiceBoundaryRequest {
        return new ServiceBoundaryRequest(
            principal: $principal,
            callerNamespace: $callerNamespace,
            targetNamespace: $targetNamespace ?? '',
            endpointName: $endpoint->endpoint_name,
            serviceName: $service->service_name,
            operationName: $operation->operation_name,
            operationMode: $this->operationMode($operation, ['mode_override' => $call->operation_mode]),
            resolvedBindingKind: $resolvedBindingKind,
            resolvedTargetReference: $operation->handler_target_reference,
            callerWorkflowInstanceId: $call->caller_workflow_instance_id,
            callerWorkflowRunId: $call->caller_workflow_run_id,
            linkedWorkflowInstanceId: $call->linked_workflow_instance_id,
            linkedWorkflowRunId: $call->linked_workflow_run_id,
            linkedWorkflowUpdateId: $call->linked_workflow_update_id,
            idempotencyKey: $call->idempotency_key,
            endpointBoundaryPolicy: $this->arrayValue($endpoint->boundary_policy),
            serviceBoundaryPolicy: $this->arrayValue($service->boundary_policy),
            operationBoundaryPolicy: $this->arrayValue($operation->boundary_policy),
            deadlinePolicy: $this->nullableArray($operation->deadline_policy),
            idempotencyPolicy: $this->nullableArray($operation->idempotency_policy),
            cancellationPolicy: $this->nullableArray($operation->cancellation_policy),
            retryPolicy: $this->nullableArray($operation->retry_policy),
        );
    }

    private function markResolutionFailure(WorkflowServiceCall $call, string $failedAt): void
    {
        $message = sprintf('Service contract component [%s] did not resolve.', $failedAt);

        $call->status = ServiceCallStatus::Failed->value;
        $call->outcome = ServiceCallOutcome::RejectedNotFound->value;
        $call->outcome_category = ServiceCallOutcome::RejectedNotFound->category();
        $call->outcome_reason = 'unknown_target';
        $call->outcome_message = $message;
        $call->resolved_target_reference = null;
        $call->outcome_metadata = [
            'failure_reason' => ServiceCallFailureReason::ResolutionFailure->value,
            'resolution_failed_at' => $failedAt,
        ];
        $call->metadata = $this->mergeMetadata($call->metadata, $call->outcome_metadata);
        $call->failure_message = $message;
        $call->failed_at ??= now();
        $call->save();
    }

    private function markUnknownBindingKind(WorkflowServiceCall $call, WorkflowServiceOperation $operation): void
    {
        $message = sprintf(
            'Service operation [%s] has unknown handler binding kind [%s].',
            $operation->operation_name,
            $operation->handler_binding_kind,
        );

        $call->status = ServiceCallStatus::Failed->value;
        $call->outcome = ServiceCallOutcome::RejectedNotFound->value;
        $call->outcome_category = ServiceCallOutcome::RejectedNotFound->category();
        $call->outcome_reason = 'unknown_binding_kind';
        $call->outcome_message = $message;
        $call->resolved_target_reference = null;
        $call->outcome_metadata = [
            'failure_reason' => ServiceCallFailureReason::ResolutionFailure->value,
            'resolution_failed_at' => 'handler_binding_kind',
            'handler_binding_kind' => (string) $operation->handler_binding_kind,
        ];
        $call->metadata = $this->mergeMetadata($call->metadata, $call->outcome_metadata);
        $call->failure_message = $message;
        $call->failed_at ??= now();
        $call->save();
    }

    private function markBoundaryRejection(
        WorkflowServiceCall $call,
        ServiceBoundaryRequest $request,
        ServiceBoundaryDecision $decision,
    ): void {
        $call->status = ServiceCallStatus::Failed->value;
        $call->outcome = $decision->outcome->value;
        $call->outcome_category = $decision->outcome->category();
        $call->outcome_reason = $decision->reason;
        $call->outcome_message = $decision->message;
        $call->outcome_metadata = $decision->metadata === [] ? null : $decision->metadata;
        $call->policy_name = $decision->policyName;
        $call->retry_after_seconds = $decision->retryAfterSeconds;
        $call->boundary_policy = $request->effectiveBoundaryPolicy() ?: null;
        $call->metadata = $this->mergeMetadata($call->metadata, $decision->metadata);
        $call->failure_message = $decision->message;
        $call->failed_at ??= now();
        $call->save();
    }

    private function markAccepted(
        WorkflowServiceCall $call,
        ServiceBoundaryRequest $request,
        ServiceBoundaryDecision $decision,
    ): void {
        $call->status = ServiceCallStatus::Accepted->value;
        $call->outcome = ServiceCallOutcome::Accepted->value;
        $call->outcome_category = ServiceCallOutcome::Accepted->category();
        $call->outcome_reason = $decision->reason;
        $call->outcome_message = $decision->message;
        $call->outcome_metadata = $decision->metadata === [] ? null : $decision->metadata;
        $call->policy_name = $decision->policyName;
        $call->retry_after_seconds = $decision->retryAfterSeconds;
        $call->boundary_policy = $request->effectiveBoundaryPolicy() ?: null;
        $call->metadata = $this->mergeMetadata($call->metadata, $decision->metadata);
        $call->accepted_at ??= now();
        $call->save();
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private function dispatchHandler(
        WorkflowServiceCall $call,
        WorkflowServiceOperation $operation,
        ServiceBoundaryRequest $request,
        array $options,
    ): ?array {
        $kind = $this->bindingKind($operation);

        return match ($kind) {
            ServiceCallBindingKind::WorkflowRun->value, 'start_workflow', 'workflow_class' => $this->dispatchWorkflowRun(
                $call,
                $operation,
                $request,
                $options,
            ),
            ServiceCallBindingKind::WorkflowUpdate->value, 'update_workflow' => $this->dispatchWorkflowUpdate(
                $call,
                $operation,
                $request,
                $options,
            ),
            ServiceCallBindingKind::WorkflowSignal->value, 'signal_workflow' => $this->dispatchWorkflowSignal(
                $call,
                $operation,
                $request,
                $options,
            ),
            ServiceCallBindingKind::WorkflowQuery->value, 'query_workflow' => $this->dispatchWorkflowQuery(
                $call,
                $operation,
                $request,
                $options,
            ),
            ServiceCallBindingKind::ActivityExecution->value => $this->dispatchActivityExecution(
                $call,
                $operation,
                $request,
                $options,
            ),
            ServiceCallBindingKind::InvocableCarrierRequest->value, 'invocable_http' => $this->dispatchInvocableCarrierRequest(
                $call,
                $operation,
                $request,
                $options,
            ),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function dispatchWorkflowRun(
        WorkflowServiceCall $call,
        WorkflowServiceOperation $operation,
        ServiceBoundaryRequest $request,
        array $options,
    ): array {
        $binding = $this->handlerBinding($operation);
        $workflowType = $this->bindingString($binding, ['workflow_type', 'workflow_class', 'type'])
            ?? $this->filledString($operation->handler_target_reference);

        if ($workflowType === null) {
            return $this->markHandlerFailure($call, $request, 'handler_target_missing', 'Workflow start binding has no workflow type.');
        }

        $instanceId = $this->bindingString($binding, ['workflow_instance_id', 'instance_id'])
            ?? $this->stringOption($options, 'workflow_instance_id');

        try {
            $result = $this->workflows()->start($workflowType, $instanceId, $this->workflowStartOptions(
                $call,
                $binding,
                $options,
            ));
        } catch (Throwable $exception) {
            return $this->markHandlerFailure($call, $request, 'workflow_start_exception', $exception->getMessage());
        }

        if (($result['started'] ?? false) === true) {
            $this->markStarted(
                $call,
                ServiceCallBindingKind::WorkflowRun->value,
                $this->stringFrom($result['workflow_run_id'] ?? null) ?? $workflowType,
                $this->stringFrom($result['workflow_instance_id'] ?? null),
                $this->stringFrom($result['workflow_run_id'] ?? null),
                null,
                ['workflow_type' => $workflowType, 'control_plane' => $this->publicResult($result)],
            );

            return ['accepted' => true, 'kind' => ServiceCallBindingKind::WorkflowRun->value] + $this->publicResult($result);
        }

        return $this->markHandlerFailure(
            $call,
            $request,
            $this->stringFrom($result['reason'] ?? null) ?? 'workflow_start_rejected',
            $this->stringFrom($result['message'] ?? null),
            ['control_plane' => $this->publicResult($result)],
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function dispatchWorkflowUpdate(
        WorkflowServiceCall $call,
        WorkflowServiceOperation $operation,
        ServiceBoundaryRequest $request,
        array $options,
    ): array {
        $binding = $this->handlerBinding($operation);
        $instanceId = $this->bindingString($binding, ['workflow_instance_id', 'instance_id', 'target_instance_id'])
            ?? $this->stringOption($options, 'target_workflow_instance_id')
            ?? $this->stringOption($options, 'workflow_instance_id');
        $updateName = $this->bindingString($binding, ['update_name', 'name'])
            ?? $this->filledString($operation->handler_target_reference)
            ?? $operation->operation_name;

        if ($instanceId === null) {
            return $this->markHandlerFailure($call, $request, 'handler_target_missing', 'Workflow update binding has no workflow instance id.');
        }

        $commandOptions = $this->workflowCommandOptions($call, $binding, $options);
        $commandOptions['wait_for'] ??= $call->operation_mode === ServiceCallOperationMode::Sync->value
            ? 'completed'
            : 'accepted';

        try {
            $result = $this->workflows()->update($instanceId, $updateName, $commandOptions);
        } catch (Throwable $exception) {
            return $this->markHandlerFailure($call, $request, 'workflow_update_exception', $exception->getMessage());
        }

        if (($result['accepted'] ?? false) === true) {
            $updateId = $this->stringFrom($result['update_id'] ?? null);
            $runId = $this->stringFrom($result['run_id'] ?? null);
            $metadata = ['update_name' => $updateName, 'control_plane' => $this->publicResult($result)];

            if (($result['update_status'] ?? null) === 'completed') {
                $this->markCompleted(
                    $call,
                    ServiceCallBindingKind::WorkflowUpdate->value,
                    $updateId ?? $updateName,
                    $this->stringFrom($result['workflow_instance_id'] ?? null) ?? $instanceId,
                    $runId,
                    $updateId,
                    $metadata,
                );
                $this->releaseBoundaryAdmission($request);
            } else {
                $this->markStarted(
                    $call,
                    ServiceCallBindingKind::WorkflowUpdate->value,
                    $updateId ?? $updateName,
                    $this->stringFrom($result['workflow_instance_id'] ?? null) ?? $instanceId,
                    $runId,
                    $updateId,
                    $metadata,
                );
            }

            return ['accepted' => true, 'kind' => ServiceCallBindingKind::WorkflowUpdate->value] + $this->publicResult($result);
        }

        return $this->markHandlerFailure(
            $call,
            $request,
            $this->stringFrom($result['reason'] ?? null) ?? 'workflow_update_rejected',
            $this->stringFrom($result['message'] ?? null),
            ['control_plane' => $this->publicResult($result)],
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function dispatchWorkflowSignal(
        WorkflowServiceCall $call,
        WorkflowServiceOperation $operation,
        ServiceBoundaryRequest $request,
        array $options,
    ): array {
        $binding = $this->handlerBinding($operation);
        $instanceId = $this->bindingString($binding, ['workflow_instance_id', 'instance_id', 'target_instance_id'])
            ?? $this->stringOption($options, 'target_workflow_instance_id')
            ?? $this->stringOption($options, 'workflow_instance_id');
        $signalName = $this->bindingString($binding, ['signal_name', 'name'])
            ?? $this->filledString($operation->handler_target_reference)
            ?? $operation->operation_name;

        if ($instanceId === null) {
            return $this->markHandlerFailure($call, $request, 'handler_target_missing', 'Workflow signal binding has no workflow instance id.');
        }

        try {
            $result = $this->workflows()->signal($instanceId, $signalName, $this->workflowCommandOptions(
                $call,
                $binding,
                $options,
            ));
        } catch (Throwable $exception) {
            return $this->markHandlerFailure($call, $request, 'workflow_signal_exception', $exception->getMessage());
        }

        if (($result['accepted'] ?? false) === true) {
            $commandId = $this->stringFrom($result['workflow_command_id'] ?? null);

            $this->markStarted(
                $call,
                ServiceCallBindingKind::WorkflowSignal->value,
                $commandId ?? $signalName,
                $this->stringFrom($result['workflow_instance_id'] ?? null) ?? $instanceId,
                $this->stringFrom($result['run_id'] ?? null),
                null,
                ['signal_name' => $signalName, 'control_plane' => $this->publicResult($result)],
            );

            return ['accepted' => true, 'kind' => ServiceCallBindingKind::WorkflowSignal->value] + $this->publicResult($result);
        }

        return $this->markHandlerFailure(
            $call,
            $request,
            $this->stringFrom($result['reason'] ?? null) ?? 'workflow_signal_rejected',
            $this->stringFrom($result['message'] ?? null),
            ['control_plane' => $this->publicResult($result)],
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function dispatchWorkflowQuery(
        WorkflowServiceCall $call,
        WorkflowServiceOperation $operation,
        ServiceBoundaryRequest $request,
        array $options,
    ): array {
        $binding = $this->handlerBinding($operation);
        $instanceId = $this->bindingString($binding, ['workflow_instance_id', 'instance_id', 'target_instance_id'])
            ?? $this->stringOption($options, 'target_workflow_instance_id')
            ?? $this->stringOption($options, 'workflow_instance_id');
        $queryName = $this->bindingString($binding, ['query_name', 'name'])
            ?? $this->filledString($operation->handler_target_reference)
            ?? $operation->operation_name;

        if ($instanceId === null) {
            return $this->markHandlerFailure($call, $request, 'handler_target_missing', 'Workflow query binding has no workflow instance id.');
        }

        try {
            $result = $this->workflows()->query($instanceId, $queryName, $this->workflowCommandOptions(
                $call,
                $binding,
                $options,
            ));
        } catch (Throwable $exception) {
            return $this->markHandlerFailure($call, $request, 'workflow_query_exception', $exception->getMessage());
        }

        if (($result['success'] ?? false) === true) {
            $runId = $this->stringFrom($result['run_id'] ?? null);

            $this->markCompleted(
                $call,
                ServiceCallBindingKind::WorkflowQuery->value,
                $runId ?? $instanceId,
                $this->stringFrom($result['workflow_instance_id'] ?? null) ?? $instanceId,
                $runId,
                null,
                ['query_name' => $queryName, 'control_plane' => $this->publicResult($result)],
            );
            $this->releaseBoundaryAdmission($request);

            return ['accepted' => true, 'kind' => ServiceCallBindingKind::WorkflowQuery->value] + $this->publicResult($result);
        }

        return $this->markHandlerFailure(
            $call,
            $request,
            $this->stringFrom($result['reason'] ?? null) ?? 'workflow_query_rejected',
            $this->stringFrom($result['message'] ?? null),
            ['control_plane' => $this->publicResult($result)],
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function dispatchActivityExecution(
        WorkflowServiceCall $call,
        WorkflowServiceOperation $operation,
        ServiceBoundaryRequest $request,
        array $options,
    ): array {
        $binding = $this->handlerBinding($operation);
        $activityClass = $this->bindingString($binding, ['activity_class', 'class'])
            ?? $this->filledString($operation->handler_target_reference);
        $activityType = $this->bindingString($binding, ['activity_type', 'type'])
            ?? $activityClass;

        if ($activityType === null) {
            return $this->markHandlerFailure(
                $call,
                $request,
                'handler_target_missing',
                'Activity binding has no activity class or type.',
            );
        }

        $activityExecutionId = $this->bindingString($binding, ['activity_execution_id'])
            ?? $this->stringOption($options, 'activity_execution_id')
            ?? (string) Str::ulid();

        $linkedInstanceId = $this->bindingString($binding, ['workflow_instance_id', 'owning_workflow_instance_id'])
            ?? $this->stringOption($options, 'owning_workflow_instance_id');
        $linkedRunId = $this->bindingString($binding, ['workflow_run_id', 'owning_workflow_run_id'])
            ?? $this->stringOption($options, 'owning_workflow_run_id');

        $metadata = [
            'activity_execution_id' => $activityExecutionId,
            'activity_class' => $activityClass,
            'activity_type' => $activityType,
        ];

        foreach (['connection', 'queue'] as $key) {
            $value = $this->bindingString($binding, [$key]) ?? $this->stringOption($options, $key);
            if ($value !== null) {
                $metadata[$key] = $value;
            }
        }

        $this->markStarted(
            $call,
            ServiceCallBindingKind::ActivityExecution->value,
            $activityExecutionId,
            $linkedInstanceId,
            $linkedRunId,
            null,
            $metadata,
        );

        return [
            'accepted' => true,
            'kind' => ServiceCallBindingKind::ActivityExecution->value,
            'activity_execution_id' => $activityExecutionId,
            'activity_class' => $activityClass,
            'activity_type' => $activityType,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function dispatchInvocableCarrierRequest(
        WorkflowServiceCall $call,
        WorkflowServiceOperation $operation,
        ServiceBoundaryRequest $request,
        array $options,
    ): array {
        $binding = $this->handlerBinding($operation);
        $carrierEndpoint = $this->bindingString($binding, ['carrier_endpoint', 'endpoint', 'url'])
            ?? $this->filledString($operation->handler_target_reference);
        $carrierHandler = $this->bindingString($binding, ['carrier_handler', 'handler', 'handler_name']);
        $carrierName = $this->bindingString($binding, ['carrier', 'carrier_name']);

        if ($carrierEndpoint === null) {
            return $this->markHandlerFailure(
                $call,
                $request,
                'handler_target_missing',
                'Invocable carrier binding has no endpoint or carrier reference.',
            );
        }

        $carrierRequestId = $this->bindingString($binding, ['carrier_request_id'])
            ?? $this->stringOption($options, 'carrier_request_id')
            ?? (string) Str::ulid();

        $linkedInstanceId = $this->bindingString($binding, ['workflow_instance_id', 'bound_workflow_instance_id'])
            ?? $this->stringOption($options, 'bound_workflow_instance_id');

        $metadata = [
            'carrier_request_id' => $carrierRequestId,
            'carrier_endpoint' => $carrierEndpoint,
        ];

        if ($carrierHandler !== null) {
            $metadata['carrier_handler'] = $carrierHandler;
        }

        if ($carrierName !== null) {
            $metadata['carrier'] = $carrierName;
        }

        $this->markStarted(
            $call,
            ServiceCallBindingKind::InvocableCarrierRequest->value,
            $carrierRequestId,
            $linkedInstanceId,
            null,
            null,
            $metadata,
        );

        return [
            'accepted' => true,
            'kind' => ServiceCallBindingKind::InvocableCarrierRequest->value,
            'carrier_request_id' => $carrierRequestId,
            'carrier_endpoint' => $carrierEndpoint,
            'carrier_handler' => $carrierHandler,
            'carrier' => $carrierName,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function markStarted(
        WorkflowServiceCall $call,
        string $bindingKind,
        string $targetReference,
        ?string $instanceId,
        ?string $runId,
        ?string $updateId,
        array $metadata,
    ): void {
        $call->status = ServiceCallStatus::Started->value;
        $call->outcome = ServiceCallOutcome::Accepted->value;
        $call->outcome_category = ServiceCallOutcome::Accepted->category();
        $call->resolved_binding_kind = $bindingKind;
        $call->resolved_target_reference = $targetReference;
        $call->linked_workflow_instance_id = $instanceId;
        $call->linked_workflow_run_id = $runId;
        $call->linked_workflow_update_id = $updateId;
        $call->metadata = $this->mergeMetadata($call->metadata, $metadata);
        $call->started_at ??= now();
        $call->save();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function markCompleted(
        WorkflowServiceCall $call,
        string $bindingKind,
        string $targetReference,
        ?string $instanceId,
        ?string $runId,
        ?string $updateId,
        array $metadata,
    ): void {
        $call->status = ServiceCallStatus::Completed->value;
        $call->outcome = ServiceCallOutcome::Completed->value;
        $call->outcome_category = ServiceCallOutcome::Completed->category();
        $call->outcome_reason = 'completed';
        $call->resolved_binding_kind = $bindingKind;
        $call->resolved_target_reference = $targetReference;
        $call->linked_workflow_instance_id = $instanceId;
        $call->linked_workflow_run_id = $runId;
        $call->linked_workflow_update_id = $updateId;
        $call->metadata = $this->mergeMetadata($call->metadata, $metadata);
        $call->started_at ??= now();
        $call->completed_at ??= now();
        $call->save();
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function markHandlerFailure(
        WorkflowServiceCall $call,
        ServiceBoundaryRequest $request,
        string $reason,
        ?string $message,
        array $metadata = [],
    ): array {
        $call->status = ServiceCallStatus::Failed->value;
        $call->outcome = ServiceCallOutcome::HandlerFailed->value;
        $call->outcome_category = ServiceCallOutcome::HandlerFailed->category();
        $call->outcome_reason = $reason;
        $call->outcome_message = $message;
        $call->outcome_metadata = ['failure_reason' => ServiceCallFailureReason::HandlerFailure->value] + $metadata;
        $call->metadata = $this->mergeMetadata($call->metadata, $call->outcome_metadata);
        $call->failure_message = $message ?? $reason;
        $call->failed_at ??= now();
        $call->save();

        $this->releaseBoundaryAdmission($request);

        return [
            'accepted' => false,
            'kind' => $call->resolved_binding_kind,
            'reason' => $reason,
            'message' => $message,
        ];
    }

    /**
     * @param array<string, mixed> $binding
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function workflowStartOptions(WorkflowServiceCall $call, array $binding, array $options): array
    {
        $startOptions = [
            'namespace' => $call->target_namespace,
        ];

        foreach (['connection', 'queue'] as $key) {
            if (array_key_exists($key, $binding)) {
                $startOptions[$key] = $binding[$key];
            }
        }

        foreach (['duplicate_start_policy', 'business_key', 'execution_timeout_seconds', 'run_timeout_seconds'] as $key) {
            $value = $binding[$key] ?? $options[$key] ?? null;

            if ($value !== null) {
                $startOptions[$key] = $value;
            }
        }

        foreach (['labels', 'memo', 'search_attributes'] as $key) {
            $value = $binding[$key] ?? $options[$key] ?? null;

            if (is_array($value)) {
                $startOptions[$key] = $value;
            }
        }

        $arguments = $this->stringOption($options, 'arguments')
            ?? $this->stringOption($options, 'payload_blob')
            ?? $this->bindingString($binding, ['arguments', 'payload_blob']);

        if ($arguments !== null) {
            $startOptions['arguments'] = $arguments;
        }

        if ($call->payload_codec !== null) {
            $startOptions['payload_codec'] = $call->payload_codec;
        }

        return $startOptions;
    }

    /**
     * @param array<string, mixed> $binding
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function workflowCommandOptions(WorkflowServiceCall $call, array $binding, array $options): array
    {
        $commandOptions = [
            'namespace' => $call->target_namespace,
        ];

        $arguments = $this->arrayOption($options, 'arguments') ?: $this->arrayAt($binding, 'arguments');
        if ($arguments !== []) {
            $commandOptions['arguments'] = $arguments;
        }

        foreach (['payload_codec', 'payload_blob', 'wait_for', 'wait_timeout_seconds'] as $key) {
            $value = $options[$key] ?? $binding[$key] ?? null;

            if ($value !== null) {
                $commandOptions[$key] = $value;
            }
        }

        return $commandOptions;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function publicResult(array $result): array
    {
        unset(
            $result['result'],
            $result['result_envelope'],
            $result['payload'],
            $result['payload_blob'],
            $result['arguments']
        );

        return $result;
    }

    private function operationMode(WorkflowServiceOperation $operation, array $options): ServiceCallOperationMode
    {
        return ServiceCallOperationMode::tryFromCatalog($options['mode_override'] ?? null)
            ?? ServiceCallOperationMode::tryFromCatalog((string) $operation->operation_mode)
            ?? ServiceCallOperationMode::Async;
    }

    private function principal(array $options): ServiceCallPrincipal
    {
        $principal = $options['principal'] ?? null;

        if ($principal instanceof ServiceCallPrincipal) {
            return $principal;
        }

        if (is_array($principal)) {
            return ServiceCallPrincipal::fromAuditArray($principal);
        }

        return new ServiceCallPrincipal(
            subject: $this->stringOption($options, 'principal_subject') ?? 'system',
            method: $this->stringOption($options, 'principal_method') ?? 'system',
            roles: $this->stringList($options['principal_roles'] ?? []),
            tenant: $this->stringOption($options, 'principal_tenant'),
            claims: $this->arrayOption($options, 'principal_claims'),
        );
    }

    private function stampPrincipal(WorkflowServiceCall $call, ServiceCallPrincipal $principal): void
    {
        if (
            $call->exists
            && $principal->subject === 'system'
            && is_string($call->caller_principal_subject)
            && $call->caller_principal_subject !== ''
        ) {
            return;
        }

        $call->caller_principal_subject = $principal->subject;
        $call->caller_principal_method = $principal->method;
        $call->caller_principal_roles = $principal->roles === [] ? null : array_values($principal->roles);
        $call->caller_principal_tenant = $principal->tenant;
        $call->caller_principal_claims = $principal->claims === [] ? null : $principal->claims;
    }

    private function workflows(): WorkflowControlPlane
    {
        return $this->workflowControlPlane ??= app(WorkflowControlPlane::class);
    }

    private function boundaryPolicy(): ServiceBoundaryPolicy
    {
        return $this->boundaryPolicy ??= app(ServiceBoundaryPolicy::class);
    }

    private function releaseBoundaryAdmission(ServiceBoundaryRequest $request): void
    {
        $policy = $this->boundaryPolicy();

        if (method_exists($policy, 'release')) {
            $policy->release($request);
        }
    }

    private function bindingKind(WorkflowServiceOperation $operation): string
    {
        return strtolower(trim((string) $operation->handler_binding_kind));
    }

    private function resolvedBindingKind(WorkflowServiceOperation $operation): ?string
    {
        return ServiceCallBindingKind::tryFromHandlerBindingKind($this->bindingKind($operation))?->value;
    }

    /**
     * @return array<string, mixed>
     */
    private function handlerBinding(WorkflowServiceOperation $operation): array
    {
        return is_array($operation->handler_binding) ? $operation->handler_binding : [];
    }

    /**
     * @param array<string, mixed> $binding
     * @param list<string> $keys
     */
    private function bindingString(array $binding, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (($value = $this->stringFrom($binding[$key] ?? null)) !== null) {
                return $value;
            }
        }

        return null;
    }

    private function stringOption(array $options, string $key): ?string
    {
        return $this->stringFrom($options[$key] ?? null);
    }

    private function stringFrom(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int|string, mixed>
     */
    private function arrayAt(array $source, string $key): array
    {
        return isset($source[$key]) && is_array($source[$key]) ? $source[$key] : [];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int|string, mixed>
     */
    private function arrayOption(array $options, string $key): array
    {
        return isset($options[$key]) && is_array($options[$key]) ? $options[$key] : [];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nullableArray(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $entry): bool => is_string($entry) && trim($entry) !== '',
        ));
    }

    private function filledString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function contractName(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * @return array<string, mixed>
     */
    private function effectiveBoundaryPolicy(
        WorkflowServiceEndpoint $endpoint,
        WorkflowService $service,
        WorkflowServiceOperation $operation,
    ): array {
        return ServiceBoundaryRequest::mergePolicy(
            $this->arrayValue($endpoint->boundary_policy),
            $this->arrayValue($service->boundary_policy),
            $this->arrayValue($operation->boundary_policy),
        );
    }

    /**
     * @param array<string, mixed>|null $metadata
     * @param array<string, mixed>|null $extra
     * @return array<string, mixed>|null
     */
    private function mergeMetadata(mixed $metadata, ?array $extra): ?array
    {
        $base = $this->arrayValue($metadata);
        $extra ??= [];

        if ($base === [] && $extra === []) {
            return null;
        }

        return ServiceBoundaryRequest::mergePolicy($base, $extra);
    }

    private function cancellationAllowed(mixed $policy): bool
    {
        $policy = $this->arrayValue($policy);

        foreach (['allow_cancel', 'cancellable', 'allow_external_cancel'] as $key) {
            if (array_key_exists($key, $policy) && $policy[$key] === false) {
                return false;
            }
        }

        return ($policy['mode'] ?? null) !== 'none';
    }

    private function acceptedShape(WorkflowServiceCall $call): bool
    {
        return ! $this->isFailed($call) && ! $this->isCancelled($call);
    }

    private function isFailed(WorkflowServiceCall $call): bool
    {
        return (string) $call->status === ServiceCallStatus::Failed->value;
    }

    private function isCancelled(WorkflowServiceCall $call): bool
    {
        return (string) $call->status === ServiceCallStatus::Cancelled->value;
    }

    private function outcomeValue(WorkflowServiceCall $call): ?string
    {
        if ($call->outcome instanceof ServiceCallOutcome) {
            return $call->outcome->value;
        }

        return is_string($call->outcome) ? $call->outcome : null;
    }

    private function isTerminal(WorkflowServiceCall $call): bool
    {
        return ServiceCallStatus::tryFrom((string) $call->status)?->isTerminal() ?? false;
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('c') : null;
    }
}
