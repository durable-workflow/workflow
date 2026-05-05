<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Models\WorkflowServiceCall;

/**
 * Typed list/detail projection of a {@see WorkflowServiceCall}.
 *
 * The detail shape exposes link references (linked run id, linked update id)
 * but only includes inline previews of the linked objects when they sit in the
 * same namespace as the call's owning scope. Foreign-namespace links remain
 * plain ids - Waterline will surface them as opaque references rather than
 * letting an operator browse into another namespace's data.
 */
final class ServiceCallView
{
    /**
     * @return array<string, mixed>
     */
    public static function listItem(WorkflowServiceCall $call): array
    {
        $status = ServiceCallStatus::tryFrom((string) $call->status);
        $outcome = self::outcome($call);

        return [
            'id' => $call->id,
            'workflow_service_endpoint_id' => $call->workflow_service_endpoint_id,
            'workflow_service_id' => $call->workflow_service_id,
            'workflow_service_operation_id' => $call->workflow_service_operation_id,
            'namespace' => $call->namespace,
            'endpoint_name' => $call->endpoint_name,
            'service_name' => $call->service_name,
            'operation_name' => $call->operation_name,

            'caller_namespace' => $call->caller_namespace,
            'caller_workflow_instance_id' => $call->caller_workflow_instance_id,
            'caller_workflow_run_id' => $call->caller_workflow_run_id,
            'caller_principal_subject' => $call->caller_principal_subject,
            'caller_principal_method' => $call->caller_principal_method,
            'caller_principal_roles' => self::array($call->caller_principal_roles),
            'caller_principal_tenant' => $call->caller_principal_tenant,

            'target_namespace' => $call->target_namespace,
            'linked_workflow_instance_id' => $call->linked_workflow_instance_id,
            'linked_workflow_run_id' => $call->linked_workflow_run_id,
            'linked_workflow_update_id' => $call->linked_workflow_update_id,

            'status' => $call->status,
            'status_bucket' => $status?->bucket(),
            'outcome' => $outcome?->value,
            'outcome_bucket' => $outcome?->bucket(),
            'is_terminal' => $status?->isTerminal() ?? false,
            'is_policy_outcome' => $outcome?->isBoundaryRejection() ?? false,

            'operation_mode' => $call->operation_mode,
            'resolved_binding_kind' => $call->resolved_binding_kind,
            'resolved_target_reference' => $call->resolved_target_reference,

            'idempotency_key' => $call->idempotency_key,
            'failure_message' => $call->failure_message,

            'accepted_at' => self::iso($call->accepted_at),
            'started_at' => self::iso($call->started_at),
            'completed_at' => self::iso($call->completed_at),
            'failed_at' => self::iso($call->failed_at),
            'cancelled_at' => self::iso($call->cancelled_at),
            'created_at' => self::iso($call->created_at),
            'updated_at' => self::iso($call->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(WorkflowServiceCall $call, ?string $observerNamespace = null): array
    {
        $base = self::listItem($call);

        $base['payload_codec'] = $call->payload_codec;
        $base['input_payload_reference'] = $call->input_payload_reference;
        $base['output_payload_reference'] = $call->output_payload_reference;
        $base['failure_payload_reference'] = $call->failure_payload_reference;
        $base['deadline_policy'] = self::array($call->deadline_policy);
        $base['idempotency_policy'] = self::array($call->idempotency_policy);
        $base['cancellation_policy'] = self::array($call->cancellation_policy);
        $base['retry_policy'] = self::array($call->retry_policy);
        $base['boundary_policy'] = self::array($call->boundary_policy);
        $base['metadata'] = self::array($call->metadata);
        $base['caller_principal_claims'] = self::array($call->caller_principal_claims);

        $base['caller_link'] = self::callerLinkRef($call, $observerNamespace);
        $base['linked_run_ref'] = self::linkedRunRef($call, $observerNamespace);
        $base['linked_update_ref'] = self::linkedUpdateRef($call, $observerNamespace);

        return $base;
    }

    /**
     * Build an opaque reference describing the caller side of the service
     * call. Returns null when no caller is recorded.
     *
     * The ref always carries the caller namespace so an operator can decide
     * whether to follow the link or treat it as foreign-namespace data.
     *
     * @return array<string, mixed>|null
     */
    private static function callerLinkRef(WorkflowServiceCall $call, ?string $observerNamespace): ?array
    {
        if ($call->caller_workflow_instance_id === null && $call->caller_workflow_run_id === null) {
            return null;
        }

        return [
            'namespace' => $call->caller_namespace,
            'workflow_instance_id' => $call->caller_workflow_instance_id,
            'workflow_run_id' => $call->caller_workflow_run_id,
            'in_observer_namespace' => self::matchesObserver($call->caller_namespace, $observerNamespace),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function linkedRunRef(WorkflowServiceCall $call, ?string $observerNamespace): ?array
    {
        if ($call->linked_workflow_instance_id === null && $call->linked_workflow_run_id === null) {
            return null;
        }

        return [
            'namespace' => $call->target_namespace,
            'workflow_instance_id' => $call->linked_workflow_instance_id,
            'workflow_run_id' => $call->linked_workflow_run_id,
            'in_observer_namespace' => self::matchesObserver($call->target_namespace, $observerNamespace),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function linkedUpdateRef(WorkflowServiceCall $call, ?string $observerNamespace): ?array
    {
        if ($call->linked_workflow_update_id === null) {
            return null;
        }

        return [
            'namespace' => $call->target_namespace,
            'workflow_instance_id' => $call->linked_workflow_instance_id,
            'workflow_update_id' => $call->linked_workflow_update_id,
            'workflow_run_id' => $call->linked_workflow_run_id,
            'in_observer_namespace' => self::matchesObserver($call->target_namespace, $observerNamespace),
        ];
    }

    private static function matchesObserver(?string $linkNamespace, ?string $observerNamespace): bool
    {
        if ($observerNamespace === null) {
            return true;
        }

        return $linkNamespace === $observerNamespace;
    }

    private static function outcome(WorkflowServiceCall $call): ?ServiceCallOutcome
    {
        if ($call->outcome instanceof ServiceCallOutcome) {
            return $call->outcome;
        }

        return is_string($call->outcome) ? ServiceCallOutcome::tryFrom($call->outcome) : null;
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function iso(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('c') : null;
    }
}
