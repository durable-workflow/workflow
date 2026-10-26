<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Contracts\WorkflowServiceOperationAuthorizer;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Exceptions\ServiceOperationAuthorizationException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceCallDispatch;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

/** Shared by embedded execution and external-worker completion. */
final class WorkflowServiceOperationAuthority
{
    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    public static function options(WorkflowRun $run, array $command): array
    {
        // Caller, call and catalog locks must share the completion transaction.
        // A separately configured model connection cannot provide atomic refusal.
        $connection = $run->getConnection();
        if ($connection !== DB::connection()) {
            throw new ServiceOperationAuthorizationException('service_operation_storage_connection_mismatch');
        }
        foreach ([
            ['service_call_model', WorkflowServiceCall::class],
            ['service_call_dispatch_model', WorkflowServiceCallDispatch::class],
            ['service_endpoint_model', WorkflowServiceEndpoint::class],
            ['service_model', WorkflowService::class],
            ['service_operation_model', WorkflowServiceOperation::class],
        ] as [$role, $model]) {
            if (ConfiguredV2Models::query($role, $model)->getModel()->getConnection() !== $connection) {
                throw new ServiceOperationAuthorizationException('service_operation_storage_connection_mismatch');
            }
        }
        foreach (['principal', 'principal_subject', 'principal_method', 'principal_roles',
            'principal_tenant', 'principal_claims'] as $field) {
            if (array_key_exists($field, $command)) {
                throw new ServiceOperationAuthorizationException();
            }
        }
        if (isset($command['caller_namespace']) && $command['caller_namespace'] !== $run->namespace) {
            throw new ServiceOperationAuthorizationException();
        }

        $principal = app(WorkflowServiceOperationAuthorizer::class)->authorize($run, $command);
        $namespace = $command['namespace'] ?? $run->namespace;
        self::checkReference($run, $command, $namespace, $principal);

        // Preserve explicit null namespaces: null is the embedded namespace,
        // not permission to use ambient configuration or any existing call.
        return [
            'namespace' => $namespace,
            'caller_namespace' => $run->namespace,
            'caller_workflow_instance_id' => $run->workflow_instance_id,
            'caller_workflow_run_id' => $run->id,
            'principal' => $principal,
        ];
    }

    /**
     * Repeated by the control plane on the actual row selected for an effect.
     * @param array<string, ?string> $scope
     */
    public static function authorizeExistingCall(
        WorkflowServiceCall $call,
        ServiceCallPrincipal $principal,
        array $scope,
        ?ServiceBoundaryPolicy $policy = null,
    ): void {
        foreach ($scope + [
            'target_namespace' => $scope['namespace'],
        ] as $field => $expected) {
            if ($call->getAttribute($field) !== $expected) {
                throw new ServiceOperationAuthorizationException('service_call_not_owned');
            }
        }
        self::checkCatalog($call, $scope['namespace'], $principal, $policy);
    }

    /**
     * @param array<string, mixed> $command
     */
    private static function checkReference(
        WorkflowRun $run,
        array $command,
        ?string $namespace,
        ServiceCallPrincipal $principal,
    ): void {
        $callId = $command['service_call_id'] ?? null;
        $key = $command['idempotency_key'] ?? null;
        if ($callId === null && $key === null) {
            return;
        }

        if ($callId !== null) {
            $call = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
                ->whereKey($callId)
                ->lockForUpdate()
                ->first();
        } else {
            $call = ServiceCallIdempotency::find([$namespace, strtolower(trim($command['endpoint_name'])),
                strtolower(trim($command['service_name'])), strtolower(trim($command['operation_name'])), $key]);
        }
        if ($call === null && $callId === null) {
            return;
        }
        if ($call === null) {
            throw new ServiceOperationAuthorizationException('service_call_not_owned');
        }
        self::authorizeExistingCall($call, $principal, [
            'namespace' => $namespace,
            'caller_namespace' => $run->namespace,
            'caller_workflow_instance_id' => $run->workflow_instance_id,
            'caller_workflow_run_id' => $run->id,
            'endpoint_name' => strtolower(trim($command['endpoint_name'])),
            'service_name' => strtolower(trim($command['service_name'])),
            'operation_name' => strtolower(trim($command['operation_name'])),
        ]);
    }

    private static function checkCatalog(
        WorkflowServiceCall $call,
        ?string $namespace,
        ServiceCallPrincipal $principal,
        ?ServiceBoundaryPolicy $policy = null,
    ): void {
        $catalog = [];
        $resolutionFailure = $call->status === ServiceCallStatus::Failed->value
            && $call->outcome === ServiceCallOutcome::RejectedNotFound
            && ($call->outcome_metadata['failure_reason'] ?? null) === 'resolution_failure';
        foreach ([
            [
                'service_endpoint_model', WorkflowServiceEndpoint::class, $call->workflow_service_endpoint_id,
                [
                    'endpoint_name' => $call->endpoint_name,
                ]],
            [
                'service_model', WorkflowService::class, $call->workflow_service_id,
                [
                    'workflow_service_endpoint_id' => $call->workflow_service_endpoint_id,
                    'service_name' => $call->service_name,
                ]],
            [
                'service_operation_model', WorkflowServiceOperation::class, $call->workflow_service_operation_id,
                [
                    'workflow_service_endpoint_id' => $call->workflow_service_endpoint_id,
                    'workflow_service_id' => $call->workflow_service_id,
                    'operation_name' => $call->operation_name,
                ]],
        ] as [$role, $model, $id, $attributes]) {
            // A retained resolution failure may have no catalog component.
            // Known components still have to be this call's scoped objects.
            if ($id === 'unresolved' && $resolutionFailure) {
                continue;
            }
            $row = ConfiguredV2Models::query($role, $model)->where('namespace', $namespace)
                ->lockForUpdate()
                ->find($id);
            if ($row === null) {
                throw new ServiceOperationAuthorizationException('service_call_not_owned');
            }
            foreach ([
                'id' => $id,
                'namespace' => $namespace,
            ] + $attributes as $field => $expected) {
                if ($row->getAttribute($field) !== $expected) {
                    throw new ServiceOperationAuthorizationException('service_call_not_owned');
                }
            }
            $catalog[$role] = $row;
        }
        // A retained resolution failure has no operation to invoke. Its known
        // components and caller scope were still checked above.
        if (count($catalog) !== 3) {
            return;
        }
        $policy ??= app(ServiceBoundaryPolicy::class);
        if (! is_callable([$policy, 'authorizeReplay'])) {
            throw new ServiceOperationAuthorizationException('service_operation_replay_authority_unavailable');
        }
        $operation = $catalog['service_operation_model'];
        $decision = $policy->authorizeReplay(new ServiceBoundaryRequest(
            principal: $principal,
            callerNamespace: $call->caller_namespace,
            targetNamespace: $namespace ?? '',
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
            endpointBoundaryPolicy: $catalog['service_endpoint_model']->boundary_policy ?? [],
            serviceBoundaryPolicy: $catalog['service_model']->boundary_policy ?? [],
            operationBoundaryPolicy: $operation->boundary_policy ?? [],
        ));
        if (! $decision instanceof ServiceBoundaryDecision || $decision->isDenied()) {
            throw new ServiceOperationAuthorizationException(
                $decision instanceof ServiceBoundaryDecision
                    ? ($decision->reason ?? 'service_operation_authority_invalid')
                    : 'service_operation_replay_authority_unavailable',
            );
        }
    }
}
