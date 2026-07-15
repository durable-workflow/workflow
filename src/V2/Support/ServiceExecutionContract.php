<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\ServiceControlPlane;
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
 * Machine-readable service execution contract consumed by workflow-server
 * discovery endpoints and API-floor checks.
 *
 * The human-readable authority remains the cross-namespace service call
 * architecture documentation. This manifest freezes the service-layer
 * execution vocabulary that callers need at runtime: durable address fields,
 * handler binding adapters, operation modes, lifecycle states, outcomes,
 * failure reasons, and the control-plane methods that operate on service
 * calls.
 *
 * @api Stable class surface consumed by the standalone workflow-server,
 * which re-exports the manifest from `GET /api/cluster/info` under the
 * `service_execution_contract` key.
 */
final class ServiceExecutionContract
{
    public const SCHEMA = 'durable-workflow.v2.service-execution.contract';

    public const VERSION = 1;

    public const AUTHORITY_DOCUMENT = 'https://github.com/durable-workflow/workflow/blob/v2/docs/architecture/workflow-service-calls-architecture.md';

    public const ADDRESS_FIELDS = ['endpoint_name', 'service_name', 'operation_name'];

    public const HANDLER_BINDING_KINDS = [
        'start_workflow',
        'signal_workflow',
        'update_workflow',
        'query_workflow',
        'activity_execution',
        'invocable_http',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'authority_document' => self::AUTHORITY_DOCUMENT,
            'cluster_info_key' => 'service_execution_contract',
            'capability_flag' => 'service_execution',
            'control_plane' => self::controlPlane(),
            'address' => self::address(),
            'durable_records' => self::durableRecords(),
            'handler_binding_kinds' => self::handlerBindingKinds(),
            'resolved_target_binding_kinds' => self::resolvedTargetBindingKinds(),
            'operation_modes' => self::operationModes(),
            'lifecycle_statuses' => self::lifecycleStatuses(),
            'outcomes' => self::outcomes(),
            'failure_reasons' => self::failureReasons(),
            'execution_rules' => self::executionRules(),
            'observability' => self::observability(),
            'evolution' => self::evolution(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function handlerBindingKinds(): array
    {
        return self::HANDLER_BINDING_KINDS;
    }

    /**
     * @return list<string>
     */
    public static function operationModeValues(): array
    {
        return array_map(
            static fn (ServiceCallOperationMode $mode): string => $mode->value,
            ServiceCallOperationMode::cases(),
        );
    }

    /**
     * @return list<string>
     */
    public static function lifecycleStatusValues(): array
    {
        return array_map(
            static fn (ServiceCallStatus $status): string => $status->value,
            ServiceCallStatus::cases(),
        );
    }

    /**
     * @return list<string>
     */
    public static function outcomeValues(): array
    {
        return array_map(
            static fn (ServiceCallOutcome $outcome): string => $outcome->value,
            ServiceCallOutcome::cases(),
        );
    }

    /**
     * @return list<string>
     */
    public static function failureReasonValues(): array
    {
        return array_map(
            static fn (ServiceCallFailureReason $reason): string => $reason->value,
            ServiceCallFailureReason::cases(),
        );
    }

    /**
     * @return list<string>
     */
    public static function resolvedTargetBindingKindValues(): array
    {
        return array_map(
            static fn (ServiceCallBindingKind $kind): string => $kind->value,
            ServiceCallBindingKind::cases(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function controlPlane(): array
    {
        return [
            'interface' => ServiceControlPlane::class,
            'methods' => [
                'execute' => [
                    'input_address_fields' => self::ADDRESS_FIELDS,
                    'returns' => 'service_call_result',
                ],
                'describeCall' => [
                    'input_fields' => ['service_call_id'],
                    'returns' => 'service_call_detail',
                ],
                'cancelCall' => [
                    'input_fields' => ['service_call_id'],
                    'returns' => 'service_call_result',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function address(): array
    {
        return [
            'fields' => self::ADDRESS_FIELDS,
            'canonical_form' => 'endpoint/service/operation',
            'resolution_order' => [
                'workflow_service_endpoints',
                'workflow_services',
                'workflow_service_operations',
            ],
            'caller_obligation' => 'Callers address durable capability names and payloads, not raw namespace, '
                . 'connection, or queue topology.',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function durableRecords(): array
    {
        return [
            'endpoints' => [
                'model' => WorkflowServiceEndpoint::class,
                'table' => 'workflow_service_endpoints',
                'identity_fields' => ['namespace', 'endpoint_name'],
            ],
            'services' => [
                'model' => WorkflowService::class,
                'table' => 'workflow_services',
                'identity_fields' => ['namespace', 'workflow_service_endpoint_id', 'service_name'],
            ],
            'operations' => [
                'model' => WorkflowServiceOperation::class,
                'table' => 'workflow_service_operations',
                'identity_fields' => ['namespace', 'workflow_service_id', 'operation_name'],
                'binding_fields' => ['handler_binding_kind', 'handler_target_reference', 'handler_binding'],
            ],
            'calls' => [
                'model' => WorkflowServiceCall::class,
                'table' => 'workflow_service_calls',
                'identity_fields' => ['id', 'namespace', 'caller_namespace', 'target_namespace'],
                'link_fields' => [
                    'resolved_binding_kind',
                    'resolved_target_reference',
                    'linked_workflow_instance_id',
                    'linked_workflow_run_id',
                    'linked_workflow_update_id',
                ],
                'policy_fields' => [
                    'deadline_policy',
                    'idempotency_policy',
                    'cancellation_policy',
                    'retry_policy',
                    'boundary_policy',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function resolvedTargetBindingKinds(): array
    {
        $kinds = [];

        foreach (ServiceCallBindingKind::cases() as $kind) {
            $kinds[$kind->value] = [
                'terminal_link_reference' => match ($kind) {
                    ServiceCallBindingKind::WorkflowRun => 'workflow_run_id',
                    ServiceCallBindingKind::WorkflowUpdate => 'workflow_update_id',
                    ServiceCallBindingKind::WorkflowSignal => 'workflow_command_id',
                    ServiceCallBindingKind::WorkflowQuery => 'workflow_run_id_or_workflow_instance_id',
                    ServiceCallBindingKind::ActivityExecution => 'activity_execution_id',
                    ServiceCallBindingKind::InvocableCarrierRequest => 'carrier_request_id',
                },
            ];
        }

        return $kinds;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function operationModes(): array
    {
        $modes = [];

        foreach (ServiceCallOperationMode::cases() as $mode) {
            $modes[$mode->value] = [
                'blocks_for_terminal_result' => $mode !== ServiceCallOperationMode::Async,
                'may_return_terminal_result_inline' => $mode !== ServiceCallOperationMode::Async,
                'returns_durable_reference' => $mode !== ServiceCallOperationMode::Sync,
            ];
        }

        return $modes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function lifecycleStatuses(): array
    {
        $statuses = [];

        foreach (ServiceCallStatus::cases() as $status) {
            $statuses[$status->value] = [
                'terminal' => $status->isTerminal(),
                'bucket' => $status->bucket(),
            ];
        }

        return $statuses;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function outcomes(): array
    {
        $outcomes = [];

        foreach (ServiceCallOutcome::cases() as $outcome) {
            $outcomes[$outcome->value] = [
                'terminal' => $outcome->isTerminal(),
                'boundary_rejection' => $outcome->isBoundaryRejection(),
                'bucket' => $outcome->bucket(),
                'category' => $outcome->category(),
            ];
        }

        return $outcomes;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function failureReasons(): array
    {
        return [
            ServiceCallFailureReason::ResolutionFailure->value => [
                'phase' => 'resolution',
                'terminal_status' => ServiceCallStatus::Failed->value,
            ],
            ServiceCallFailureReason::PolicyRejection->value => [
                'phase' => 'boundary_policy',
                'terminal_status' => ServiceCallStatus::Failed->value,
            ],
            ServiceCallFailureReason::Timeout->value => [
                'phase' => 'deadline',
                'terminal_status' => ServiceCallStatus::Failed->value,
            ],
            ServiceCallFailureReason::Cancellation->value => [
                'phase' => 'cancellation',
                'terminal_status' => ServiceCallStatus::Cancelled->value,
            ],
            ServiceCallFailureReason::HandlerFailure->value => [
                'phase' => 'handler',
                'terminal_status' => ServiceCallStatus::Failed->value,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function executionRules(): array
    {
        return [
            'durable_call_id' => 'Every service call created by the control plane has a durable service-call id '
                . 'recorded in workflow_service_calls.',
            'unknown_binding_kind' => 'Unknown handler binding kinds are outside the v2 contract and must fail '
                . 'closed before call acceptance.',
            'resolution_boundary' => 'The service boundary resolves endpoint, service, operation, policies, and '
                . 'handler binding before dispatching into the selected execution lane.',
            'routing_boundary' => 'Service execution selects the durable capability; workflow and activity routing '
                . 'remains owned by the selected execution lane.',
            'transport_logs' => 'Raw transport logs are diagnostic only and are not required for lifecycle, '
                . 'outcome, or observability state.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function observability(): array
    {
        return [
            'source_of_truth' => WorkflowServiceCall::class,
            'guaranteed_fields' => [
                'id',
                'status',
                'outcome',
                'caller_namespace',
                'target_namespace',
                'endpoint_name',
                'service_name',
                'operation_name',
                'caller_workflow_instance_id',
                'caller_workflow_run_id',
                'resolved_binding_kind',
                'resolved_target_reference',
            ],
            'namespace_scoping' => 'Service catalog and service-call lookups resolve out-of-namespace rows as unavailable to the observer.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function evolution(): array
    {
        return [
            'additive_change' => 'New optional fields, new diagnostic fields, or new handler binding kinds are '
                . 'additive only when old consumers can ignore them safely.',
            'breaking_change' => 'Removing or renaming address fields, lifecycle values, outcome values, or '
                . 'guaranteed observability fields requires a major protocol change.',
            'unknown_field_policy' => 'Consumers must ignore unknown additive fields and fail closed on unknown required binding kinds.',
        ];
    }
}
