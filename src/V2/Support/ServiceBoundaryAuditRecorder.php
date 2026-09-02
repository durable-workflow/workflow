<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Model;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

/**
 * Persists a workflow_service_calls row for every boundary decision.
 */
final class ServiceBoundaryAuditRecorder
{
    private const UNRESOLVED = 'unresolved';

    public function record(
        ServiceBoundaryRequest $request,
        ServiceBoundaryDecision $decision,
    ): WorkflowServiceCall {
        /** @var class-string<WorkflowServiceCall> $modelClass */
        $modelClass = ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class);

        /** @var WorkflowServiceCall $call */
        $call = new $modelClass();

        $endpointId = $this->endpointId($request);
        $serviceId = $endpointId !== null ? $this->serviceId($request, $endpointId) : null;
        $operationId = $serviceId !== null ? $this->operationId($request, $serviceId) : null;
        $boundaryPolicy = $request->effectiveBoundaryPolicy();

        $call->workflow_service_endpoint_id = $endpointId ?? self::UNRESOLVED;
        $call->workflow_service_id = $serviceId ?? self::UNRESOLVED;
        $call->workflow_service_operation_id = $operationId ?? self::UNRESOLVED;
        $call->namespace = $request->targetNamespace;
        $call->endpoint_name = $request->endpointName;
        $call->service_name = $request->serviceName;
        $call->operation_name = $request->operationName;
        $call->caller_namespace = $request->callerNamespace;
        $call->caller_workflow_instance_id = $request->callerWorkflowInstanceId;
        $call->caller_workflow_run_id = $request->callerWorkflowRunId;
        $call->target_namespace = $request->targetNamespace;
        $call->linked_workflow_instance_id = $request->linkedWorkflowInstanceId;
        $call->linked_workflow_run_id = $request->linkedWorkflowRunId;
        $call->linked_workflow_update_id = $request->linkedWorkflowUpdateId;
        $call->status = $decision->isAllowed()
            ? ServiceCallStatus::Accepted->value
            : ServiceCallStatus::Failed->value;
        $call->operation_mode = $request->operationMode->value;
        $call->resolved_binding_kind = $request->resolvedBindingKind ?? self::UNRESOLVED;
        $call->resolved_target_reference = $request->resolvedTargetReference;
        $call->idempotency_key = $request->idempotencyKey;
        $call->deadline_policy = $request->deadlinePolicy;
        $call->idempotency_policy = $request->idempotencyPolicy;
        $call->cancellation_policy = $request->cancellationPolicy;
        $call->retry_policy = $request->retryPolicy;
        $call->boundary_policy = $boundaryPolicy === [] ? null : $boundaryPolicy;
        $call->metadata = $decision->metadata === [] ? null : $decision->metadata;
        $call->outcome = $decision->outcome->value;
        $call->outcome_category = $decision->outcome->category();
        $call->outcome_reason = $decision->reason;
        $call->outcome_message = $decision->message;
        $call->outcome_metadata = $decision->metadata === [] ? null : $decision->metadata;
        $call->policy_name = $decision->policyName;
        $call->retry_after_seconds = $decision->retryAfterSeconds;
        $call->caller_principal_subject = $request->principal->subject;
        $call->caller_principal_method = $request->principal->method;
        $call->caller_principal_roles = $request->principal->roles === []
            ? null
            : array_values($request->principal->roles);
        $call->caller_principal_tenant = $request->principal->tenant;
        $call->caller_principal_claims = $request->principal->claims === []
            ? null
            : $request->principal->claims;

        $now = now();
        if ($decision->isAllowed()) {
            $call->accepted_at = $now;
        } else {
            $call->failed_at = $now;
            $call->failure_message = $decision->message;
        }

        $call->save();

        return $call;
    }

    private function endpointId(ServiceBoundaryRequest $request): ?string
    {
        return $this->lookupCatalogId(
            ConfiguredV2Models::resolve('service_endpoint_model', WorkflowServiceEndpoint::class),
            [
                'namespace' => $request->targetNamespace,
                'endpoint_name' => $request->endpointName,
            ],
        );
    }

    private function serviceId(ServiceBoundaryRequest $request, string $endpointId): ?string
    {
        return $this->lookupCatalogId(
            ConfiguredV2Models::resolve('service_model', WorkflowService::class),
            [
                'namespace' => $request->targetNamespace,
                'workflow_service_endpoint_id' => $endpointId,
                'service_name' => $request->serviceName,
            ],
        );
    }

    private function operationId(ServiceBoundaryRequest $request, string $serviceId): ?string
    {
        return $this->lookupCatalogId(
            ConfiguredV2Models::resolve('service_operation_model', WorkflowServiceOperation::class),
            [
                'namespace' => $request->targetNamespace,
                'workflow_service_id' => $serviceId,
                'operation_name' => $request->operationName,
            ],
        );
    }

    /**
     * @param class-string<Model> $modelClass
     * @param array<string, string|null> $where
     */
    private function lookupCatalogId(string $modelClass, array $where): ?string
    {
        /** @var Model|null $row */
        $row = $modelClass::query()->where($where)->first();

        return $row?->getKey();
    }
}
