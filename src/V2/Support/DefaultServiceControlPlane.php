<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

/**
 * Durable-row service control-plane adapter.
 *
 * This adapter owns the service-call row lifecycle. Hosts with real handler
 * transports can decorate or replace it; the default keeps the protocol
 * surface resolvable and records the accepted/cancelled state without
 * inspecting payload bytes.
 */
final class DefaultServiceControlPlane implements ServiceControlPlane
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function execute(string $endpointName, string $serviceName, string $operationName, array $options = []): array
    {
        $namespace = $this->namespace($options);
        $endpoint = $this->endpoint($namespace, $endpointName);

        if (! $endpoint) {
            return $this->missing($namespace, $endpointName, $serviceName, $operationName, 'endpoint_not_found');
        }

        $service = $this->service($namespace, $endpoint, $serviceName);

        if (! $service) {
            return $this->missing($namespace, $endpoint->endpoint_name, $serviceName, $operationName, 'service_not_found');
        }

        $operation = $this->operation($namespace, $service, $operationName);

        if (! $operation) {
            return $this->missing($namespace, $endpoint->endpoint_name, $service->service_name, $operationName, 'operation_not_found');
        }

        $call = $this->existingCall($options['service_call_id'] ?? null) ?? new WorkflowServiceCall();

        $call->workflow_service_endpoint_id = $endpoint->id;
        $call->workflow_service_id = $service->id;
        $call->workflow_service_operation_id = $operation->id;
        $call->namespace = $namespace;
        $call->endpoint_name = $endpoint->endpoint_name;
        $call->service_name = $service->service_name;
        $call->operation_name = $operation->operation_name;
        $call->caller_namespace = $options['caller_namespace'] ?? $call->caller_namespace;
        $call->caller_workflow_instance_id = $options['caller_workflow_instance_id'] ?? $call->caller_workflow_instance_id;
        $call->caller_workflow_run_id = $options['caller_workflow_run_id'] ?? $call->caller_workflow_run_id;
        $call->target_namespace = $namespace;
        $call->status = ServiceCallStatus::Accepted->value;
        $call->outcome = ServiceCallOutcome::Accepted->value;
        $call->operation_mode = $this->operationMode($operation, $options)->value;
        $call->resolved_binding_kind = (string) $operation->handler_binding_kind;
        $call->resolved_target_reference = $operation->handler_target_reference;
        $call->payload_codec = $options['payload_codec'] ?? $call->payload_codec;
        $call->idempotency_key = $options['idempotency_key'] ?? $call->idempotency_key;
        $call->deadline_policy = $operation->deadline_policy;
        $call->idempotency_policy = $operation->idempotency_policy;
        $call->cancellation_policy = $operation->cancellation_policy;
        $call->retry_policy = $operation->retry_policy;
        $call->boundary_policy = $operation->boundary_policy;
        $call->accepted_at ??= now();
        $call->save();

        return $this->serialize($call) + [
            'accepted' => true,
            'reason' => null,
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

        $call->status = ServiceCallStatus::Cancelled->value;
        $call->outcome = ServiceCallOutcome::Cancelled->value;
        $call->cancelled_at ??= now();
        $call->failure_message = $options['reason'] ?? $call->failure_message;
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
        $outcome = $call->outcome instanceof ServiceCallOutcome
            ? $call->outcome->value
            : $call->outcome;

        return [
            'service_call_id' => $call->id,
            'namespace' => $call->namespace,
            'endpoint_name' => $call->endpoint_name,
            'service_name' => $call->service_name,
            'operation_name' => $call->operation_name,
            'operation_mode' => $call->operation_mode,
            'status' => $call->status,
            'outcome' => $outcome,
            'resolved_binding_kind' => $call->resolved_binding_kind,
            'resolved_target_reference' => $call->resolved_target_reference,
            'linked_workflow_instance_id' => $call->linked_workflow_instance_id,
            'linked_workflow_run_id' => $call->linked_workflow_run_id,
            'linked_workflow_update_id' => $call->linked_workflow_update_id,
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
    private function missing(
        ?string $namespace,
        string $endpointName,
        string $serviceName,
        string $operationName,
        string $reason,
    ): array {
        return [
            'accepted' => false,
            'service_call_id' => null,
            'namespace' => $namespace,
            'endpoint_name' => $endpointName,
            'service_name' => $serviceName,
            'operation_name' => $operationName,
            'operation_mode' => null,
            'status' => null,
            'resolved_binding_kind' => null,
            'resolved_target_reference' => null,
            'linked_workflow_instance_id' => null,
            'linked_workflow_run_id' => null,
            'linked_workflow_update_id' => null,
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCallShape(): array
    {
        return [
            'namespace' => null,
            'endpoint_name' => null,
            'service_name' => null,
            'operation_name' => null,
            'operation_mode' => null,
            'status' => null,
            'resolved_binding_kind' => null,
            'resolved_target_reference' => null,
            'linked_workflow_instance_id' => null,
            'linked_workflow_run_id' => null,
            'linked_workflow_update_id' => null,
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
    private function namespace(array $options): ?string
    {
        $namespace = $options['namespace'] ?? config('workflows.v2.namespace');

        return is_string($namespace) && trim($namespace) !== ''
            ? trim($namespace)
            : null;
    }

    private function endpoint(?string $namespace, string $endpointName): ?WorkflowServiceEndpoint
    {
        $model = ConfiguredV2Models::resolve('service_endpoint_model', WorkflowServiceEndpoint::class);

        return $model::query()
            ->where('namespace', $namespace)
            ->where('endpoint_name', strtolower($endpointName))
            ->first();
    }

    private function service(?string $namespace, WorkflowServiceEndpoint $endpoint, string $serviceName): ?WorkflowService
    {
        $model = ConfiguredV2Models::resolve('service_model', WorkflowService::class);

        return $model::query()
            ->where('namespace', $namespace)
            ->where('workflow_service_endpoint_id', $endpoint->id)
            ->where('service_name', strtolower($serviceName))
            ->first();
    }

    private function operation(?string $namespace, WorkflowService $service, string $operationName): ?WorkflowServiceOperation
    {
        $model = ConfiguredV2Models::resolve('service_operation_model', WorkflowServiceOperation::class);

        return $model::query()
            ->where('namespace', $namespace)
            ->where('workflow_service_id', $service->id)
            ->where('operation_name', strtolower($operationName))
            ->first();
    }

    private function existingCall(mixed $serviceCallId): ?WorkflowServiceCall
    {
        $model = ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class);

        return is_string($serviceCallId) && trim($serviceCallId) !== ''
            ? $model::query()->whereKey(trim($serviceCallId))->first()
            : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function call(string $serviceCallId, array $options): ?WorkflowServiceCall
    {
        $model = ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class);
        $query = $model::query()->whereKey(trim($serviceCallId));
        $namespace = $this->namespace($options);

        if ($namespace !== null) {
            $query->where('namespace', $namespace);
        }

        return $query->first();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function operationMode(WorkflowServiceOperation $operation, array $options): ServiceCallOperationMode
    {
        return ServiceCallOperationMode::tryFromCatalog($options['mode_override'] ?? null)
            ?? ServiceCallOperationMode::tryFromCatalog($operation->operation_mode)
            ?? ServiceCallOperationMode::Async;
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('c') : null;
    }
}
