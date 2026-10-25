<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\WorkflowServiceOperationAuthorizer;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Exceptions\ServiceOperationAuthorizationException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
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
        self::checkReference($run, $command, $namespace);

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
     * @param array<string, mixed> $command
     */
    private static function checkReference(WorkflowRun $run, array $command, ?string $namespace): void
    {
        $callId = $command['service_call_id'] ?? null;
        $key = $command['idempotency_key'] ?? null;
        if ($callId === null && $key === null) {
            return;
        }

        $query = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class);
        if ($callId !== null) {
            $query->whereKey($callId);
        } else {
            $query->where('target_namespace', $namespace)
                ->where('endpoint_name', strtolower(trim($command['endpoint_name'])))
                ->where('service_name', strtolower(trim($command['service_name'])))
                ->where('operation_name', strtolower(trim($command['operation_name'])))
                ->where('idempotency_key', $key)
                ->oldest('created_at')
                ->oldest('id');
        }
        /** @var WorkflowServiceCall|null $call */
        $call = $query->lockForUpdate()
            ->first();
        if ($call === null && $callId === null) {
            return;
        }
        if ($call === null || $call->namespace !== $namespace || $call->target_namespace !== $namespace
            || $call->caller_namespace !== $run->namespace
            || $call->caller_workflow_instance_id !== $run->workflow_instance_id
            || $call->caller_workflow_run_id !== $run->id
            || $call->endpoint_name !== strtolower(trim($command['endpoint_name']))
            || $call->service_name !== strtolower(trim($command['service_name']))
            || $call->operation_name !== strtolower(trim($command['operation_name']))) {
            throw new ServiceOperationAuthorizationException('service_call_not_owned');
        }
        self::checkCatalog($call, $namespace);
    }

    private static function checkCatalog(WorkflowServiceCall $call, ?string $namespace): void
    {
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
        }
    }
}
